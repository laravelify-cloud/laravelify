// Laravelify AI: a chat for Claude, Cursor and Copilot in VS Code (a Laravelify website's editor).
// Pick an agent and model, sign in from a dialog, and switch agents in the middle of a task: the
// conversation, the files changed, and a project map (graphify) carry over to the next one.
const vscode = require('vscode');
const path = require('path');
const fs = require('fs');
const { AGENTS } = require('./lib/agents');
const { Sessions } = require('./lib/session');
const { Login } = require('./lib/login');
const { run } = require('./lib/shell');
const { LiveServer } = require('./lib/live');

let live = null;

class ChatView {
    constructor(context) {
        this.context = context;
        this.view = null;
        this.sessions = null;
        this.chat = null;
        this.login = null;
        this.busy = false;
        this.bins = {};
    }

    root() {
        const folder = vscode.workspace.workspaceFolders && vscode.workspace.workspaceFolders[0];

        return folder ? folder.uri.fsPath : null;
    }

    ensureSessions() {
        if (!this.sessions && this.root()) {
            this.sessions = new Sessions(this.root());
        }

        return this.sessions;
    }

    resolveWebviewView(view) {
        this.view = view;
        this.ready = false;
        const media = vscode.Uri.joinPath(this.context.extensionUri, 'media');
        view.webview.options = { enableScripts: true, localResourceRoots: [media] };
        view.webview.html = this.html(view.webview, media);
        view.webview.onDidReceiveMessage((message) => this.receive(message).catch((error) => this.post({ type: 'error', message: String(error.message || error) })));
    }

    post(message) {
        if (this.view) {
            this.view.webview.postMessage(message);
        }
    }

    /** For messages sent while the chat is still opening (Ctrl+L with the panel closed). */
    postWhenReady(message) {
        if (this.view && this.ready) {
            this.post(message);
        } else {
            this.pending = [...(this.pending || []), message];
        }
    }

