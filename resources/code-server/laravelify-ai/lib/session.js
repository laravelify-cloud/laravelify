// Conversations shared by all agents. A chat lives in the workspace (.laravelify/ai), so when one
// agent stops (a usage limit, or you pick another) the next one reads the same history, the files
// changed so far, and the project map, and carries on.
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { AGENTS, LIMIT, parse, describeTool } = require('./agents');
const { run, stream } = require('./shell');

class Sessions {
    constructor(root) {
        this.root = root;
        this.dir = path.join(root, '.laravelify', 'ai');
        this.chatsDir = path.join(this.dir, 'chats');
        this.handoffFile = path.join(this.dir, 'handoff.md');
        this.child = null;
        this.graphTimer = null;
        fs.mkdirSync(this.chatsDir, { recursive: true });
        this.keepOutOfGit();
    }

    /** The chats and the project map are this editor's own: git never commits them. */
    keepOutOfGit() {
        const exclude = path.join(this.root, '.git', 'info', 'exclude');

        try {
            const current = fs.existsSync(exclude) ? fs.readFileSync(exclude, 'utf8') : '';
            const missing = ['.laravelify/', 'graphify-out/'].filter((line) => !current.split('\n').includes(line));

            if (missing.length) {
                fs.mkdirSync(path.dirname(exclude), { recursive: true });
                fs.appendFileSync(exclude, (current.endsWith('\n') || current === '' ? '' : '\n') + '# Laravelify AI (chats, project map)\n' + missing.join('\n') + '\n');
            }
        } catch (e) {}
    }

    list() {
        return fs.readdirSync(this.chatsDir)
            .filter((file) => file.endsWith('.json'))
            .map((file) => this.load(file.slice(0, -5)))
            .filter(Boolean)
            .sort((a, b) => b.updated - a.updated)
            .map((chat) => ({ id: chat.id, title: chat.title, updated: chat.updated, bookmarked: !!chat.bookmarked, agents: [...new Set(chat.turns.map((turn) => turn.agent))] }));
    }

    load(id) {
        try {
            return JSON.parse(fs.readFileSync(path.join(this.chatsDir, id + '.json'), 'utf8'));
        } catch (e) {
            return null;
        }
    }

    create() {
        const chat = { id: crypto.randomBytes(6).toString('hex'), title: 'New chat', created: Date.now(), updated: Date.now(), sessions: {}, turns: [] };
        this.save(chat);

        return chat;
    }

    save(chat) {
        chat.updated = Date.now();
        fs.writeFileSync(path.join(this.chatsDir, chat.id + '.json'), JSON.stringify(chat, null, 1));
    }

    remove(id) {
        fs.rmSync(path.join(this.chatsDir, id + '.json'), { force: true });
    }

    /**
     * Take back the last message and its answer. The files it changed stay as they are (Source
     * Control shows them); the agents' own sessions start fresh, so they forget it too.
     */
    rewind(chat) {
        const turn = chat.turns.pop();
        chat.sessions = {};
        this.save(chat);

        return turn;
    }

    /**
     * A checkpoint: every file of the workspace (tracked and new, not ignored) saved as a git
     * tree, without touching the real index, the branch or the stash.
     */
    async checkpoint() {
        const index = path.join(this.dir, 'checkpoint.index');
        const { ok, out } = await run(`cp "$(git rev-parse --git-dir)/index" '${index}' 2>/dev/null; GIT_INDEX_FILE='${index}' git add -A . && GIT_INDEX_FILE='${index}' git write-tree`, { cwd: this.root, timeout: 60000 });
        const tree = (out.match(/^[0-9a-f]{40,64}$/m) || [])[0];

        return ok && tree ? tree : null;
    }

    /**
     * Restore checkpoint: the files go back to how they were before that message (files made
     * since are removed), and the message with everything after it leaves the chat.
     */
    async restore(chat, turnId) {
        const position = chat.turns.findIndex((turn) => turn.id === turnId);
        const turn = chat.turns[position];

        if (!turn || !turn.checkpoint) {
            throw new Error('This message has no checkpoint (it was sent before checkpoints existed).');
        }

        const now = await this.checkpoint();
        const index = path.join(this.dir, 'restore.index');
        const { ok, out } = await run(`set -e
            git diff --name-only --no-renames --diff-filter=A -z ${turn.checkpoint} ${now} | xargs -0 -r rm -f --
            GIT_INDEX_FILE='${index}' git read-tree ${turn.checkpoint}
            GIT_INDEX_FILE='${index}' git checkout-index -a -f
            rm -f '${index}'`, { cwd: this.root, timeout: 120000 });

        if (!ok) {
            throw new Error(out.trim().split('\n').slice(-3).join('\n') || 'git could not restore the files.');
        }

        chat.turns.splice(position);
        chat.sessions = {};
        this.save(chat);
        await this.writeHandoff(chat);

        return turn;
    }

