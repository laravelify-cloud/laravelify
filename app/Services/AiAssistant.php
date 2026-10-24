<?php

namespace App\Services;

use App\Mcp\Servers\LaravelifyServer;
use App\Models\AiChat;
use App\Models\GithubApp;
use App\Models\McpToken;
use App\Models\Setting;
use App\Models\User;
use App\Support\GitInput;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use ReflectionClass;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * The panel's AI assistant: Claude Code, Cursor Agent, or GitHub Copilot CLI (the same agents and
 * the same shared sign-in as VS Code), run as the AI chat's own user (lfy-ws--panelaichat, no
 * website's files). It manages the server only through the panel's MCP tools, with an MCP token
 * made for it the first time; each agent is allowed those tools and nothing else (no shell).
 */
class AiAssistant
{
    public const AGENTS = [
        'claude' => [
            'label' => 'Claude',
            'product' => 'Claude Code',
            'binary' => 'claude',
            'install' => 'NPM_CONFIG_PREFIX="$HOME/.local" npm install -g @anthropic-ai/claude-code',
            'models' => ['default' => 'Default', 'sonnet' => 'Sonnet', 'opus' => 'Opus', 'haiku' => 'Haiku'],
            'hints' => ['default' => 'The usual model of your plan', 'sonnet' => 'Fast and smart, for everyday work', 'opus' => 'Most capable, for hard problems', 'haiku' => 'Fastest, for quick answers'],
            // claude --effort: how long it thinks before answering.
            'efforts' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'xhigh' => 'Extra high', 'max' => 'Max'],
        ],
        'cursor' => [
            'label' => 'Cursor',
            'product' => 'Cursor Agent',
            'binary' => 'cursor-agent',
            'install' => 'curl -fsSL https://cursor.com/install | bash',
            'models' => ['auto' => 'Auto', 'composer-2.5-fast' => 'Composer 2.5', 'claude-opus-5-5-medium' => 'Claude Opus 5.5', 'gpt-5.6-sol-medium' => 'GPT-5.6', 'gemini-4-argon-medium' => 'Gemini 4'],
        ],
        'copilot' => [
            'label' => 'Copilot',
            'product' => 'GitHub Copilot CLI',
            'binary' => 'copilot',
            'install' => 'NPM_CONFIG_PREFIX="$HOME/.local" npm install -g @github/copilot',
            'models' => ['auto' => 'Auto', 'claude-sonnet-4.5' => 'Claude Sonnet 4.5', 'gpt-5' => 'GPT-5', 'gemini-2.5-pro' => 'Gemini 2.5 Pro'],
        ],
    ];

    /** What every agent is told first: who it is, what it may do, and to ask before destroying. */
    public const INSTRUCTIONS = 'You are the AI assistant of this Laravelify panel, a deployment panel on the user\'s own server. '
        .'Work only through the "laravelify" MCP tools (websites, deploys, logs, domains, environment, nginx, backups, files). '
        .'Before anything that deletes or empties (a website, a group, a file, a database import with reset), say exactly what will go and ask the user to confirm in the chat. '
        .'Answer briefly, in the user\'s language, and say what you changed.';

    /** Messages that mean the agent's account ran out: the chat offers another agent. */
    public const LIMIT = '/(usage|rate|session|weekly|monthly)[ -]?limit|limit (reached|exceeded|hit)|quota|out of (credits|requests)|too many requests|\b429\b|credits? (balance|exhausted)/i';

    private const TOKEN_SETTING = 'ai_chat.mcp_token_id';

    public function __construct(private HostWriter $host, private SiteFiles $files, private SiteUser $sites) {}

    public function user(): SiteUser
    {
        return $this->sites->forAiChat();
    }

    public function home(): string
    {
        return $this->user()->home();
    }

    /**
     * The chat's user and home, the shared AI sign-in, and the MCP connection (a token of its own,
     * made once and written for each agent). Cheap to call again.
     */
    public function prepare(User $user): void
    {
        $this->host->aiChatUser();

        try {
            $this->host->syncAgentSignIns();
        } catch (Throwable) {
            // Without it the agents only ask to sign in.
        }

        $this->ensureMcp($user);
    }

    /**
     * The panel's MCP token for the chat: made the first time (and again when it was deleted in
     * Settings → MCP), then written into each agent's MCP configuration in the chat's home.
     */
    public function ensureMcp(User $user): void
    {
        $id = (int) Setting::getValue(self::TOKEN_SETTING);

        if ($id > 0 && McpToken::query()->whereKey($id)->exists() && $this->files->exec(['test', '-s', $this->home().'/chat/.cursor/cli.json'], null, null, $this->user())->ok) {
            return;
        }

        $issued = McpToken::issue($user, 'Panel AI chat');
        Setting::putValue(self::TOKEN_SETTING, (string) $issued['token']->id);

        $url = route('mcp');
        $headers = ['Authorization' => 'Bearer '.$issued['plain']];
        $configs = [
            '.laravelify/mcp.json' => ['mcpServers' => ['laravelify' => ['type' => 'http', 'url' => $url, 'headers' => $headers]]],
            '.cursor/mcp.json' => ['mcpServers' => ['laravelify' => ['url' => $url, 'headers' => $headers]]],
            // Cursor asks before each tool in print mode and takes no answer: the panel tools and reading are allowed, its shell and edits are not.
            'chat/.cursor/cli.json' => ['permissions' => ['allow' => ['Mcp(laravelify:*)', 'Read(**)'], 'deny' => ['Shell(*)', 'Write(**)']]],
            '.copilot/mcp-config.json' => ['mcpServers' => ['laravelify' => ['type' => 'http', 'url' => $url, 'headers' => $headers, 'tools' => ['*']]]],
        ];

        foreach ($configs as $file => $config) {
            $path = $this->home().'/'.$file;
            $this->files->exec(
                ['/bin/sh', '-c', 'umask 077 && mkdir -p "$(dirname "$1")" && cat > "$1"', 'mcp', $path],
                json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                null,
                $this->user(),
            );
        }
    }

    /**
     * Whether an agent's CLI is installed for the chat's user, and signed in.
     *
     * @return array{installed: bool, signedIn: bool, who: ?string}
     */
    public function status(string $agent): array
    {
        $binary = $this->binary($agent);

        if ($binary === null) {
            return ['installed' => false, 'signedIn' => false, 'who' => null];
        }

        if ($agent === 'copilot') {
            $config = $this->read('.copilot/config.json') ?? [];
            $who = $config['last_logged_in_user'] ?? $config['lastLoggedInUser'] ?? ($config['logged_in_users'][0] ?? null);

            return ['installed' => true, 'signedIn' => $who !== null, 'who' => is_array($who) ? ($who['login'] ?? null) : $who];
        }

        $output = $this->shell("'{$binary}' ".($agent === 'claude' ? 'auth status' : 'status'), 25);

        if ($agent === 'claude') {
            $data = json_decode((string) substr($output, (int) strpos($output, '{')), true);

            if (is_array($data)) {
                return ['installed' => true, 'signedIn' => (bool) ($data['loggedIn'] ?? false), 'who' => $data['email'] ?? ($data['account']['email'] ?? null)];
            }
        }

        $signedIn = preg_match('/logged in/i', $output) === 1 && preg_match('/not logged/i', $output) !== 1;
        preg_match('/logged in as\s+(\S+)/i', $output, $who);

        return ['installed' => true, 'signedIn' => $signedIn, 'who' => $who[1] ?? null];
    }

    /** Install an agent's CLI for the chat's user (a few minutes for Claude and Copilot). */
    public function install(string $agent): string
    {
        return $this->shell(self::AGENTS[$agent]['install'], 900);
    }

    /**
     * The command for one turn of a chat, as the chat's user.
     *
     * @return list<string>
     */
    public function command(AiChat $chat, string $prompt): array
    {
        $agent = $chat->agent;
        $binary = $this->binary($agent) ?? throw new \RuntimeException(self::AGENTS[$agent]['product'].' is not installed yet.');
        $model = $chat->model;
        $resume = $chat->session_id;
        // Cursor and Copilot have no system prompt: the first message carries the instructions.
        $first = $resume === null && $agent !== 'claude' ? self::INSTRUCTIONS."\n\n---\n\n".$prompt : $prompt;

        $arguments = match ($agent) {
            'claude' => [
                '-p', '--output-format', 'stream-json', '--verbose', '--include-partial-messages',
                '--mcp-config', $this->home().'/.laravelify/mcp.json', '--strict-mcp-config',
                // "=" binds one value: newer CLIs read --allowedTools as a list. Reading is for the
                // images and repositories attached to the chat; it has no shell and cannot write.
                '--allowedTools=mcp__laravelify,Read,Grep,Glob',
                '--disallowedTools=Bash,Write,Edit,NotebookEdit',
                '--append-system-prompt', self::INSTRUCTIONS,
                ...($model && $model !== 'default' ? ['--model', $model] : []),
                ...(isset(self::AGENTS['claude']['efforts'][$chat->effort ?? '']) ? ['--effort', $chat->effort] : []),
                ...($resume ? ['--resume', $resume] : []),
                '--', $prompt,
            ],
            'cursor' => [
                '-p', '--output-format', 'stream-json', '--stream-partial-output', '--approve-mcps', '--trust',
                ...($model && $model !== 'auto' ? ['--model', $model] : []),
                ...($resume ? ['--resume', $resume] : []),
                $first,
            ],
            default => [
                '-p', $first, '--output-format', 'json', '--allow-tool', 'laravelify',
                ...($model && $model !== 'auto' ? ['--model', $model] : []),
                ...($resume ? ['--resume', $resume] : []),
            ],
        };

        // Into its chat folder as its own user (the panel cannot enter that home itself).
        return $this->asUser(['/bin/sh', '-c', 'cd "$0" 2>/dev/null; exec "$@"', $this->home().'/chat', $binary, ...$arguments]);
    }

    /**
     * Put a message's attachments where the agent can read them, and say so at the top of the
     * prompt: images copied into the chat's folder, GitHub repositories cloned (shallow, read-only
     * copies, updated each time), and website logs as text.
     *
     * @param  list<array<string, mixed>>  $attachments
     */
    public function withAttachments(AiChat $chat, array $attachments, string $prompt): string
    {
        $notes = [];
        $folder = $this->home().'/chat';

        foreach ($attachments as $index => $attachment) {
            switch ($attachment['kind'] ?? null) {
                case 'image':
                    $source = storage_path('app/private/'.$attachment['path']);

                    if (! is_file($source)) {
                        break;
                    }

                    $target = $folder.'/attachments/'.$chat->id.'/'.basename((string) $attachment['path']);
                    $this->files->exec(['/bin/sh', '-c', 'mkdir -p "$(dirname "$1")" && cat > "$1"', 'image', $target], (string) file_get_contents($source), null, $this->user());
                    $notes[] = '[Image #'.($index + 1).'] is the file '.$target.' (open it with your Read tool).';
                    break;

                case 'repo':
                    $copy = $this->cloneRepository((string) $attachment['name'], (int) ($attachment['app'] ?? 0), (string) ($attachment['branch'] ?? ''));
                    $notes[] = $copy !== null
                        ? 'The GitHub repository '.$attachment['name'].' ('.$attachment['branch'].') is cloned at '.$copy.': read it there (it is a copy; nothing there is pushed).'
                        : 'The GitHub repository '.$attachment['name'].' could not be cloned; say so if it matters.';
                    break;

                case 'log':
                    $notes[] = 'The last lines of the log "'.$attachment['label'].'":'."\n```\n".mb_substr((string) $attachment['text'], -12000)."\n```";
                    break;
            }
        }

        return $notes === [] ? $prompt : "Attached to this message:\n".implode("\n\n", $notes)."\n\n---\n\n".$prompt;
    }

    /** A shallow copy of a repository of a connected GitHub App, in the chat's folder. */
    private function cloneRepository(string $fullName, int $appId, string $branch): ?string
    {
        $app = GithubApp::query()->find($appId);

        if ($app === null || preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $fullName) !== 1 || ($branch !== '' && ! GitInput::validBranch($branch))) {
            return null;
        }

        try {
            $token = app(GithubAppClient::class)->installationToken($app, $fullName);
        } catch (Throwable) {
            return null;
        }

        $target = $this->home().'/chat/repos/'.str_replace('/', '__', $fullName);
        $remote = 'https://x-access-token:'.$token.'@github.com/'.$fullName.'.git';
        $script = 'if [ -d "$1/.git" ]; then git -C "$1" fetch -q --depth 1 "$2" ${3:+"$3"} && git -C "$1" reset -q --hard FETCH_HEAD; '
            .'else rm -rf "$1" && git clone -q --depth 1 ${3:+--branch "$3"} "$2" "$1"; fi && git -C "$1" remote set-url origin "$4"';
        $process = new Process($this->asUser(['/bin/sh', '-c', $script, 'clone', $target, $remote, $branch, 'https://github.com/'.$fullName.'.git']), null, null, null, 600);
        $process->run();

        return $process->isSuccessful() ? $target : null;
    }

    /**
     * A command as the chat's user with its environment (directly on a development machine).
     *
     * @param  list<string>  $command
     * @return list<string>
     */
    public function asUser(array $command): array
    {
        $user = $this->user();

        if ($user->separate()) {
            return $user->wrap($command, $this->environment());
        }

        $variables = array_map(fn (string $key, string $value) => $key.'='.$value, array_keys($this->environment()), $this->environment());

        return ['/usr/bin/env', ...$variables, ...$command];
    }

    /**
     * The panel's MCP tools, for the chat's Skills list and "/" shortcuts.
     *
     * @return list<array{name: string, description: string, readOnly: bool}>
     */
    public function skills(): array
    {
        $classes = (new ReflectionClass(LaravelifyServer::class))->getProperty('tools')->getDefaultValue();
        $skills = [];

        foreach ($classes as $class) {
            /** @var Tool $tool */
            $tool = app($class);
            $reflection = new ReflectionClass($class);
            $skills[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'readOnly' => $reflection->getAttributes(IsReadOnly::class) !== [],
            ];
        }

        return $skills;
    }

    /**
     * @return array<string, string>
     */
    public function environment(): array
    {
        $home = $this->home();

        return [
            'HOME' => $home,
            'PATH' => $home.'/.local/bin:/opt/graphify/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'LANG' => 'C.UTF-8',
            'NO_COLOR' => '1',
            'TERM' => 'xterm-256color',
        ];
    }

    private function binary(string $agent): ?string
    {
        $path = trim($this->shell('command -v '.self::AGENTS[$agent]['binary'].' 2>/dev/null', 10));

        return $path !== '' && str_starts_with($path, '/') ? strtok($path, "\n") : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read(string $relative): ?array
    {
        $result = $this->files->exec(['cat', $this->home().'/'.$relative], null, null, $this->user());
        $data = $result->ok ? json_decode($result->output, true) : null;

        return is_array($data) ? $data : null;
    }

    private function shell(string $command, int $timeout): string
    {
        $process = new Process($this->asUser(['/bin/bash', '-lc', $command]), null, null, null, $timeout);

        try {
            $process->run();
        } catch (Throwable) {
            return '';
        }

        return trim($process->getOutput()."\n".$process->getErrorOutput());
    }
}