    async receive(message) {
        const sessions = this.ensureSessions();

        switch (message.type) {
            case 'ready':
                this.chat = sessions ? (sessions.list()[0] ? sessions.load(sessions.list()[0].id) : sessions.create()) : null;
                this.post({ type: 'state', chat: this.chat, chats: sessions ? sessions.list() : [], busy: this.busy, saved: this.context.globalState.get('laravelifyAi.pick') || null });
                this.sendActiveFile();
                this.ready = true;
                (this.pending || []).splice(0).forEach((pending) => this.post(pending));
                await this.refreshAgents();
                this.post({ type: 'graph', state: require('fs').existsSync(path.join(this.root() || '', 'graphify-out', 'GRAPH_REPORT.md')) ? 'ready' : 'missing' });
                break;

            case 'agents':
                await this.refreshAgents();
                break;

            case 'models': {
                const bin = await this.binary(message.agent);
                const models = bin ? await AGENTS[message.agent].models(bin) : await AGENTS[message.agent].models(null);
                this.post({ type: 'models', agent: message.agent, models });
                break;
            }

            case 'pick':
                await this.context.globalState.update('laravelifyAi.pick', { agent: message.agent, model: message.model });
                break;

            case 'send':
                await this.send(message);
                break;

            // Files and folders dropped on the chat: VS Code hands them over as URIs or paths.
            case 'dropPaths':
                for (const entry of message.paths || []) {
                    let fsPath = entry;

                    try {
                        fsPath = /^[a-z][\w+.-]*:/i.test(entry) ? vscode.Uri.parse(entry).fsPath : entry;
                    } catch (e) {}

                    const relative = path.relative(this.root(), fsPath);

                    if (relative && !relative.startsWith('..') && !path.isAbsolute(relative)) {
                        const isFolder = fs.existsSync(fsPath) && fs.statSync(fsPath).isDirectory();
                        this.post({ type: 'attach', path: relative + (isFolder ? '/' : '') });
                    }
                }

                break;

            case 'stop':
                if (sessions) {
                    sessions.stop();
                    sessions.markStopped(this.chat);
                }

                break;

            case 'newChat':
                if (!this.busy) {
                    this.chat = sessions.create();
                    this.post({ type: 'state', chat: this.chat, chats: sessions.list(), busy: false });
                }

                break;

            case 'openChat':
                if (!this.busy) {
                    this.chat = sessions.load(message.id) || this.chat;
                    this.post({ type: 'state', chat: this.chat, chats: sessions.list(), busy: false });
                }

                break;

            case 'deleteChat':
                sessions.remove(message.id);

                if (this.chat && this.chat.id === message.id) {
                    this.chat = sessions.list()[0] ? sessions.load(sessions.list()[0].id) : sessions.create();
                }

                this.post({ type: 'state', chat: this.chat, chats: sessions.list(), busy: this.busy });
                break;

            case 'login':
                await this.startLogin(message.agent);
                break;

            case 'loginAnswer':
                if (this.login) {
                    this.login.answer(message.text);
                }

                break;

            case 'loginCancel':
                if (this.login) {
                    this.login.cancel();
                    this.login = null;
                }

                break;

            case 'logout': {
                const bin = await this.binary(message.agent);

                if (bin) {
                    await run(AGENTS[message.agent].logout(bin), { timeout: 30000 });
                }

                await this.refreshAgents();
                break;
            }

            case 'install':
                await this.install(message.agent);
                break;

            case 'copy':
                await vscode.env.clipboard.writeText(String(message.text || ''));
                break;

            case 'openUrl':
                await vscode.env.openExternal(vscode.Uri.parse(message.url));
                break;

            case 'openFile': {
                const file = path.isAbsolute(message.path) ? message.path : path.join(this.root(), message.path);
                await vscode.window.showTextDocument(vscode.Uri.file(file), { preview: true, preserveFocus: false });
                break;
            }

            case 'openChanges':
                await vscode.commands.executeCommand('workbench.view.scm');
                break;

            case 'attachPick': {
                const uris = await vscode.window.showOpenDialog({ canSelectMany: true, canSelectFolders: true, defaultUri: vscode.Uri.file(this.root()), openLabel: 'Add to chat' });

                for (const uri of uris || []) {
                    this.post({ type: 'attach', path: path.relative(this.root(), uri.fsPath) || '.' });
                }

                break;
            }

            case 'rewind':
                if (!this.busy && this.chat && this.chat.turns.length) {
                    const turn = sessions.rewind(this.chat);
                    this.post({ type: 'state', chat: this.chat, chats: sessions.list(), busy: false });
                    this.post({ type: 'restorePrompt', text: turn.prompt });

                    if (turn.changes && turn.changes.length) {
                        vscode.window.showInformationMessage(`Rewound. The ${turn.changes.length} file(s) that answer changed are still changed: review them in Source Control.`, 'Open Source Control')
                            .then((choice) => choice && vscode.commands.executeCommand('workbench.view.scm'));
                    }
                }

                break;

            case 'restore': {
                if (this.busy || !this.chat) break;

                const later = this.chat.turns.length - this.chat.turns.findIndex((turn) => turn.id === message.turnId);
                const choice = await vscode.window.showWarningMessage(
                    'Restore checkpoint?',
                    { modal: true, detail: `The project's files go back to how they were before this message (files made since are removed). This message${later > 1 ? ` and the ${later - 1} after it` : ''} leave the chat; the message goes back in the box. Ignored files like .env are not touched.` },
                    'Restore',
                );

                if (choice !== 'Restore') break;

                try {
                    const turn = await sessions.restore(this.chat, message.turnId);
                    this.post({ type: 'state', chat: this.chat, chats: sessions.list(), busy: false });
                    this.post({ type: 'restorePrompt', text: turn.prompt });
                    vscode.window.setStatusBarMessage('$(history) Checkpoint restored', 4000);
                    sessions.refreshGraph((event) => this.post(event));
                } catch (error) {
                    vscode.window.showErrorMessage('Could not restore the checkpoint: ' + error.message);
                }

                break;
            }

            case 'bookmark':
                if (this.chat) {
                    sessions.bookmark(this.chat);
                    this.post({ type: 'state', chat: this.chat, chats: sessions.list(), busy: this.busy });
                }

                break;

            case 'export':
                if (this.chat && this.chat.turns.length) {
                    const file = sessions.export(this.chat);
                    await vscode.window.showTextDocument(vscode.Uri.file(file), { preview: false });
                }

                break;

            case 'mentionPick': {
                const files = await vscode.workspace.findFiles('**/*', '{**/node_modules/**,**/vendor/**,**/.git/**,**/graphify-out/**,**/.laravelify/**,**/storage/**,**/public/build/**}', 5000);
                const pick = await vscode.window.showQuickPick(
                    files.map((uri) => ({ label: path.basename(uri.fsPath), description: path.relative(this.root(), path.dirname(uri.fsPath)), path: path.relative(this.root(), uri.fsPath) }))
                        .sort((a, b) => a.path.localeCompare(b.path)),
                    { placeHolder: 'Mention a file from this project', matchOnDescription: true, canPickMany: true },
                );

                for (const item of pick || []) {
                    this.post({ type: 'attach', path: item.path });
                }

                break;
            }

            case 'usage':
                await vscode.env.openExternal(vscode.Uri.parse(AGENTS[message.agent].usageUrl));
                break;

            case 'openMap':
                await vscode.window.showTextDocument(vscode.Uri.file(path.join(this.root(), 'graphify-out', 'GRAPH_REPORT.md')), { preview: true });
                break;

            case 'rebuildMap':
                sessions.refreshGraph((event) => this.post(event));
                break;
        }
    }

