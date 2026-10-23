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
 * Feedback for every action: Livewire marks the element that started a request with
 * data-loading (a button, or the form for a submit). The button gets a spinner (CSS), a
 * submit button outside its form (form="id") or the one that was clicked is marked too, and a
 * thin bar runs at the top of the page while any request takes longer than a moment.
 */
(() => {
    const bar = document.createElement('div');
    bar.className = 'page-progress';
    let showTimer = null;

    const buttonsOf = (form) => {
        const buttons = [...form.querySelectorAll('button[type="submit"], button:not([type])')];

        if (form.id) {
            buttons.push(...document.querySelectorAll('button[form="' + CSS.escape(form.id) + '"]'));
        }

        return buttons;
    };

    let lastSubmitter = null;
    document.addEventListener('submit', (event) => {
        lastSubmitter = event.submitter || null;
    }, true);

    // Background refreshes (wire:poll, wire:init) are not something the person did: no bar for them.
    const background = (element) => [...element.attributes].some((attribute) => attribute.name.startsWith('wire:poll') || attribute.name === 'wire:init');

    const update = () => {
        const busy = [...document.querySelectorAll('[data-loading]')].filter((element) => !background(element));

        document.querySelectorAll('.is-busy').forEach((button) => {
            const form = button.form;

            if (!button.hasAttribute('data-loading') && !(form && form.hasAttribute('data-loading'))) {
                button.classList.remove('is-busy');
            }
        });

        busy.forEach((element) => {
            if (element.tagName === 'FORM') {
                const buttons = buttonsOf(element);
                // The button that was clicked, or the form's only submit button.
                const target = buttons.includes(lastSubmitter) ? lastSubmitter : buttons.length === 1 ? buttons[0] : null;
                target?.classList.add('is-busy');
            } else if (element.matches('button, .btn, a.dropdown-option, a.app-tab')) {
                element.classList.add('is-busy');
            }
        });

        if (busy.length > 0) {
            if (!bar.isConnected) {
                document.body.appendChild(bar);
            }

            showTimer ??= setTimeout(() => bar.classList.add('is-active'), 250);
        } else {
            clearTimeout(showTimer);
            showTimer = null;
            bar.classList.remove('is-active');
        }
    };

    new MutationObserver(update).observe(document.documentElement, { subtree: true, attributes: true, attributeFilter: ['data-loading'] });
})();

/*
 * "Add website" keeps a draft in the browser as you type (every field, the repository loaded and
 * the list it came from). After a reload it offers the draft back, or a fresh form. A draft older
 * than a week is dropped; the page that follows a created website removes it.
 */
