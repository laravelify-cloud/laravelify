// The three agents: how each is installed, signed in to, listed, run, and how its answers are read.
// They all run as this website's own user, in the workspace, through their official CLIs.
const vscode = require('vscode');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { run, which, shellPath } = require('./shell');

const home = os.homedir();

/** The Claude Code extension ships the claude CLI; use it instead of a second copy. */
function claudeFromExtension() {
    const extension = vscode.extensions.getExtension('anthropic.claude-code');

    if (!extension) {
        return null;
    }

    const binary = path.join(extension.extensionPath, 'resources', 'native-binary', 'claude');

    return fs.existsSync(binary) ? binary : null;
}

const AGENTS = {
    claude: {
        id: 'claude',
        label: 'Claude',
        product: 'Claude Code',
        color: '#d97757',
        mark: '✳',
        account: 'Claude account (Pro, Max, Team) or Anthropic Console',
        async binary() {
            // Its own CLI first: the Claude Code extension's copy goes away when that extension is removed.
            return (await which('claude')) || claudeFromExtension();
        },
        install: 'NPM_CONFIG_PREFIX="$HOME/.local" npm install -g @anthropic-ai/claude-code',
        async status(bin) {
            const { ok, out } = await run(`'${bin}' auth status`, { timeout: 20000 });

            try {
                const data = JSON.parse(out.slice(out.indexOf('{')));

                return { signedIn: !!data.loggedIn, who: data.email || data.account?.email || data.authMethod || null };
            } catch (e) {
                return { signedIn: ok && /logged in/i.test(out) && !/not logged/i.test(out), who: null };
            }
        },
        login: (bin) => `'${bin}' auth login`,
        logout: (bin) => `'${bin}' auth logout`,
        async models() {
            return [
                { id: 'default', name: 'Default', detail: 'Your plan’s default' },
                { id: 'sonnet', name: 'Sonnet', detail: 'Fast, everyday coding' },
                { id: 'opus', name: 'Opus', detail: 'Hardest tasks' },
                { id: 'haiku', name: 'Haiku', detail: 'Quick edits' },
            ];
        },
        efforts: ['low', 'medium', 'high', 'xhigh', 'max'],
        usageUrl: 'https://claude.ai/settings/usage',
        args(prompt, model, resume, effort) {
            return [
                '-p', '--output-format', 'stream-json', '--verbose', '--include-partial-messages',
                // "=" binds one value: newer CLIs read --allowedTools as a list and would take
                // the message as another tool name, then wait for a prompt on stdin.
                '--permission-mode', 'acceptEdits', '--allowedTools=Bash',
                ...(model && model !== 'default' ? ['--model', model] : []),
                ...(effort ? ['--effort', effort] : []),
                ...(resume ? ['--resume', resume] : []),
                '--', prompt,
            ];
        },
    },

    cursor: {
        id: 'cursor',
        label: 'Cursor',
        product: 'Cursor Agent',
        color: '#111111',
        mark: '⌘',
        account: 'Cursor account',
        async binary() {
            return which('cursor-agent');
        },
        install: 'curl -fsSL https://cursor.com/install | bash',
        async status(bin) {
            const { out } = await run(`'${bin}' status`, { timeout: 20000 });
            const signedIn = /logged in/i.test(out) && !/not logged/i.test(out);
            const who = (out.match(/logged in as\s+(\S+)/i) || [])[1] || null;

            return { signedIn, who };
        },
        login: (bin) => `'${bin}' login`,
        logout: (bin) => `'${bin}' logout`,
        async models(bin) {
            const { out } = await run(`'${bin}' models`, { timeout: 30000 });
            const models = [];

            for (const line of out.split('\n')) {
                const match = line.match(/^\s*([a-z0-9][\w.\-[\]=,]*)\s+-\s+(.+?)\s*(\(.*\))?\s*$/i);

                if (match) {
                    models.push({ id: match[1], name: match[2], detail: match[3] ? match[3].replace(/[()]/g, '') : '' });
                }
            }

            if (!models.length) {
                return [{ id: 'auto', name: 'Auto', detail: 'Cursor picks the model' }];
            }

            // The list has hundreds of variants: show the ones Cursor's own picker leads with, in its
            // order, and keep the rest for a search.
            const featured = this.featured.filter((id) => models.some((m) => m.id === id));
            const LEVELS = { low: 'Low', medium: 'Medium', high: 'High', xhigh: 'Extra High', max: 'Max' };
            const tidy = (m) => {
                let name = m.name.replace(/\s*\(NO ZDR\)/, '').replace(/\s+1M\b/, '').replace(/[​]/g, '').replace(/\s+/g, ' ').trim();
                const level = LEVELS[(m.id.match(/-(low|medium|high|xhigh|max)$/) || [])[1]];

                return level && !name.endsWith(level) ? name + ' ' + level : name;
            };

            return [
                ...featured.map((id) => models.find((m) => m.id === id)),
                ...models.filter((m) => !featured.includes(m.id)).map((m) => ({ ...m, featured: false })),
            ].map((m) => ({ ...m, name: tidy(m), detail: m.id === 'auto' ? '' : m.detail }));
        },
        featured: ['auto', 'grok-4.7-high', 'cursor-grok-4.6-high', 'composer-2.5-fast', 'claude-opus-5-5-medium', 'gemini-4-argon-medium', 'muse-wonder-1.0-high', 'gpt-5.6-sol-medium', 'claude-fable-5-1-high'],
        // Effort is part of Cursor's models (Codex 5.3 Low, High, Extra High…), not a setting.
        efforts: [],
        usageUrl: 'https://cursor.com/dashboard',
        args(prompt, model, resume) {
            return [
                '-p', '--output-format', 'stream-json', '--stream-partial-output', '--force',
                ...(model && model !== 'auto' ? ['--model', model] : []),
                ...(resume ? ['--resume', resume] : []),
                prompt,
            ];
        },
    },

    copilot: {
        id: 'copilot',
        label: 'Copilot',
        product: 'GitHub Copilot CLI',
        color: '#24292f',
        mark: '◉',
        account: 'GitHub account with Copilot',
        async binary() {
            return which('copilot');
        },
        install: 'NPM_CONFIG_PREFIX="$HOME/.local" npm install -g @github/copilot',
        async status() {
            // The CLI keeps the signed-in GitHub user in its config (or a token in the environment).
            if (process.env.COPILOT_GITHUB_TOKEN || process.env.GH_TOKEN) {
                return { signedIn: true, who: 'token' };
            }

            for (const file of ['config.json', 'settings.json']) {
                try {
                    const data = JSON.parse(fs.readFileSync(path.join(home, '.copilot', file), 'utf8'));
                    const users = data.logged_in_users || data.loggedInUsers || [];
                    const last = data.last_logged_in_user || data.lastLoggedInUser;

                    if ((Array.isArray(users) && users.length) || last) {
                        const user = last || users[0];

                        return { signedIn: true, who: typeof user === 'string' ? user : user.login || null };
                    }
                } catch (e) {}
            }

            return { signedIn: false, who: null };
        },
        // Device code: a short code to type at github.com/login/device. The browser flow
        // would send the browser back to a port on this server, which it cannot reach.
        login: (bin) => `'${bin}' login --device-code`,
        logout: (bin) => `'${bin}' logout`,
        async models() {
            return [
                { id: 'auto', name: 'Auto', detail: 'Copilot picks the model' },
                { id: 'claude-sonnet-4.5', name: 'Claude Sonnet 4.5', detail: '' },
                { id: 'gpt-5', name: 'GPT-5', detail: '' },
                { id: 'gpt-5-mini', name: 'GPT-5 mini', detail: '' },
                { id: 'gemini-2.5-pro', name: 'Gemini 2.5 Pro', detail: '' },
            ];
        },
        efforts: ['minimal', 'low', 'medium', 'high', 'xhigh', 'max'],
        usageUrl: 'https://github.com/settings/copilot',
        args(prompt, model, resume, effort) {
            return [
                '-p', prompt, '--output-format', 'json', '--allow-all-tools', '--allow-all-paths',
                ...(model && model !== 'auto' ? ['--model', model] : []),
                ...(effort ? ['--reasoning-effort', effort] : []),
                ...(resume ? ['--resume', resume] : []),
            ];
        },
    },
};