    async binary(agentId) {
        if (!this.bins[agentId]) {
            this.bins[agentId] = await AGENTS[agentId].binary();
        }

        return this.bins[agentId];
    }

    async refreshAgents() {
        this.bins = {};
        const result = {};

        await Promise.all(Object.keys(AGENTS).map(async (id) => {
            const bin = await this.binary(id);
            const status = bin ? await AGENTS[id].status(bin) : { signedIn: false, who: null };
            result[id] = { installed: !!bin, ...status };
        }));

        this.post({ type: 'agents', agents: result });

        return result;
    }

    /**
     * Claude Code, Cursor Agent and Copilot CLI are installed for this website's user when the
     * editor opens, one after the other in the background (a sign-in stays the person's step).
     */
    async installMissing() {
        for (const id of Object.keys(AGENTS)) {
            if (!(await AGENTS[id].binary())) {
                await this.install(id, { quiet: true });
            }
        }
    }

    async install(agentId, { quiet = false } = {}) {
        const agent = AGENTS[agentId];
        this.post({ type: 'installing', agent: agentId, state: true });

        const result = await vscode.window.withProgress(
            { location: quiet ? vscode.ProgressLocation.Window : vscode.ProgressLocation.Notification, title: 'Installing ' + agent.product + '…' },
            () => run(agent.install, { timeout: 600000, cwd: this.root() }),
        );

        this.post({ type: 'installing', agent: agentId, state: false });

        if (!result.ok) {
            vscode.window.showErrorMessage('Could not install ' + agent.product + ': ' + result.out.trim().split('\n').slice(-2).join(' '));
        }

        await this.refreshAgents();

        return result.ok;
    }

    async startLogin(agentId) {
        if (this.login) {
            this.login.cancel();
        }

        let bin = await this.binary(agentId);

        if (!bin && (await this.install(agentId))) {
            bin = await this.binary(agentId);
        }

        if (!bin) {
            return;
        }

        this.login = new Login(agentId, AGENTS[agentId].login(bin), this.root(), async (state) => {
            this.post({ type: 'login', ...state });

            if (state.state === 'done' || state.state === 'error') {
                this.login = null;
                await this.refreshAgents();
            }
        });
    }

    async send(message) {
        if (this.busy || !this.chat) {
            return;
        }

        const agent = AGENTS[message.agent];
        const bin = await this.binary(message.agent);

        if (!bin) {
            this.post({ type: 'needLogin', agent: message.agent, reason: agent.product + ' is not installed yet.' });

            return;
        }

        this.busy = true;
        this.post({ type: 'busy', busy: true });

        try {
            await this.ensureSessions().send(this.chat, message, bin, (event) => this.post(event));
        } finally {
            this.busy = false;
            this.post({ type: 'busy', busy: false });
            this.post({ type: 'chats', chats: this.sessions.list(), chat: this.chat });
        }
    }

