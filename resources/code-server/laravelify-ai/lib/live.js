// Live Server: run the project's dev server (npm run dev, php artisan serve, php -S...) in a
// terminal on a free port, then ask the panel to put it on a random public name through the
// Cloudflare tunnel. Stopping it ends the dev server and takes the name and its DNS record away.
const vscode = require('vscode');
const fs = require('fs');
const os = require('os');
const net = require('net');
const http = require('http');
const path = require('path');

/** The panel's endpoint, its host name, the key, and the port range (written by the panel). */
function settings() {
    try {
        return JSON.parse(fs.readFileSync(path.join(os.homedir(), '.laravelify', 'live.json'), 'utf8'));
    } catch (e) {
        return null;
    }
}

function portFree(port) {
    return new Promise((resolve) => {
        const server = net.createServer().once('error', () => resolve(false)).once('listening', () => server.close(() => resolve(true)));
        server.listen(port, '127.0.0.1');
    });
}

function listening(port) {
    return new Promise((resolve) => {
        const socket = net.connect(port, '127.0.0.1');
        socket.once('connect', () => { socket.destroy(); resolve(true); });
        socket.once('error', () => resolve(false));
    });
}

async function freePort([from, to]) {
    for (let tries = 0; tries < 50; tries++) {
        const port = from + Math.floor(Math.random() * (to - from + 1));

        if (await portFree(port)) return port;
    }

    throw new Error('No free port for the Live Server.');
}

/** The panel answers over the server's own address (the request never leaves the machine). */
function ask(config, action, body = {}) {
    return new Promise((resolve, reject) => {
        const url = new URL(config.endpoint + '/' + action);
        const data = JSON.stringify(body);
        const request = http.request({
            hostname: url.hostname, port: url.port || 80, path: url.pathname, method: 'POST', timeout: 60000,
            headers: { Host: config.host, Authorization: 'Bearer ' + config.key, 'Content-Type': 'application/json', Accept: 'application/json', 'Content-Length': Buffer.byteLength(data) },
        }, (response) => {
            let text = '';
            response.on('data', (chunk) => { text += chunk; });
            response.on('end', () => {
                let json = {};
                try { json = JSON.parse(text); } catch (e) {}
                json.ok ? resolve(json) : reject(new Error(json.error || 'The panel answered ' + response.statusCode + '.'));
            });
        });
        request.on('timeout', () => request.destroy(new Error('The panel did not answer.')));
        request.on('error', reject);
        request.end(data);
    });
}

const read = (root, file) => { try { return fs.readFileSync(path.join(root, file), 'utf8'); } catch (e) { return null; } };

/** The time of the last save, read by live-router.php (pages reload when it changes). */
const versionFile = () => path.join(os.homedir(), '.laravelify', 'live-version');

/**
 * How to run this project's dev server on 127.0.0.1:port: { label, command, reload } or null.
 * Node projects use their dev script (Next.js, Vite, Nuxt, Astro, SvelteKit get the port as a flag;
 * others get PORT), Laravel uses artisan serve, other PHP and static sites PHP's own server.
 * reload says how a saved file shows: 'hmr' (the framework updates the page itself), 'pages'
 * (live-router.php reloads every open page), or 'preview' (VS Code reloads its own preview).
 */