/** Messages from the CLIs that mean the account ran out (so the chat offers another agent). */
const LIMIT = /(usage|rate|session|weekly|monthly)[ -]?limit|limit (reached|exceeded|hit)|quota|out of (credits|requests)|too many requests|\b429\b|upgrade (your|to)|credits? (balance|exhausted)|resets? (at|in)\b/i;

/**
 * One JSON line from a CLI, turned into the chat's events:
 * { kind: 'session', id } | { kind: 'text', text, delta } | { kind: 'thinking' } | { kind: 'tool', name, input }
 * | { kind: 'result', text, error } .
 */
function parse(agent, line) {
    let event;

    try {
        event = JSON.parse(line);
    } catch (e) {
        return line.trim() ? [{ kind: 'raw', text: line }] : [];
    }

    const out = [];
    const session = event.session_id || event.sessionId || event.session?.id;

    if (session) {
        out.push({ kind: 'session', id: session });
    }

    // Claude: partial text arrives as stream events, whole blocks as "assistant" messages.
    if (event.type === 'stream_event' && event.event?.delta?.type === 'text_delta') {
        out.push({ kind: 'text', text: event.event.delta.text, delta: true });

        return out;
    }

    if (event.type === 'stream_event') {
        return out;
    }

    if (event.type === 'thinking') {
        out.push({ kind: 'thinking' });

        return out;
    }

    if (event.type === 'assistant' && Array.isArray(event.message?.content)) {
        for (const block of event.message.content) {
            if (block.type === 'text' && block.text) {
                // Cursor: lines with a timestamp are pieces; the last one (no timestamp) is the whole text.
                if (agent === 'cursor') {
                    out.push({ kind: 'text', text: block.text, delta: !!event.timestamp_ms });
                } else if (agent !== 'claude') {
                    out.push({ kind: 'text', text: block.text, delta: false });
                } else {
                    out.push({ kind: 'text-block', text: block.text });
                }
            } else if (block.type === 'tool_use') {
                out.push({ kind: 'tool', name: block.name, input: block.input || {} });
            }
        }

        return out;
    }

    if (event.type === 'tool_call' && event.subtype === 'started' && event.tool_call) {
        const name = Object.keys(event.tool_call)[0];
        out.push({ kind: 'tool', name, input: (event.tool_call[name] || {}).args || {} });

        return out;
    }

    if (event.type === 'result') {
        out.push({ kind: 'result', text: event.result || '', error: !!event.is_error || event.subtype === 'error' });

        return out;
    }

    // Copilot's JSON lines: take any text and tool the event carries.
    if (agent === 'copilot') {
        const type = String(event.type || event.event || '');
        const text = event.delta ?? event.text ?? event.content ?? event.message?.content ?? event.data?.content ?? event.data?.delta;

        if (/tool/i.test(type) && /start|call|request|execut/i.test(type)) {
            out.push({ kind: 'tool', name: event.toolName || event.name || event.data?.toolName || 'tool', input: event.arguments || event.data?.arguments || {} });
        } else if (/error/i.test(type)) {
            out.push({ kind: 'result', text: String(event.message || event.error || text || 'Copilot stopped with an error.'), error: true });
        } else if (typeof text === 'string' && text !== '') {
            out.push({ kind: 'text', text, delta: /delta|chunk|partial/i.test(type) });
        }
    }

    return out;
}

/** One line for a tool the agent used. */
function describeTool(name, input) {
    const file = input.file_path || input.path || input.filePath || input.target_file || '';
    const short = (value) => String(value).split('/').slice(-2).join('/');
    const n = String(name || '');

    if (/^(Read|readToolCall|view|read_file)$/i.test(n)) return { icon: 'eye', text: 'Read ' + short(file), file };
    if (/^(Write|writeToolCall|create|create_file)$/i.test(n)) return { icon: 'new-file', text: 'Created ' + short(file), file };
    if (/edit|str_replace|apply_patch/i.test(n)) return { icon: 'edit', text: 'Edited ' + short(file), file };
    if (/bash|shell|run|command/i.test(n)) return { icon: 'terminal', text: 'Ran ' + String(input.command || input.cmd || '').slice(0, 120) };
    if (/grep|glob|search|find/i.test(n)) return { icon: 'search', text: 'Searched ' + String(input.pattern || input.query || input.globPattern || '') };
    if (/todo/i.test(n)) return { icon: 'checklist', text: 'Updated the plan' };

    return { icon: 'tools', text: n.replace(/ToolCall$/, '') || 'Tool' };
}

module.exports = { AGENTS, LIMIT, parse, describeTool, shellPath };
