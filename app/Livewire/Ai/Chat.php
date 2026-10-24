<?php

namespace App\Livewire\Ai;

use App\Jobs\RunAiChatTurn;
use App\Livewire\Concerns\FollowsTasks;
use App\Livewire\PanelComponent;
use App\Models\AiChat;
use App\Models\AiChatMessage;
use App\Models\GithubApp;
use App\Models\Website;
use App\Services\AiAssistant;
use App\Services\GithubAppClient;
use App\Services\SiteLogs;
use App\Services\TerminalSessions;
use App\Support\AgentStream;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Url;
use Livewire\WithFileUploads;
use Throwable;

/**
 * The panel's AI assistant: chat with Claude Code, Cursor Agent, or Copilot CLI, which manage the
 * server through the panel's MCP tools (listed as Skills, and as "/" shortcuts in the message box).
 */
class Chat extends PanelComponent
{
    use FollowsTasks;
    use WithFileUploads;

    #[Url(as: 'chat', except: null)]
    public ?int $chatId = null;

    public string $message = '';

    public string $agent = 'claude';

    public string $model = 'default';

    /** Claude's effort (--effort), '' for its own default. */
    public string $effort = '';

    /** @var array<string, array{installed: bool, signedIn: bool, who: ?string}> */
    public array $statuses = [];

    /** An image pasted or picked, moved into $attached as soon as it arrives. */
    public $pasted = null;

    /** @var list<array<string, mixed>> Images, GitHub repositories, and logs for the next message. */
    public array $attached = [];

    /** The attach picker that is open: 'repo', 'log', or null. */
    public ?string $picker = null;

    public string $pickerSearch = '';

    /** The website whose logs the log picker lists. */
    public ?int $logWebsite = null;

    public function updatedPasted(): void
    {
        $this->validate(['pasted' => ['image', 'max:8192']], ['pasted.image' => 'Only images can be pasted here.', 'pasted.max' => 'Images up to 8 MB.']);

        $path = $this->pasted->store('ai-chat/attachments', 'local');
        $images = collect($this->attached)->where('kind', 'image')->count();
        $this->attached[] = ['kind' => 'image', 'path' => $path, 'label' => 'Image #'.($images + 1)];
        $this->pasted = null;
    }

    public function openPicker(string $kind): void
    {
        $this->picker = in_array($kind, ['repo', 'log'], true) ? $kind : null;
        $this->pickerSearch = '';
        $this->logWebsite = null;
    }

    public function closePicker(): void
    {
        $this->picker = null;
    }

    public function addRepository(int $appId, string $name, string $branch): void
    {
        if (collect($this->attached)->contains(fn (array $item) => ($item['kind'] ?? null) === 'repo' && $item['name'] === $name)) {
            $this->picker = null;

            return;
        }

        $this->attached[] = ['kind' => 'repo', 'app' => $appId, 'name' => $name, 'branch' => $branch, 'label' => $name];
        $this->picker = null;
    }

    public function addLog(string $source, SiteLogs $logs): void
    {
        $website = Website::query()->find($this->logWebsite);

        if ($website === null) {
            return;
        }

        $label = collect($logs->sources($website))->firstWhere('key', $source)['label'] ?? $source;

        try {
            $text = $logs->tail($website, $source, 150);
        } catch (Throwable $exception) {
            $this->toast('Could not read that log: '.$exception->getMessage(), 'error');

            return;
        }

        $this->attached[] = ['kind' => 'log', 'label' => $website->name.' · '.$label, 'text' => $text !== '' ? $text : '(empty)'];
        $this->picker = null;
    }

    public function removeAttachment(int $index): void
    {
        unset($this->attached[$index]);
        $this->attached = array_values($this->attached);
    }

    /**
     * Repositories of the connected GitHub Apps, for the import picker (cached a few minutes).
     *
     * @return list<array{app: int, appName: string, name: string, branch: string}>
     */
    private function repositoryOptions(): array
    {
        $options = [];

        foreach (GithubApp::query()->get() as $app) {
            if (! $app->isConnected()) {
                continue;
            }

            try {
                $repositories = Cache::remember('ai-chat:repos:'.$app->id, 300, fn () => app(GithubAppClient::class)->repositories($app));
            } catch (Throwable) {
                continue;
            }

            foreach ($repositories as $repository) {
                $options[] = ['app' => $app->id, 'appName' => (string) $app->name, 'name' => (string) $repository['name'], 'branch' => (string) $repository['branch']];
            }
        }

        $search = strtolower(trim($this->pickerSearch));

        return array_values(array_filter($options, fn (array $option) => $search === '' || str_contains(strtolower($option['name']), $search)));
    }

