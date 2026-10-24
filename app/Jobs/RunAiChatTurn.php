<?php

namespace App\Jobs;

use App\Models\AiChatMessage;
use App\Services\AiAssistant;
use App\Support\AgentStream;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * One answer of the panel's AI assistant: runs the chat's agent with the user's message and
 * writes the answer into its message as it streams (the chat page polls it), with the panel
 * tools it used. A stop request (stopKey) ends the agent.
 */
class RunAiChatTurn implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1800;

    public int $tries = 1;

    /**
     * @param  list<array<string, mixed>>  $attachments  Pasted images, GitHub repositories, website logs.
     */
    public function __construct(public int $answerId, public string $prompt, public array $attachments = []) {}

    public static function stopKey(int $answerId): string
    {
        return 'ai-chat-stop:'.$answerId;
    }

    public function handle(AiAssistant $assistant): void
    {
        $answer = AiChatMessage::query()->with('chat')->find($this->answerId);

        if (! $answer || $answer->status !== AiChatMessage::RUNNING) {
            return;
        }

        $chat = $answer->chat;
        $text = '';
        $streamed = false;
        $raw = '';
        $gap = false;
        $tools = [];
        $error = null;
        $savedAt = 0.0;
        $stopped = false;

        $save = function (bool $force = false) use (&$savedAt, &$text, &$tools, $answer): void {
            if (! $force && microtime(true) - $savedAt < 0.4) {
                return;
            }

            $savedAt = microtime(true);
            $answer->forceFill(['text' => $text, 'tools' => $tools])->save();
        };

        try {
            $prompt = $this->attachments === [] ? $this->prompt : $assistant->withAttachments($chat, $this->attachments, $this->prompt);
            $process = new Process($assistant->command($chat, $prompt), null, null, null, $this->timeout);
            $process->start();
            $buffer = '';

            foreach ($process as $type => $data) {
                if ($type !== Process::OUT) {
                    $error = trim(($error ?? '')."\n".$data);

                    continue;
                }

                $buffer .= $data;

                while (($newline = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $newline);
                    $buffer = substr($buffer, $newline + 1);

                    foreach (AgentStream::parse($chat->agent, trim($line)) as $event) {
                        switch ($event[0]) {
                            case 'session':
                                if ($chat->session_id !== $event[1]) {
                                    $chat->forceFill(['session_id' => $event[1]])->save();
                                }
                                break;
                            case 'text':
                                // Pieces add up; a whole text (no pieces seen) replaces it.
                                if ($event[2]) {
                                    // Text after a tool call is a new paragraph.
                                    if ($gap) {
                                        $text .= "\n\n";
                                        $gap = false;
                                    }

                                    // Cursor ends a run of pieces with the whole text so far: only what is new is added.
                                    if ($raw !== '' && str_starts_with($event[1], $raw)) {
                                        $text .= substr($event[1], strlen($raw));
                                        $raw = $event[1];
                                    } else {
                                        $text .= $event[1];
                                        $raw .= $event[1];
                                    }

                                    $streamed = true;
                                } elseif (! $streamed) {
                                    $text = $event[1];
                                }
                                break;
                            case 'block':
                                if (! $streamed) {
                                    $text = trim($text."\n\n".$event[1]);
                                }
                                break;
                            case 'tool':
                                // Claude's own tool loader is not a panel tool.
                                $gap = $text !== '';

                                if (! in_array($event[1], ['ToolSearch', 'GetMcpTools'], true)) {
                                    $tools[] = ['name' => AgentStream::toolName($event[1]), 'input' => array_slice($event[2], 0, 8, true)];
                                }
                                break;
                            case 'result':
                                if ($event[2]) {
                                    $error = $event[1];
                                } elseif ($text === '') {
                                    $text = $event[1];
                                }
                                break;
                        }
                    }

                    $save();
                }

                if (Cache::pull(self::stopKey($answer->id))) {
                    $process->stop(3);
                    $stopped = true;

                    break;
                }
            }

            $process->wait();
            $failed = ! $stopped && ! $process->isSuccessful() && $text === '';
        } catch (Throwable $exception) {
            $failed = true;
            $error = $exception->getMessage();
        }

        // Claude Code reports a used-up session as its answer, not as an error.
        if (! $failed && ! $stopped && mb_strlen($text) < 300 && preg_match(AiAssistant::LIMIT, $text) === 1) {
            $failed = true;
            $error = $text;
            $text = '';
        }

        if ($failed && $error !== null && preg_match(AiAssistant::LIMIT, $error) === 1) {
            $error = trim($error)."\n\nThis account reached its limit: pick another agent below and send again, it continues the chat.";
        }

        $answer->forceFill([
            'text' => $failed ? trim($text."\n\n".(trim((string) $error) ?: 'The agent stopped without an answer.')) : $text,
            'tools' => $tools,
            'status' => $stopped ? AiChatMessage::STOPPED : ($failed ? AiChatMessage::ERROR : AiChatMessage::DONE),
        ])->save();
        $chat->touch();
    }
}
