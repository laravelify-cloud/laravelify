/*
 * The Database tool's rows grid: an Alpine component that loads AG Grid (its own file) only when a
 * table page opens, and takes each page of rows the server renders.
 */
window.dbGrid = function () {
    return {
        update: null,
        pending: null,

        async init() {
            const source = document.querySelector('[data-db-grid-payload]');
            this.pending = source ? JSON.parse(source.dataset.dbGridPayload) : null;

            const { mountGrid } = await import('./db-grid');

            if (this.pending) {
                this.update = mountGrid(this.$refs.grid, this.$wire, this.pending);
                this.pending = null;
            }
        },

        receive(payload) {
            if (this.update) {
                this.update(payload);
            } else {
                this.pending = payload;
            }
        },
    };
};

/*
 * The live console (xterm.js, its own file, loaded when a console is on the page): a terminal that
 * follows a command running on the server and takes what is typed, or a deployment's log streamed in.
 */
window.liveTerminal = function (config) {
    const token = () => (document.querySelector('meta[name="csrf-token"]') || {}).content || '';

    return {
        handle: null,
        running: false,
        exit: null,
        status: null,
        queued: null,

        async init() {
            const { createTerminal } = await import('./terminal');

            this.handle = createTerminal(this.$refs.screen, {
                interactive: config.mode === 'terminal',
                eol: config.mode === 'log',
                csrf: token(),
                onResize: (columns, rows) => {
                    if (config.mode === 'terminal' && this.$wire) {
                        this.$wire.columns = columns;
                        this.$wire.rows = rows;
                    }
                },
            });

            if (config.mode === 'log') {
                this.handle.attachLog(config.url, (state) => this.state(state));
            } else if (config.mode === 'terminal') {
                this.handle.term.write('\x1b[90mOutput appears here. A command that asks a question can be answered right in this window.\x1b[0m\r\n');
            }
        },

        state(next) {
            this.running = !!next.running;
            this.exit = next.exit === undefined ? this.exit : next.exit;
            this.status = next.status === undefined ? this.status : next.status;
            this.$dispatch(config.mode === 'log' ? 'console-state' : 'terminal-state', { running: this.running, exit: this.exit, status: this.status });
        },

        startSession(detail) {
            if (!this.handle) {
                return;
            }

            const urls = {
                output: config.urls.output.replace('__ID__', detail.id),
                input: config.urls.input.replace('__ID__', detail.id),
                stop: config.urls.stop.replace('__ID__', detail.id),
            };

            this.handle.reset();
            this.handle.term.write('\x1b[36m$ ' + detail.command + '\x1b[0m\r\n');
            this.handle.attachSession(urls, (state) => this.state(state));
        },

        showText(detail) {
            if (!this.handle) {
                return;
            }

            this.handle.reset();
            this.handle.term.write('\x1b[36m$ ' + detail.command + '\x1b[0m\r\n' + String(detail.text || '').replace(/\r?\n/g, '\r\n') + '\r\n');
            this.state({ running: false, exit: detail.exit });
        },

        copy() {
            if (this.handle) {
                window.copyToClipboard(this.handle.text(), 'Output copied.');
            }
        },

        clear() {
            if (this.handle) {
                this.handle.term.clear();
            }
        },

        stop() {
            if (this.handle) {
                this.handle.stop();
            }
        },

        download() {
            if (!this.handle) {
                return;
            }

            const link = document.createElement('a');
            link.href = URL.createObjectURL(new Blob([this.handle.text() + '\n'], { type: 'text/plain' }));
            link.download = (config.name || 'console') + '.log';
            link.click();
            URL.revokeObjectURL(link.href);
        },

        destroy() {
            if (this.handle) {
                this.handle.dispose();
            }
        },
    };
};