    public function mount(): void
    {
        $chat = $this->chatId ? AiChat::query()->find($this->chatId) : null;

        if ($chat) {
            $this->agent = $chat->agent;
            $this->model = (string) ($chat->model ?? array_key_first(AiAssistant::AGENTS[$chat->agent]['models']));
            $this->effort = (string) $chat->effort;
        } else {
            $this->chatId = null;
        }
    }

    /** Asked once the page shows: each agent's CLI and sign-in, as the chat's user. */
    public function loadStatuses(AiAssistant $assistant): void
    {
        try {
            $assistant->prepare(Auth::user());
        } catch (Throwable $exception) {
            $this->toast('The AI chat could not be set up: '.$exception->getMessage(), 'error');
        }

        foreach (array_keys(AiAssistant::AGENTS) as $agent) {
            $this->statuses[$agent] = $assistant->status($agent);
        }
    }

    public function updatedAgent(): void
    {
        $this->agent = isset(AiAssistant::AGENTS[$this->agent]) ? $this->agent : 'claude';
        $this->model = (string) array_key_first(AiAssistant::AGENTS[$this->agent]['models']);
    }

    public function newChat(): void
    {
        $this->chatId = null;
        $this->message = '';
    }

    public function openChat(int $id): void
    {
        $chat = AiChat::query()->findOrFail($id);
        $this->chatId = $chat->id;
        $this->agent = $chat->agent;
        $this->model = (string) ($chat->model ?? array_key_first(AiAssistant::AGENTS[$chat->agent]['models']));
        $this->effort = (string) $chat->effort;
    }

    public function deleteChat(int $id): void
    {
        AiChat::query()->whereKey($id)->delete();

        if ($this->chatId === $id) {
            $this->newChat();
        }
    }

    public function send(): void
    {
        $text = trim($this->message);
        $attachments = $this->attached;

        if ($text === '' && $attachments !== []) {
            $text = 'Look at what I attached.';
        }

        if ($text === '' || ! isset(AiAssistant::AGENTS[$this->agent], AiAssistant::AGENTS[$this->agent]['models'][$this->model])) {
            return;
        }

        $chat = $this->chatId ? AiChat::query()->find($this->chatId) : null;

        if ($chat?->isBusy()) {
            $this->toast('The agent is still answering. Stop it or wait for it.', 'warning');

            return;
        }

        $chat ??= AiChat::query()->create([
            'user_id' => Auth::id(),
            'title' => Str::limit(preg_replace('/\s+/', ' ', $text), 60),
            'agent' => $this->agent,
            'model' => $this->model,
        ]);

        // Another agent continues the conversation from what was said (its own session starts fresh).
        if ($chat->agent !== $this->agent) {
            $text = $this->recap($chat)."\n\n".$text;
            $chat->forceFill(['session_id' => null])->save();
        }

        $effort = isset(AiAssistant::AGENTS[$this->agent]['efforts'][$this->effort]) ? $this->effort : null;
        $chat->forceFill(['agent' => $this->agent, 'model' => $this->model, 'effort' => $effort])->save();
        $chat->messages()->create([
            'role' => 'user',
            'text' => trim($this->message),
            // Logs are kept short on the message; the agent gets them whole.
            'attachments' => array_map(fn (array $item) => isset($item['text']) ? ['text' => Str::limit($item['text'], 4000)] + $item : $item, $attachments) ?: null,
            'agent' => $this->agent,
            'status' => AiChatMessage::DONE,
        ]);
        $answer = $chat->messages()->create(['role' => 'assistant', 'text' => '', 'agent' => $this->agent, 'status' => AiChatMessage::RUNNING]);

        RunAiChatTurn::dispatch($answer->id, $text, $attachments);

        $this->chatId = $chat->id;
        $this->message = '';
        $this->attached = [];
    }

    public function stop(): void
    {
        $running = AiChatMessage::query()->where('ai_chat_id', $this->chatId)->where('status', AiChatMessage::RUNNING)->latest('id')->first();

        if ($running) {
            Cache::put(RunAiChatTurn::stopKey($running->id), true, 600);
        }
    }