    sendActiveFile() {
        const editor = vscode.window.activeTextEditor;
        const root = this.root();
        const file = editor && root && editor.document.uri.scheme !== 'output' ? path.relative(root, editor.document.uri.fsPath) : null;
        this.post({ type: 'activeFile', path: file && !file.startsWith('..') ? file : null });
    }

    html(webview, media) {
        const nonce = Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
        const asset = (name) => webview.asWebviewUri(vscode.Uri.joinPath(media, name));
        const agents = Object.fromEntries(Object.values(AGENTS).map((a) => [a.id, { id: a.id, label: a.label, product: a.product, color: a.color, mark: a.mark, account: a.account, efforts: a.efforts }]));

        return `<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">
<meta http-equiv="Content-Security-Policy" content="default-src 'none'; img-src ${webview.cspSource} data:; style-src ${webview.cspSource} 'unsafe-inline'; font-src ${webview.cspSource}; script-src 'nonce-${nonce}';">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="${asset('chat.css')}">
</head><body>
<div id="app"></div>
<script nonce="${nonce}">window.AGENTS = ${JSON.stringify(agents)};</script>
<script nonce="${nonce}" src="${asset('chat.js')}"></script>
</body></html>`;
    }
}

function activate(context) {
    const view = new ChatView(context);

    context.subscriptions.push(
        vscode.window.registerWebviewViewProvider('laravelifyAi.agents', view, { webviewOptions: { retainContextWhenHidden: true } }),
        vscode.window.onDidChangeActiveTextEditor(() => view.sendActiveFile()),
        vscode.commands.registerCommand('laravelifyAi.newChat', () => view.receive({ type: 'newChat' })),
        vscode.commands.registerCommand('laravelifyAi.addFile', (uri) => {
            if (uri && view.root()) {
                view.post({ type: 'attach', path: path.relative(view.root(), uri.fsPath) });
                vscode.commands.executeCommand('laravelifyAi.agents.focus');
            }
        }),
        vscode.commands.registerCommand('laravelifyAi.login', async () => {
            const pick = await vscode.window.showQuickPick(Object.values(AGENTS).map((a) => ({ label: a.product, id: a.id })), { placeHolder: 'Sign in to…' });

            if (pick) {
                await vscode.commands.executeCommand('laravelifyAi.agents.focus');
                view.receive({ type: 'login', agent: pick.id });
            }
        }),
    );

    // Selected code → the chat (Ctrl+L), or straight to an edit request (Ctrl+I), like Cursor.
    const selectionSnippet = () => {
        const editor = vscode.window.activeTextEditor;

        if (!editor || editor.selection.isEmpty || !view.root()) return null;

        const { start, end } = editor.selection;
        const last = end.character === 0 && end.line > start.line ? end.line - 1 : end.line;

        return {
            kind: 'selection',
            path: path.relative(view.root(), editor.document.uri.fsPath),
            start: start.line + 1,
            end: last + 1,
            lang: editor.document.languageId,
            text: editor.document.getText(editor.selection),
        };
    };
    const toChat = async (message) => {
        await vscode.commands.executeCommand('laravelifyAi.agents.focus');
        view.postWhenReady(message);
    };

    context.subscriptions.push(
        vscode.commands.registerCommand('laravelifyAi.addSelection', async () => {
            const snippet = selectionSnippet();
            const editor = vscode.window.activeTextEditor;

            if (snippet) {
                await toChat({ type: 'snippet', snippet });
            } else if (editor && view.root()) {
                await toChat({ type: 'attach', path: path.relative(view.root(), editor.document.uri.fsPath) });
            }
        }),
        vscode.commands.registerCommand('laravelifyAi.quickEdit', async () => {
            const snippet = selectionSnippet();

            if (!snippet) return;

            const instruction = await vscode.window.showInputBox({
                title: `Quick Edit · ${snippet.path.split('/').pop()} (${snippet.start}-${snippet.end})`,
                prompt: 'What should change in the selected code? The agent you picked in the chat makes the edit.',
                placeHolder: 'e.g. make sub_id a uuid and add an index on category_id',
            });

            if (instruction && instruction.trim()) {
                await toChat({ type: 'quickEdit', snippet, instruction: `Edit only the selected lines (${snippet.path}, lines ${snippet.start}-${snippet.end}): ${instruction.trim()}` });
            }
        }),
        vscode.languages.registerCodeActionsProvider({ scheme: 'file' }, {
            provideCodeActions(document, range) {
                if (range.isEmpty) return [];

                const add = new vscode.CodeAction('Add to Chat (Ctrl+L)', vscode.CodeActionKind.Empty);
                add.command = { command: 'laravelifyAi.addSelection', title: 'Add to Chat' };
                const edit = new vscode.CodeAction('Quick Edit with AI (Ctrl+I)', vscode.CodeActionKind.Empty);
                edit.command = { command: 'laravelifyAi.quickEdit', title: 'Quick Edit' };

                return [add, edit];
            },
        }),
    );

    // Clickable "Add to Chat · Quick Edit" just above a selection (a CodeLens: an extension cannot
    // draw a floating widget in the editor). It shows once the selection settles.
    let lensAt = null;
    const lensChanged = new vscode.EventEmitter();
    let lensTimer = null;
    context.subscriptions.push(
        lensChanged,
        vscode.languages.registerCodeLensProvider({ scheme: 'file' }, {
            onDidChangeCodeLenses: lensChanged.event,
            provideCodeLenses(document) {
                if (!lensAt || lensAt.uri !== document.uri.toString()) return [];

                const range = new vscode.Range(lensAt.line, 0, lensAt.line, 0);

                return [
                    new vscode.CodeLens(range, { title: '$(comment-discussion) Add to Chat (Ctrl+L)', command: 'laravelifyAi.addSelection' }),
                    new vscode.CodeLens(range, { title: '$(sparkle) Quick Edit (Ctrl+I)', command: 'laravelifyAi.quickEdit' }),
                ];
            },
        }),
        vscode.window.onDidChangeTextEditorSelection(({ textEditor, selections }) => {
            clearTimeout(lensTimer);
            const selection = selections[0];
            const next = selections.length === 1 && !selection.isEmpty && textEditor.document.uri.scheme === 'file'
                ? { uri: textEditor.document.uri.toString(), line: selection.start.line }
                : null;

            if (!next && lensAt) {
                lensAt = null;
                lensChanged.fire();
            } else if (next && (!lensAt || lensAt.uri !== next.uri || lensAt.line !== next.line)) {
                lensTimer = setTimeout(() => {
                    lensAt = next;
                    lensChanged.fire();
                }, 350);
            }
        }),
    );

    // Go Live: the project's dev server on a random public address while it runs.
    if (view.root()) {
        live = new LiveServer(view.root(), context.extensionPath);
        context.subscriptions.push(live, vscode.commands.registerCommand('laravelifyAi.live', () => live.click()));
    }

    const item = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Right, 100);
    item.text = '$(sparkle) AI Agents';
    item.tooltip = 'Chat with Claude, Cursor or Copilot';
    item.command = 'laravelifyAi.agents.focus';
    item.show();
    context.subscriptions.push(item);

    setTimeout(() => view.installMissing(), 3000);

    // The project map: built once when it is missing, then after every change the agents make.
    setTimeout(() => {
        const sessions = view.ensureSessions();

        if (sessions && !require('fs').existsSync(path.join(view.root(), 'graphify-out', 'graph.json'))) {
            sessions.refreshGraph((event) => view.post(event));
        }
    }, 5000);

    // Saved edits also refresh the map (a few seconds after the last save).
    let saveTimer = null;
    context.subscriptions.push(vscode.workspace.onDidSaveTextDocument((document) => {
        if (!/graphify-out|\.laravelify/.test(document.uri.fsPath)) {
            clearTimeout(saveTimer);
            saveTimer = setTimeout(() => view.ensureSessions() && view.sessions.refreshGraph((event) => view.post(event)), 8000);
        }
    }));
}

// VS Code closing (or stopping by itself when idle) takes the Live Server's address away too.
function deactivate() {
    return live && live.state !== 'off' ? live.stop() : undefined;
}

module.exports = { activate, deactivate };
