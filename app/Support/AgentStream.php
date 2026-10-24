<?php

namespace App\Support;

/**
 * The JSON lines Claude Code, Cursor Agent, and Copilot CLI print in print mode, as chat events
 * (the PHP twin of resources/code-server/laravelify-ai/lib/agents.js parse()):
 * ['session', id] | ['text', text, isDelta] | ['block', text] | ['tool', name, input] | ['result', text, isError].
 */
class AgentStream
{
    /**
     * @return list<array<int, mixed>>
     */
    public static function parse(string $agent, string $line): array
    {
        $event = json_decode($line, true);

        if (! is_array($event)) {
            return [];
        }

        $out = [];
        $session = $event['session_id'] ?? $event['sessionId'] ?? ($event['session']['id'] ?? null);

        if (is_string($session) && $session !== '') {
            $out[] = ['session', $session];
        }

        $type = (string) ($event['type'] ?? '');

        // Claude: partial text arrives as stream events, whole blocks as "assistant" messages.
        if ($type === 'stream_event') {
            if (($event['event']['delta']['type'] ?? null) === 'text_delta') {
                $out[] = ['text', (string) $event['event']['delta']['text'], true];
            }

            return $out;
        }

        if ($type === 'assistant' && is_array($event['message']['content'] ?? null)) {
            foreach ($event['message']['content'] as $block) {
                if (($block['type'] ?? null) === 'text' && ($block['text'] ?? '') !== '') {
                    // Cursor: pieces carry a timestamp; the last one (none) is the whole text.
                    $out[] = match ($agent) {
                        'cursor' => ['text', (string) $block['text'], isset($event['timestamp_ms'])],
                        'claude' => ['block', (string) $block['text']],
                        default => ['text', (string) $block['text'], false],
                    };
                } elseif (($block['type'] ?? null) === 'tool_use') {
                    $out[] = ['tool', (string) ($block['name'] ?? 'tool'), (array) ($block['input'] ?? [])];
                }
            }

            return $out;
        }

        if ($type === 'tool_call' && ($event['subtype'] ?? null) === 'started' && is_array($event['tool_call'] ?? null)) {
            $name = (string) array_key_first($event['tool_call']);
            $args = (array) ($event['tool_call'][$name]['args'] ?? []);

            // Cursor: "readToolCall" is its Read; a panel tool comes as "mcpToolCall" naming the tool.
            if ($name === 'mcpToolCall') {
                $name = (string) ($args['toolName'] ?? $args['name'] ?? 'mcp');
                $args = (array) ($args['args'] ?? $args['arguments'] ?? []);
            } elseif (str_ends_with($name, 'ToolCall')) {
                $name = ucfirst(substr($name, 0, -8));
            }

            $out[] = ['tool', $name, $args];

            return $out;
        }

        if ($type === 'result') {
            $out[] = ['result', (string) ($event['result'] ?? ''), (bool) ($event['is_error'] ?? false) || ($event['subtype'] ?? null) === 'error'];

            return $out;
        }

        if ($agent === 'copilot') {
            $kind = (string) ($event['type'] ?? $event['event'] ?? '');
            $text = $event['delta'] ?? $event['text'] ?? $event['content'] ?? ($event['message']['content'] ?? $event['data']['content'] ?? $event['data']['delta'] ?? null);

            if (preg_match('/tool/i', $kind) && preg_match('/start|call|request|execut/i', $kind)) {
                $out[] = ['tool', (string) ($event['toolName'] ?? $event['name'] ?? $event['data']['toolName'] ?? 'tool'), (array) ($event['arguments'] ?? $event['data']['arguments'] ?? [])];
            } elseif (preg_match('/error/i', $kind)) {
                $out[] = ['result', (string) ($event['message'] ?? $event['error'] ?? $text ?? 'Copilot stopped with an error.'), true];
            } elseif (is_string($text) && $text !== '') {
                $out[] = ['text', $text, preg_match('/delta|chunk|partial/i', $kind) === 1];
            }
        }

        return $out;
    }

    /**
     * What a sign-in printed so far (the PHP twin of laravelify-ai/lib/login.js): the sign-in link
     * (the longest one), a device code to type on it, and whether it asks for a code back.
     *
     * @return array{text: string, url: ?string, code: ?string, needsInput: bool}
     */
    public static function login(string $output): array
    {
        $text = preg_replace(['/\x1b\]8;[^\x07\x1b]*(\x07|\x1b\\\\)/', '/\]8;;[^\s\]]*/', '/\x1b\[[0-9;?]*[A-Za-z]/', '/\x1b[()][A-Z0-9]/', '/\r/'], '', $output) ?? $output;
        preg_match_all('/https:\/\/[^\s"\'<>\x07]+/', $text, $links);
        $urls = array_map(fn (string $url) => rtrim($url, ').,'), $links[0]);
        usort($urls, fn (string $a, string $b) => strlen($b) <=> strlen($a));
        preg_match('/(?:code|enter)[^A-Z0-9\n]{0,40}\b([A-Z0-9]{4}-[A-Z0-9]{4})\b/i', $text, $code);

        return [
            'text' => $text,
            'url' => $urls[0] ?? null,
            'code' => $code[1] ?? null,
            'needsInput' => preg_match('/paste (the )?(code|token)|paste code here/i', $text) === 1,
        ];
    }

    /** "deploy-website", from "mcp__laravelify__deploy-website" or "laravelify-deploy-website". */
    public static function toolName(string $name): string
    {
        return preg_replace('/^(mcp__laravelify__|laravelify[-_:.]+)/', '', $name) ?? $name;
    }
}