    /** The agent whose sign-in dialog is open. */
    public ?string $login = null;

    public ?string $loginSession = null;

    public int $loginOffset = 0;

    /** What the sign-in printed so far. */
    public string $loginOutput = '';

    public ?int $loginExit = null;

    /** The code the sign-in page shows, to type back (Claude). */
    public string $loginAnswer = '';

    public bool $loginAnswered = false;

    public function startLogin(string $agent, AiAssistant $assistant, TerminalSessions $sessions): void
    {
        if (! isset(AiAssistant::AGENTS[$agent])) {
            return;
        }

        $this->closeLogin($sessions);
        $this->login = $agent;

        try {
            $this->loginSession = $sessions->startArguments($assistant->loginCommand($agent), AiAssistant::AGENTS[$agent]['product'].' sign-in', Auth::id());
        } catch (Throwable $exception) {
            $this->loginOutput = $exception->getMessage();
            $this->loginExit = 1;
        }
    }

    /** Polled while the dialog is open: the sign-in's new output, and its end. */
    public function pollLogin(AiAssistant $assistant, TerminalSessions $sessions): void
    {
        if ($this->loginSession === null || $this->loginExit !== null) {
            return;
        }

        $read = $sessions->read($this->loginSession, $this->loginOffset, Auth::id());

        if ($read === null) {
            $this->loginExit = 1;

            return;
        }

        $this->loginOutput = mb_substr($this->loginOutput.base64_decode($read['data']), -20000);
        $this->loginOffset = $read['offset'];

        if ($read['done']) {
            $this->loginExit = (int) $read['exit'];
            $this->loadStatuses($assistant);
        }
    }

    public function answerLogin(TerminalSessions $sessions): void
    {
        $answer = trim($this->loginAnswer);

        if ($this->loginSession === null || $answer === '') {
            return;
        }

        $sessions->write($this->loginSession, $answer."\r", Auth::id());
        $this->loginAnswer = '';
        $this->loginAnswered = true;
    }

    public function closeLogin(TerminalSessions $sessions): void
    {
        if ($this->loginSession !== null && $this->loginExit === null) {
            $sessions->stop($this->loginSession, Auth::id());
        }

        $this->reset('login', 'loginSession', 'loginOffset', 'loginOutput', 'loginExit', 'loginAnswer', 'loginAnswered');
    }

    public function install(string $agent): void
    {
        if (! isset(AiAssistant::AGENTS[$agent])) {
            return;
        }

        $this->runInBackground('ai.install', 'ai-chat', 'Installing '.AiAssistant::AGENTS[$agent]['product'], ['agent' => $agent, 'user_id' => Auth::id()]);
    }

    /** The conversation so far, for an agent that takes over. */
    private function recap(AiChat $chat): string
    {
        $lines = $chat->messages->take(-12)->map(fn (AiChatMessage $message) => ($message->role === 'user' ? 'User: ' : 'Assistant: ').Str::limit($message->text, 600))->implode("\n");

        return "This chat continues a conversation another assistant started. What was said so far:\n".$lines."\n\n---";
    }

    public function render(AiAssistant $assistant)
    {
        $chat = $this->chatId ? AiChat::query()->with('messages')->find($this->chatId) : null;

        return view('livewire.ai.chat', [
            'chat' => $chat,
            'chats' => AiChat::query()->latest('updated_at')->limit(50)->get(['id', 'title', 'agent', 'updated_at']),
            'busy' => $chat?->messages->contains('status', AiChatMessage::RUNNING) ?? false,
            'agents' => AiAssistant::AGENTS,
            'skills' => $assistant->skills(),
            'repositories' => $this->picker === 'repo' ? $this->repositoryOptions() : [],
            'logWebsites' => $this->picker === 'log' ? Website::query()->orderBy('name')->get(['id', 'name']) : collect(),
            'loginState' => $this->login ? AgentStream::login($this->loginOutput) : null,
            'logSources' => $this->picker === 'log' && $this->logWebsite ? app(SiteLogs::class)->sources(Website::query()->findOrFail($this->logWebsite)) : [],
        ])->layout('layouts.app', ['mobile' => true])->title('AI chat');
    }
}