    bookmark(chat) {
        chat.bookmarked = !chat.bookmarked;
        this.save(chat);
    }

    /** The conversation as Markdown, saved in .laravelify/ai/exports. */
    export(chat) {
        const lines = ['# ' + chat.title, '', '_Exported ' + new Date().toISOString().slice(0, 16).replace('T', ' ') + '_', ''];

        for (const turn of chat.turns) {
            const agent = AGENTS[turn.agent];
            lines.push('## You', '', turn.prompt, '');

            if (turn.files && turn.files.length) {
                lines.push('Context: ' + turn.files.map((file) => '`' + file + '`').join(', '), '');
            }

            lines.push(`## ${agent ? agent.product : turn.agent}${turn.model ? ' · ' + turn.model : ''}${turn.effort ? ' · effort ' + turn.effort : ''}`, '');

            for (const part of turn.parts) {
                lines.push(part.kind === 'tool' ? '- _' + part.text + '_' : part.text, '');
            }

            if (turn.error) {
                lines.push('> ' + turn.error.split('\n').join('\n> '), '');
            }
        }

        const dir = path.join(this.dir, 'exports');
        fs.mkdirSync(dir, { recursive: true });
        const file = path.join(dir, chat.title.replace(/[^\w-]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 50) + '-' + chat.id + '.md');
        fs.writeFileSync(file, lines.join('\n'));

        return file;
    }

    /**
     * Send one message. `emit` receives the live updates for the chat view. Resolves when the
     * agent is done (or stopped), with the finished turn.
     */
    /** Pasted images, saved in .laravelify/ai/images so every agent can open them as files. */
    saveImages(chat, turnId, images) {
        const dir = path.join(this.dir, 'images');
        const saved = [];

        for (const image of (images || []).slice(0, 10)) {
            const match = /^data:image\/(png|jpe?g|gif|webp);base64,(.+)$/.exec(image.dataUrl || '');

            if (!match) continue;

            const data = Buffer.from(match[2], 'base64');

            if (data.length > 10 * 1024 * 1024) continue;

            fs.mkdirSync(dir, { recursive: true });
            const file = path.join(dir, `${chat.id}-${turnId}-${Number(image.n) || saved.length + 1}.${match[1].replace('jpeg', 'jpg')}`);
            fs.writeFileSync(file, data);
            saved.push({ n: Number(image.n) || saved.length + 1, path: path.relative(this.root, file) });
        }

        return saved;
    }

