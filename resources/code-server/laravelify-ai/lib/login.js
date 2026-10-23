// Signing in: each CLI's login runs in a terminal of its own (the browser cannot be opened from the
// server), and what it prints is read for the sign-in link, a device code, or a request to paste
// a code back. The chat shows those in a dialog with copy buttons.
const { pty } = require('./shell');

const strip = (text) => text
    // OSC 8 hyperlinks: keep the visible part only.
    .replace(/\x1b\]8;[^\x07\x1b]*(\x07|\x1b\\)/g, '')
    .replace(/\]8;;[^\s\]]*/g, '')
    .replace(/\x1b\[[0-9;?]*[A-Za-z]/g, '')
    .replace(/\x1b[()][A-Z0-9]/g, '')
    .replace(/\r/g, '');

class Login {
    constructor(agent, command, cwd, update) {
        this.agent = agent;
        this.update = update;
        this.output = '';
        this.state = { agent, state: 'starting', url: null, code: null, needsInput: false, message: null };
        this.child = pty(command, {
            cwd,
            onData: (data) => this.read(data),
            onExit: (code) => {
                this.child = null;
                this.publish({ state: code === 0 ? 'done' : this.state.state === 'cancelled' ? 'cancelled' : 'error', message: code === 0 ? null : this.tail() });
            },
        });
    }

    read(data) {
        this.output += strip(data);
        const changes = {};
        // The longest https link printed (the sign-in page; some CLIs print a short one first).
        const urls = (this.output.match(/https:\/\/[^\s"'<>\x07]+/g) || []).map((url) => url.replace(/[).,]+$/, ''));
        const url = urls.sort((a, b) => b.length - a.length)[0];

        if (url && url !== this.state.url) {
            changes.url = url;
            changes.state = 'waiting';
        }

        const code = (this.output.match(/(?:code|enter)[^A-Z0-9\n]{0,40}\b([A-Z0-9]{4}-[A-Z0-9]{4})\b/i) || [])[1];

        if (code && code !== this.state.code) {
            changes.code = code;
        }

        // Only a CLI that asks for a code back (Claude) gets the paste box; a device code shown to
        // type on the website (Copilot: "enter code ABCD-1234") does not.
        if (/paste (the )?(code|token)|paste code here/i.test(this.output) && !this.state.needsInput) {
            changes.needsInput = true;
        }

        if (Object.keys(changes).length) {
            this.publish(changes);
        }
    }

    /** The code the sign-in page showed (Claude), typed back into the CLI. */
    answer(text) {
        if (this.child) {
            this.child.stdin.write(String(text).trim() + '\r');
            this.publish({ needsInput: false, state: 'checking' });
        }
    }

    cancel() {
        if (this.child) {
            this.state.state = 'cancelled';
            this.child.kill('SIGTERM');
        }
    }

    tail() {
        return this.output.trim().split('\n').slice(-4).join('\n');
    }

    publish(changes) {
        Object.assign(this.state, changes);
        this.update({ ...this.state });
    }
}

module.exports = { Login };