window.formDraft = function (key, fields) {
    const read = () => {
        try {
            const draft = JSON.parse(localStorage.getItem(key) || 'null');

            return draft && Date.now() - draft.at < 7 * 86400000 ? draft : null;
        } catch (e) {
            return null;
        }
    };

    return {
        offer: false,
        draft: null,
        savedAt: null,
        timer: null,
        last: null,

        init() {
            this.draft = read();
            this.offer = !!(this.draft && this.meaningful(this.draft.values));
            this.last = JSON.stringify(this.snapshot());
            // Saving starts only once the draft question is answered, so an old draft is never overwritten by an empty form.
            this.timer = setInterval(() => this.save(), 1500);
        },

        destroy() {
            clearInterval(this.timer);
        },

        snapshot() {
            const values = {};
            fields.forEach((field) => (values[field] = this.$wire[field]));

            return values;
        },

        /** A draft worth offering: something was typed or a repository was loaded. */
        meaningful(values) {
            return ['name', 'domain', 'repository', 'extra_domains', 'http_port', 'new_repository_name', 'install_command', 'build_command', 'start_command'].some((field) => String(values[field] || '').trim() !== '');
        },

        save() {
            if (this.offer) {
                return;
            }

            const values = this.snapshot();
            const json = JSON.stringify(values);

            if (json === this.last) {
                return;
            }

            this.last = json;

            try {
                if (this.meaningful(values)) {
                    localStorage.setItem(key, JSON.stringify({ at: Date.now(), values }));
                    this.savedAt = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                } else {
                    localStorage.removeItem(key);
                    this.savedAt = null;
                }
            } catch (e) {}
        },

        async restore() {
            this.offer = false;
            await this.$wire.restoreDraft(this.draft.values);
            this.last = JSON.stringify(this.snapshot());
        },

        discard() {
            this.offer = false;

            try {
                localStorage.removeItem(key);
            } catch (e) {}
        },

        summary() {
            if (!this.draft) {
                return '';
            }

            const values = this.draft.values;
            const parts = [values.name, values.repository ? values.repository.replace(/^https:\/\/github\.com\//, '').replace(/\.git$/, '') : '', values.domain || (values.http_port ? 'port ' + values.http_port : '')].filter(Boolean);
            const minutes = Math.round((Date.now() - this.draft.at) / 60000);
            const when = minutes < 1 ? 'just now' : minutes < 60 ? minutes + ' min ago' : minutes < 1440 ? Math.round(minutes / 60) + ' h ago' : Math.round(minutes / 1440) + ' d ago';

            return ' — ' + (parts.join(' · ') || 'some fields') + ', saved ' + when + '.';
        },
    };
};

/*
 * "Add website": the repository list (search, keys, details), and the domain helper that shows
 * the Cloudflare zones and the names already used in them.
 */
window.repoPicker = function (repos) {
    const colors = { PHP: '#4F5D95', JavaScript: '#f1e05a', TypeScript: '#3178c6', Vue: '#41b883', HTML: '#e34c26', CSS: '#563d7c', Blade: '#f7523f', Svelte: '#ff3e00', Python: '#3572A5', Go: '#00ADD8' };

    return {
        repos,
        q: '',
        index: 0,

        results() {
            const words = this.q.toLowerCase().split(/\s+/).filter(Boolean);

            return this.repos.filter((repo) => {
                const text = (repo.name + ' ' + (repo.language || '') + ' ' + (repo.description || '')).toLowerCase();

                return words.every((word) => text.includes(word));
            });
        },

        pick(repo) {
            if (repo) {
                this.$wire.selectedRepository = repo.clone_url;
                this.$wire.loadRepository();
            }
        },

        keys(event) {
            const results = this.results();

            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                this.index = Math.max(0, Math.min(results.length - 1, this.index + (event.key === 'ArrowDown' ? 1 : -1)));
                this.$nextTick(() => this.$refs.list.querySelectorAll('.repo-row')[this.index]?.scrollIntoView({ block: 'nearest' }));
            } else if (event.key === 'Enter') {
                event.preventDefault();
                this.pick(results[this.index]);
            }
        },

        color(language) {
            return colors[language] || '#8b949e';
        },

        ago(date) {
            const seconds = (Date.now() - new Date(date).getTime()) / 1000;

            if (!isFinite(seconds)) {
                return '';
            }

            const units = [[31536000, 'y'], [2592000, 'mo'], [86400, 'd'], [3600, 'h'], [60, 'm']];
            const unit = units.find(([size]) => seconds >= size);

            return unit ? Math.floor(seconds / unit[0]) + unit[1] + ' ago' : 'just now';
        },
    };
};

window.domainPicker = function () {
    return {
        zones: [],
        loading: false,
        error: null,
        suggest: false,
        taken: false,
        value: '',

        init() {
            // A restored draft or a random subdomain changes the domain from the server side.
            this.$watch('$wire.domain', (domain) => (this.value = domain || ''));
        },

        async load(fresh = false) {
            this.value = this.$wire.domain || '';
            this.loading = true;

            try {
                const options = await this.$wire.domainOptions(fresh);
                this.zones = options.zones || [];
                this.error = options.error;
            } finally {
                this.loading = false;
            }
        },

        records() {
            return this.zones.flatMap((zone) => zone.records);
        },

        total() {
            return this.records().length;
        },

        matches() {
            const query = this.value.trim().toLowerCase();

            return query === '' ? [] : this.records().filter((record) => record.name.includes(query)).slice(0, 12);
        },

        state() {
            const name = this.value.trim().toLowerCase().replace(/\.$/, '');
            const record = this.records().find((entry) => entry.name === name);

            if (record && record.website) {
                return { kind: 'site', icon: '✕', text: 'Used by the website ' + record.website + '. Add it there under Domains instead, or pick another name.' };
            }

            if (record) {
                return { kind: 'dns', icon: '!', text: 'Already has a ' + record.type + ' record (→ ' + record.content + ') in Cloudflare. Saving points it at this website.' };
            }

            if (this.zones.length && !this.zones.some((zone) => name === zone.name || name.endsWith('.' + zone.name))) {
                return { kind: 'unknown', icon: '?', text: 'Not one of your Cloudflare domains (' + this.zones.map((zone) => zone.name).join(', ') + '). Set its DNS yourself.' };
            }

            return { kind: 'free', icon: '✓', text: this.zones.length ? 'Free: no record with this name yet.' : 'Point this name at the server, or use a Cloudflare tunnel.' };
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