function devCommand(root, port, router = null) {
    const pkgText = read(root, 'package.json');
    const composer = read(root, 'composer.json') || '';
    const install = fs.existsSync(path.join(root, 'node_modules')) ? '' : 'npm install && ';

    if (pkgText && !/laravel\/framework/.test(composer)) {
        let pkg = {};
        try { pkg = JSON.parse(pkgText); } catch (e) {}
        const deps = { ...(pkg.dependencies || {}), ...(pkg.devDependencies || {}) };
        const scripts = pkg.scripts || {};
        const script = scripts.dev ? 'dev' : scripts.start ? 'start' : null;

        if (deps.next) return { label: 'Next.js', reload: 'hmr', command: `${install}npx next dev -p ${port} -H 127.0.0.1` };
        if (!script) return null;
        if (deps.nuxt || deps.astro || deps['@sveltejs/kit'] || deps.vite) {
            return { label: deps.nuxt ? 'Nuxt' : deps.astro ? 'Astro' : deps['@sveltejs/kit'] ? 'SvelteKit' : 'Vite', reload: 'hmr', command: `${install}npm run ${script} -- --port ${port} --host 127.0.0.1` };
        }

        return { label: 'Node.js', reload: 'preview', command: `${install}PORT=${port} HOST=127.0.0.1 npm run ${script}` };
    }

    if (/laravel\/framework/.test(composer) && fs.existsSync(path.join(root, 'artisan'))) {
        const vendor = fs.existsSync(path.join(root, 'vendor')) ? '' : 'composer install && ';
        const env = fs.existsSync(path.join(root, '.env')) || !fs.existsSync(path.join(root, '.env.example')) ? '' : 'cp .env.example .env && php artisan key:generate && ';

        return { label: 'Laravel', reload: 'preview', command: `${vendor}${env}php artisan serve --host=127.0.0.1 --port=${port}` };
    }

    const docroot = ['public', 'web', 'www'].find((dir) => fs.existsSync(path.join(root, dir))) || '.';

    if (composer || fs.readdirSync(root).some((file) => /\.(php|html?)$/.test(file)) || docroot !== '.') {
        const routed = router ? ` '${router}'` : '';

        return { label: composer || fs.existsSync(path.join(root, docroot, 'index.php')) ? 'PHP' : 'Static site', reload: router ? 'pages' : 'preview', command: `LIVE_VERSION_FILE='${versionFile()}' php -S 127.0.0.1:${port} -t ${docroot}${routed}` };
    }

    return null;
}

class LiveServer {
    constructor(root, extensionPath) {
        this.root = root;
        this.router = path.join(extensionPath, 'lib', 'live-router.php');
        this.dev = null;
        this.saveTimer = null;
        this.state = 'off'; // off | starting | live
        this.url = null;
        this.port = null;
        this.terminal = null;
        this.item = vscode.window.createStatusBarItem(vscode.StatusBarAlignment.Right, 101);
        this.item.command = 'laravelifyAi.live';
        this.render();
        this.item.show();

        // A saved file shows on the live site by itself (see devCommand's reload).
        this.saved = vscode.workspace.onDidSaveTextDocument((document) => {
            if (this.state === 'live' && document.uri.fsPath.startsWith(this.root)) {
                clearTimeout(this.saveTimer);
                this.saveTimer = setTimeout(() => this.refresh(), 300);
            }
        });

        this.closed = vscode.window.onDidCloseTerminal((terminal) => {
            if (terminal === this.terminal) {
                this.terminal = null;
                this.stop();
            }
        });
    }

    render(detail) {
        const icons = { off: '$(broadcast)', starting: '$(loading~spin)', live: '$(circle-filled)' };
        this.item.text = `${icons[this.state]} ${this.state === 'live' ? 'Live: ' + new URL(this.url).host : this.state === 'starting' ? (detail || 'Going live…') : 'Go Live'}`;
        this.item.tooltip = this.state === 'live'
            ? `Live Server: ${this.url}\nThe dev server on port ${this.port}. Click to open, copy, or stop it.`
            : 'Run the dev server (npm run dev, php artisan serve, php -S…) and show it on a random public address';
        this.item.backgroundColor = this.state === 'live' ? new vscode.ThemeColor('statusBarItem.warningBackground') : undefined;
    }

