/*
 * A live console (xterm.js, MIT) for the panel's terminals and deployment logs. Loaded only on pages
 * that show one.
 *
 * Terminal: the command runs on the server with a real terminal; this reads its output by offset
 * and sends what is typed (answers to "Proceed? (Y/n)", Ctrl+C) back. Deployment log: the log
 * is streamed in read-only, so it scrolls like a console and never jumps.
 */
import { Terminal } from '@xterm/xterm';
import { FitAddon } from '@xterm/addon-fit';
import '@xterm/xterm/css/xterm.css';

const THEME = {
    background: '#0c0e12',
    foreground: '#d7dae0',
    cursor: '#7aa2f7',
    selectionBackground: '#33467c88',
    black: '#15161e',
    red: '#f7768e',
    green: '#9ece6a',
    yellow: '#e0af68',
    blue: '#7aa2f7',
    magenta: '#bb9af7',
    cyan: '#7dcfff',
    white: '#a9b1d6',
    brightBlack: '#414868',
    brightRed: '#f7768e',
    brightGreen: '#9ece6a',
    brightYellow: '#e0af68',
    brightBlue: '#7aa2f7',
    brightMagenta: '#bb9af7',
    brightCyan: '#7dcfff',
    brightWhite: '#c0caf5',
};

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function bytes(base64) {
    const binary = atob(base64);
    const out = new Uint8Array(binary.length);

    for (let i = 0; i < binary.length; i++) {
        out[i] = binary.charCodeAt(i);
    }

    return out;
}

/**
 * @param {HTMLElement} host
 * @param {{interactive?: boolean, eol?: boolean, csrf?: string, onResize?: (cols: number, rows: number) => void}} options
 */
export function createTerminal(host, options = {}) {
    const term = new Terminal({
        fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace',
        fontSize: 13,
        lineHeight: 1.25,
        cursorBlink: !!options.interactive,
        cursorStyle: 'bar',
        disableStdin: !options.interactive,
        scrollback: 20000,
        convertEol: !!options.eol,
        allowProposedApi: true,
        theme: THEME,
    });
    const fit = new FitAddon();
    term.loadAddon(fit);
    term.open(host);

    const refit = () => {
        try {
            fit.fit();

            if (options.onResize) {
                options.onResize(term.cols, term.rows);
            }
        } catch (e) {}
    };
    refit();
    const observer = new ResizeObserver(() => refit());
    observer.observe(host);

    let run = 0;
    let session = null;
    let queue = '';
    let sending = false;

    const headers = () => ({ 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': options.csrf || '', 'X-Requested-With': 'XMLHttpRequest' });

    const flush = async () => {
        if (sending || !session || queue === '') {
            return;
        }

        sending = true;

        while (queue !== '' && session) {
            const data = queue;
            queue = '';

            try {
                await fetch(session.urls.input, { method: 'POST', headers: headers(), body: JSON.stringify({ data }) });
            } catch (e) {}
        }

        sending = false;
    };

    if (options.interactive) {
        term.onData((data) => {
            if (session && session.running) {
                queue += data;
                setTimeout(flush, 15);
            }
        });
    }

    const handle = {
        term,

        reset() {
            run++;
            session = null;
            queue = '';
            term.reset();
        },

        focus() {
            term.focus();
        },

        refit,

        /** Everything on the screen and above it, or just the selection, as plain text. */
        text() {
            if (term.hasSelection()) {
                return term.getSelection();
            }

            const buffer = term.buffer.active;
            const lines = [];

            for (let i = 0; i < buffer.length; i++) {
                lines.push(buffer.getLine(i).translateToString(true));
            }

            return lines.join('\n').replace(/\s+$/, '');
        },

        /** Follow a command running on the server until it ends. */
        async attachSession(urls, onState) {
            const mine = ++run;
            session = { urls, running: true };
            let offset = 0;
            let delay = 80;
            onState({ running: true, exit: null });
            term.focus();

            while (run === mine) {
                let answer = null;

                try {
                    const response = await fetch(urls.output + '?offset=' + offset, { headers: { Accept: 'application/json' }, cache: 'no-store' });

                    if (response.status === 404) {
                        break;
                    }

                    answer = response.ok ? await response.json() : null;
                } catch (e) {
                    answer = null;
                }

                if (run !== mine) {
                    return;
                }

                if (answer) {
                    if (answer.data) {
                        term.write(bytes(answer.data));
                        delay = 40;
                    } else {
                        delay = Math.min(delay + 40, 400);
                    }

                    offset = answer.offset;

                    if (answer.done) {
                        session.running = false;
                        onState({ running: false, exit: answer.exit });

                        return;
                    }
                } else {
                    delay = Math.min(delay * 2, 2000);
                }

                await wait(delay);
            }

            if (run === mine) {
                session.running = false;
                onState({ running: false, exit: null });
            }
        },

        stop() {
            if (session && session.running) {
                fetch(session.urls.stop, { method: 'POST', headers: headers(), body: '{}' }).catch(() => {});
            }
        },

        /** Stream a text log (a deployment's) until it stops growing and its job is over. */
        async attachLog(url, onState) {
            const mine = ++run;
            let offset = 0;
            let delay = 600;
            onState({ running: true, exit: null });

            while (run === mine) {
                let answer = null;

                try {
                    const response = await fetch(url + '?offset=' + offset, { headers: { Accept: 'application/json' }, cache: 'no-store' });
                    answer = response.ok ? await response.json() : null;
                } catch (e) {
                    answer = null;
                }

                if (run !== mine) {
                    return;
                }

                if (answer) {
                    if (answer.reset) {
                        term.reset();
                    }

                    if (answer.text) {
                        term.write(answer.text);
                        delay = 400;
                    } else {
                        delay = Math.min(delay + 200, 2500);
                    }

                    offset = answer.offset;
                    onState({ running: !!answer.live, status: answer.status });

                    if (!answer.live) {
                        return;
                    }
                }

                await wait(delay);
            }
        },

        dispose() {
            run++;
            session = null;
            observer.disconnect();
            term.dispose();
        },
    };

    return handle;
}