    async send(chat, { agent: agentId, model, effort, prompt, files, snippets, images }, bin, emit) {
        const agent = AGENTS[agentId];
        const previous = chat.turns.length ? chat.turns[chat.turns.length - 1].agent : null;
        const switched = previous !== null && previous !== agentId;
        const resume = switched ? null : chat.sessions[agentId] || null;
        effort = agent.efforts.includes(effort) ? effort : null;
        const checkpoint = await this.checkpoint();
        const turnId = crypto.randomBytes(4).toString('hex');
        const turn = { id: turnId, agent: agentId, model, effort, prompt, files: files || [], snippets: (snippets || []).slice(0, 20), images: this.saveImages(chat, turnId, images), parts: [], status: 'running', started: Date.now(), continuedFrom: switched ? previous : null, checkpoint };

        if (chat.title === 'New chat') {
            chat.title = prompt.replace(/\s+/g, ' ').slice(0, 60);
        }

        chat.turns.push(turn);
        this.save(chat);
        await this.writeHandoff(chat);
        emit({ type: 'turn', chatId: chat.id, turn });

        const full = this.buildPrompt(chat, turn, { switched, firstForAgent: !resume });
        // Files already changed before this message are not counted as its changes.
        const before = new Map((await this.changedFiles()).map((change) => [change.path, change.status + ':' + this.stamp(change.path)]));
        let gotDelta = false;
        let errorText = '';

        const addText = (text, delta) => {
            const last = turn.parts[turn.parts.length - 1];

            if (last && last.kind === 'text') {
                // Some CLIs send a segment in pieces and then whole again (or the text so far):
                // a repeat or a longer version of what is shown replaces it instead of adding to it.
                if (text === last.text || last.text.endsWith(text) && text.length > 40) {
                    return;
                }

                last.text = !delta || (text.startsWith(last.text) && last.text.length > 0) ? text : last.text + text;
            } else {
                turn.parts.push({ kind: 'text', text });
            }

            emit({ type: 'parts', chatId: chat.id, turnId: turn.id, parts: turn.parts });
        };

        await new Promise((resolve) => {
            this.child = stream(bin, agent.args(full, model, resume, effort), {
                cwd: this.root,
                onLine: (line) => {
                    for (const event of parse(agentId, line)) {
                        if (event.kind === 'session') {
                            chat.sessions[agentId] = event.id;
                        } else if (event.kind === 'text') {
                            gotDelta = gotDelta || event.delta;
                            addText(event.text, event.delta);
                        } else if (event.kind === 'text-block') {
                            // Claude sends each block whole after its pieces: only used when no piece came.
                            if (!gotDelta) {
                                addText(event.text, false);
                            }

                            gotDelta = false;
                            turn.parts.push({ kind: 'break' });
                        } else if (event.kind === 'thinking') {
                            emit({ type: 'thinking', chatId: chat.id, turnId: turn.id });
                        } else if (event.kind === 'tool') {
                            turn.parts.push({ kind: 'tool', ...describeTool(event.name, event.input) });
                            emit({ type: 'parts', chatId: chat.id, turnId: turn.id, parts: turn.parts });
                        } else if (event.kind === 'result') {
                            if (event.error) {
                                errorText = event.text || errorText;
                            } else if (!turn.parts.some((part) => part.kind === 'text') && event.text) {
                                addText(event.text, false);
                            }
                        } else if (event.kind === 'raw') {
                            errorText += event.text + '\n';
                        }
                    }
                },
                onExit: (code, stderr) => {
                    this.child = null;
                    turn.parts = turn.parts.filter((part) => part.kind !== 'break');

                    if (turn.status === 'stopped') {
                        // Stopped by the person: keep what came.
                    } else if (code !== 0 || errorText.trim() !== '' && !turn.parts.some((part) => part.kind === 'text')) {
                        const message = (errorText || stderr || '').replace(/\x1b\[[0-9;?]*[A-Za-z]/g, '').trim().split('\n').slice(-6).join('\n');
                        turn.status = LIMIT.test(message) ? 'limit' : 'error';
                        turn.error = message || 'The agent stopped without an answer (exit ' + code + ').';
                    } else {
                        turn.status = 'done';
                    }

                    resolve();
                },
            });
        });

        turn.finished = Date.now();
        turn.changes = (await this.changedFiles()).filter((change) => before.get(change.path) !== change.status + ':' + this.stamp(change.path));
        this.save(chat);
        await this.writeHandoff(chat);
        emit({ type: 'done', chatId: chat.id, turn });
        this.refreshGraph(emit);

        return turn;
    }

    stop() {
        if (this.child) {
            this.child.kill('SIGINT');
            setTimeout(() => this.child && this.child.kill('SIGKILL'), 3000);
        }
    }

    markStopped(chat) {
        const last = chat.turns[chat.turns.length - 1];

        if (last && last.status === 'running') {
            last.status = 'stopped';
            this.save(chat);
        }
    }

    /**
     * What the agent receives: the message, with a short brief the first time an agent joins
     * the chat (the project map, and the handoff when another agent did the work before).
     */
    buildPrompt(chat, turn, { switched, firstForAgent }) {
        const notes = [];
        const hasGraph = fs.existsSync(path.join(this.root, 'graphify-out', 'GRAPH_REPORT.md'));

        if (firstForAgent && hasGraph) {
            notes.push('A map of this project (modules, classes and how they connect) is in graphify-out/GRAPH_REPORT.md. Use it only when you need to find where something lives; the files named in the message do not need it.');
        }

        let handoff = '';
        const previous = chat.turns[chat.turns.length - 2];

        if (previous && (switched || firstForAgent)) {
            const before = AGENTS[previous.agent];
            const unfinished = previous.status !== 'done';

            if (unfinished) {
                // The other agent stopped mid-task (a usage limit, an error, or Stop): hand the work over.
                notes.push(`${before ? before.product : 'Another assistant'} was working on this and stopped${previous.status === 'limit' ? ' at its usage limit' : ''} before finishing. The handoff below has the task, what was done and the files changed. Finish that work first (check what is already done; do not redo it), then answer the new message.`);

                try {
                    const text = fs.readFileSync(this.handoffFile, 'utf8');
                    handoff = '\n\n[Handoff]\n' + (text.length > 12000 ? text.slice(0, 2000) + '\n[…]\n' + text.slice(-9500) : text);
                } catch (e) {}
            } else {
                // The other agent finished: the conversation is only background for the new message.
                notes.push(`Earlier messages in this chat were answered by ${before ? before.product : 'another assistant'} and are finished. The recap below is background only: answer just the new message, and do no more than it asks.`);
                handoff = '\n\n[Earlier in this chat]\n' + chat.turns.slice(-5, -1).map((t) => {
                    const answer = t.parts.filter((part) => part.kind === 'text').map((part) => part.text).join(' ').replace(/\s+/g, ' ').trim();
                    const changed = (t.changes || []).map((change) => change.path).join(', ');

                    return `User: ${t.prompt.slice(0, 400)}\nAssistant: ${answer.length > 500 ? answer.slice(0, 500) + '…' : answer || '(no text)'}${changed ? `\nFiles changed: ${changed}` : ''}`;
                }).join('\n\n');
            }
        }

        if (turn.files.length) {
            notes.push('Files the user attached for context: ' + turn.files.join(', '));
        }

        notes.push('Do not edit files in graphify-out/ or .laravelify/ (they are generated).');

        // Selected code and long pasted text, sent beside the message.
        const blocks = (turn.snippets || []).map((snippet) => snippet.kind === 'selection'
            ? `\n\n[Selected code: ${snippet.path}, lines ${snippet.start}-${snippet.end}]\n\`\`\`${snippet.lang || ''}\n${snippet.text}\n\`\`\``
            : `\n\n[Pasted text]\n\`\`\`\n${snippet.text}\n\`\`\``).join('');
        const pictures = (turn.images || []).length
            ? '\n\n[Images]\nThe message refers to these images by number. Open each file to look at it:\n' + turn.images.map((image) => `- [Image #${image.n}]: ${image.path}`).join('\n')
            : '';
        const message = turn.prompt + blocks + pictures;

        return notes.length > 1 || switched || firstForAgent
            ? '[Context]\n- ' + notes.join('\n- ') + handoff + '\n\n[Message]\n' + message
            : message;
    }

    /** The handoff: everything the next agent needs to pick up the task. */
    async writeHandoff(chat) {
        const lines = ['# Handoff: ' + chat.title, '', '_Written by the Laravelify AI panel after every message. Any assistant picking up this task reads it first._', ''];
        const first = chat.turns[0];

        if (first) {
            lines.push('## The task', '', first.prompt, '');
        }

        lines.push('## Conversation so far', '');

        for (const turn of chat.turns.slice(-10)) {
            const agent = AGENTS[turn.agent];
            const text = turn.parts.filter((part) => part.kind === 'text').map((part) => part.text).join('\n').trim();
            const tools = turn.parts.filter((part) => part.kind === 'tool').map((part) => '  - ' + part.text);
            const state = { done: 'finished', running: 'in progress', stopped: 'stopped by the user', limit: 'STOPPED: usage limit reached', error: 'stopped with an error' }[turn.status] || turn.status;

            lines.push(`### ${agent ? agent.product : turn.agent}${turn.model ? ' (' + turn.model + ')' : ''}: ${state}`, '', '**User:** ' + turn.prompt, '');

            if (tools.length) {
                lines.push('**Actions:**', ...tools.slice(-25), '');
            }

            if (text) {
                lines.push('**Answer:** ' + (text.length > 2500 ? text.slice(0, 2500) + ' […]' : text), '');
            }

            if (turn.error) {
                lines.push('**Stopped with:** ' + turn.error, '');
            }
        }

        const changes = await this.changedFiles();
        lines.push('## Files changed (not committed yet)', '', changes.length ? changes.map((change) => '- ' + change.status + ' ' + change.path).join('\n') : '- none', '');

        const last = chat.turns[chat.turns.length - 1];

        if (last && last.status !== 'done') {
            lines.push('## Where it stopped', '', 'The last message was not finished (' + (last.error ? last.error.split('\n')[0] : last.status) + '). Continue from there.', '');
        }

        fs.writeFileSync(this.handoffFile, lines.join('\n'));
    }

    /** When a file last changed (so a file edited again by an answer still counts). */
    stamp(relative) {
        try {
            return fs.statSync(path.join(this.root, relative)).mtimeMs;
        } catch (e) {
            return 0;
        }
    }

    async changedFiles() {
        const { ok, out } = await run('git status --porcelain=v1 --untracked-files=all -- . ":!graphify-out" ":!.laravelify"', { cwd: this.root, timeout: 15000 });

        if (!ok) {
            return [];
        }

        return out.split('\n').filter((line) => line.length > 3).map((line) => ({ status: line.slice(0, 2).trim() || 'M', path: line.slice(3) }));
    }

    /** Rebuild the project map after a change (graphify, code only, no AI and no key). */
    refreshGraph(emit) {
        clearTimeout(this.graphTimer);
        this.graphTimer = setTimeout(async () => {
            emit({ type: 'graph', state: 'updating' });
            const { ok, out } = await run('command -v graphify >/dev/null && graphify update . 2>&1 | tail -3', { cwd: this.root, timeout: 300000 });
            const summary = (out.match(/Rebuilt:\s*([^\n]+)/) || [])[1] || null;
            emit({ type: 'graph', state: ok ? 'ready' : 'missing', summary });
        }, 1500);
    }
}

module.exports = { Sessions };