    /** The status bar button: start, or the actions of a running Live Server. */
    async click() {
        if (this.state === 'starting') return;
        if (this.state === 'off') return this.start();

        const pick = await vscode.window.showQuickPick([
            { label: '$(globe) Open in VS Code', id: 'inside' },
            { label: '$(link-external) Open in the browser', id: 'browser' },
            { label: '$(copy) Copy the link', id: 'copy', description: this.url },
            { label: '$(terminal) Show the dev server', id: 'terminal' },
            { label: '$(debug-stop) Stop the Live Server', id: 'stop', description: 'removes the address and its DNS record' },
        ], { placeHolder: 'Live Server · ' + this.url });

        if (!pick) return;
        if (pick.id === 'inside') vscode.commands.executeCommand('simpleBrowser.show', this.url);
        if (pick.id === 'browser') vscode.env.openExternal(vscode.Uri.parse(this.url));
        if (pick.id === 'copy') { await vscode.env.clipboard.writeText(this.url); vscode.window.setStatusBarMessage('$(check) Live Server link copied', 3000); }
        if (pick.id === 'terminal' && this.terminal) this.terminal.show();
        if (pick.id === 'stop') this.stop();
    }

    async start() {
        const config = settings();

        if (!config) {
            return vscode.window.showErrorMessage('The Live Server is not set up for this editor yet. Open VS Code again from the panel.');
        }

        try {
            this.port = await freePort(config.ports || [14000, 14999]);
        } catch (error) {
            return vscode.window.showErrorMessage(error.message);
        }

        const dev = devCommand(this.root, this.port, this.router);
        this.dev = dev;

        if (!dev) {
            return vscode.window.showErrorMessage('No dev server found: add a "dev" script to package.json, or an index.php / index.html.');
        }

        this.state = 'starting';
        this.render('Starting ' + dev.label + '…');
        this.terminal = vscode.window.createTerminal({ name: 'Live Server', cwd: this.root, env: { PORT: String(this.port), BROWSER: 'none' } });
        this.terminal.show(true);
        this.terminal.sendText(dev.command);

        // Installing packages the first time can take a few minutes.
        const started = Date.now();

        while (!(await listening(this.port))) {
            if (this.state !== 'starting') return;
            if (Date.now() - started > 5 * 60000) {
                this.fail('The dev server did not start on port ' + this.port + '. Check the Live Server terminal.');

                return;
            }

            await new Promise((resolve) => setTimeout(resolve, 1000));
        }

        this.render('Getting an address…');

        try {
            const answer = await ask(config, 'start', { port: this.port });
            this.url = answer.url;
            this.state = 'live';
            this.render();
            vscode.commands.executeCommand('simpleBrowser.show', this.url);
            const choice = await vscode.window.showInformationMessage(`Live Server: ${dev.label} is on ${this.url} (a new name can take a minute to answer).`, 'Open in the browser', 'Copy link');

            if (choice === 'Open in the browser') vscode.env.openExternal(vscode.Uri.parse(this.url));
            if (choice === 'Copy link') vscode.env.clipboard.writeText(this.url);
        } catch (error) {
            this.fail('Live Server: ' + error.message);
        }
    }

    /** After a save: tell the open pages (PHP), or reload VS Code's preview (Laravel, Node). */
    refresh() {
        if (!this.dev || this.dev.reload === 'hmr') return;

        if (this.dev.reload === 'pages') {
            try {
                fs.writeFileSync(versionFile(), String(Date.now()));
            } catch (e) {}

            return;
        }

        vscode.commands.executeCommand('simpleBrowser.show', this.url + (this.url.includes('?') ? '&' : '?') + '_reload=' + Date.now());
    }

    fail(message) {
        vscode.window.showErrorMessage(message);
        const terminal = this.terminal;
        this.terminal = null;
        this.state = 'off';
        this.render();
        if (terminal) terminal.dispose();
    }

    async stop() {
        const wasLive = this.state === 'live';
        const terminal = this.terminal;
        this.terminal = null;
        this.state = 'off';
        this.url = null;
        this.render();

        if (terminal) terminal.dispose();

        const config = settings();

        if (config) {
            try {
                await ask(config, 'stop');
                if (wasLive) vscode.window.setStatusBarMessage('$(check) Live Server stopped; its address is removed', 4000);
            } catch (error) {
                vscode.window.showWarningMessage('The Live Server stopped, but its address could not be removed yet: ' + error.message);
            }
        }
    }

    dispose() {
        this.saved.dispose();
        this.closed.dispose();
        this.item.dispose();
    }
}

module.exports = { LiveServer, devCommand };
