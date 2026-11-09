/*
 * The Database tool's rows grid: an Alpine component that loads AG Grid (its own file) only when a
 * table page opens, and takes each page of rows the server renders.
 */
/** The SQL editor with autocomplete (CodeMirror, its own file, loaded only with an SQL box). */
window.sqlEditor = function (dialect, placeholder) {
    return {
        editor: null,

        async init() {
            const { mountSqlEditor } = await import('./sql-editor');
            this.editor = mountSqlEditor(this.$refs.host, this.$refs.source, { dialect, placeholder, onRun: () => this.runNow() });
            this.loadSchema();
        },

        async loadSchema() {
            try {
                const tables = await this.$wire.sqlSchema();
                this.editor?.setSchema(tables);
            } catch (e) {}
        },

        /** From the history: put the SQL in the editor, and run it when asked. */
        setSql(detail) {
            if (!this.editor) return;
            this.editor.setValue(detail.sql || '');
            this.$el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (detail.run) this.runNow();
        },

        runNow() {
            if (this.editor) {
                this.run(this.editor.selection(), this.editor.value());
            }
        },

        destroy() {
            this.editor?.destroy();
        },
    };
};

/** A list already in the page (databases, tables, an SQL result) in the rows grid: filters, sorting, search, pages. */
window.localGrid = function (spec) {
    return {
        api: null,

        async init() {
            const { mountLocalGrid } = await import('./db-grid');
            this.api = mountLocalGrid(this.$refs.grid, spec, this.$wire, this.$refs.search);
        },

        destroy() {
            this.api?.destroy();
        },
    };
};

/**
 * Import a .sql or .sql.gz dump into a database: the file goes up in 8 MB pieces (so files of many
 * GB pass any upload limit, and a dropped piece is sent again), then the panel imports it in the
 * background and the page shows its progress.
 */
window.sqlImport = function (config) {
    const piece = 8 * 1024 * 1024;

    return {
        open: false,
        file: null,
        reset: false,
        sent: 0,
        uploading: false,
        error: '',

        get percent() {
            return this.file ? Math.floor((this.sent / this.file.size) * 100) : 0;
        },

        get sizeLabel() {
            const size = (bytes) => (bytes >= 1073741824 ? (bytes / 1073741824).toFixed(2) + ' GB' : (bytes / 1048576).toFixed(1) + ' MB');

            return this.file ? size(this.sent) + ' of ' + size(this.file.size) : '';
        },

        pick(event) {
            const file = event.target.files[0] || null;
            this.error = '';

            if (file && !/\.(sql|sql\.gz|gz)$/i.test(file.name)) {
                this.error = 'Choose a .sql or .sql.gz file.';
                this.file = null;

                return;
            }

            this.file = file;
        },

        async post(url, body) {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '', Accept: 'application/json' },
                body,
                credentials: 'same-origin',
            });
            const data = await response.json().catch(() => ({}));

            if (!response.ok) {
                throw Object.assign(new Error(data.message || 'The server answered ' + response.status + '.'), { data, status: response.status });
            }

            return data;
        },

        async start() {
            if (!this.file || this.uploading) return;

            if (this.reset && !window.confirm('Empty ' + config.database + ' first? Every table in it is deleted before the import.')) return;

            const upload = Array.from(crypto.getRandomValues(new Uint8Array(16)), (byte) => byte.toString(16).padStart(2, '0')).join('');
            this.uploading = true;
            this.error = '';
            this.sent = 0;

            try {
                while (this.sent < this.file.size) {
                    const form = new FormData();
                    form.append('upload', upload);
                    form.append('name', this.file.name);
                    form.append('total', this.file.size);
                    form.append('offset', this.sent);
                    form.append('chunk', this.file.slice(this.sent, this.sent + piece), 'piece');

                    let tries = 0;

                    for (;;) {
                        try {
                            this.sent = (await this.post(config.chunkUrl, form)).received;
                            break;
                        } catch (error) {
                            // A dropped connection: try the same piece again a few times.
                            if (error.status || ++tries >= 4) throw error;
                            await new Promise((resolve) => setTimeout(resolve, 1500 * tries));
                        }
                    }
                }

                const form = new FormData();
                form.append('upload', upload);
                form.append('name', this.file.name);
                form.append('total', this.file.size);
                form.append('reset', this.reset ? '1' : '0');
                await this.post(config.startUrl, form);

                this.open = false;
                this.file = null;
                this.$refs.file && (this.$refs.file.value = '');
                this.$wire.$refresh();
            } catch (error) {
                this.error = error.message;
            } finally {
                this.uploading = false;
            }
        },
    };
};

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

    // An element removed while it loads (a popup that closes on click) never loses data-loading:
    // check again whenever a request ends.
    document.addEventListener('livewire:init', () => {
        window.Livewire.hook('request', ({ succeed, fail }) => {
            succeed(() => setTimeout(update));
            fail(() => setTimeout(update));
        });
    });
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

    /** The most of a terminal's transcript kept in the browser, in characters. */
    const TRANSCRIPT_LIMIT = 300000;
    const HINT = '\x1b[90mOutput appears here and stays: earlier commands and their output are kept above, scroll up to see them. A command that asks a question can be answered right in this window.\x1b[0m\r\n';

    return {
        handle: null,
        running: false,
        exit: null,
        status: null,
        queued: null,
        transcript: '',
        decoder: null,
        saveTimer: null,
        startedAt: null,
        showingHint: false,

        async init() {
            const { createTerminal } = await import('./terminal');

            this.handle = createTerminal(this.$refs.screen, {
                interactive: config.mode === 'terminal',
                eol: config.mode === 'log',
                csrf: token(),
                onWrite: config.storageKey ? (data) => this.record(data) : null,
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
                this.restore();
                window.addEventListener('pagehide', () => this.saveTimer && this.save());
            }
        },

        /** Bring back what this terminal showed before the page was reloaded. */
        restore() {
            let saved = '';

            try {
                saved = config.storageKey ? localStorage.getItem(config.storageKey) || '' : '';
            } catch (e) {}

            if (saved === '') {
                this.showingHint = true;
                this.handle.term.write(HINT);

                return;
            }

            this.transcript = saved;
            this.handle.term.write(saved);
            this.handle.term.write('\x1b[0m');

            if (this.handle.term.buffer.active.cursorX !== 0) {
                this.handle.term.write('\r\n');
            }

            this.handle.term.write('\x1b[90m── restored ' + new Date().toLocaleTimeString() + ' ──\x1b[0m\r\n');
        },

        /** Keep what was shown, so it can be shown again after a reload. */
        record(data) {
            if (typeof data !== 'string') {
                this.decoder = this.decoder || new TextDecoder();
                data = this.decoder.decode(data, { stream: true });
            }

            this.transcript += data;

            if (this.transcript.length > TRANSCRIPT_LIMIT) {
                const cut = this.transcript.indexOf('\n', this.transcript.length - TRANSCRIPT_LIMIT);
                this.transcript = '\x1b[0m' + this.transcript.slice(cut === -1 ? this.transcript.length - TRANSCRIPT_LIMIT : cut + 1);
            }

            clearTimeout(this.saveTimer);
            this.saveTimer = setTimeout(() => this.save(), 400);
        },

        save() {
            if (!config.storageKey) {
                return;
            }

            try {
                if (this.transcript === '') {
                    localStorage.removeItem(config.storageKey);
                } else {
                    localStorage.setItem(config.storageKey, this.transcript);
                }
            } catch (e) {}
        },

        /** A header above each command: when, where, and the command itself. Earlier output stays above it. */
        announce(detail) {
            if (this.showingHint) {
                this.showingHint = false;
                this.handle.term.reset();
            } else {
                this.handle.newline();
                this.handle.write('\r\n');
            }

            const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            const place = detail.directory ? '\x1b[34m' + detail.directory + '\x1b[0m ' : '';

            this.handle.write(place + '\x1b[90m' + time + '\x1b[0m\r\n\x1b[1;36m$\x1b[0m \x1b[1m' + detail.command + '\x1b[0m\r\n');
            this.handle.term.scrollToBottom();
            this.startedAt = Date.now();
        },

        /** A line under a finished command: how it ended and how long it took. */
        finish(exit) {
            if (!this.handle) {
                return;
            }

            const seconds = this.startedAt ? (Date.now() - this.startedAt) / 1000 : null;
            const took = seconds === null ? '' : ' · ' + (seconds < 60 ? seconds.toFixed(1) + 's' : Math.floor(seconds / 60) + 'm ' + Math.round(seconds % 60) + 's');
            const mark = exit === 0 ? '\x1b[32m✓ done' : exit === null || exit === undefined ? '\x1b[33m■ ended' : '\x1b[31m✗ exit ' + exit;

            this.handle.write('\x1b[0m');
            this.handle.newline();
            this.handle.write(mark + '\x1b[0m\x1b[90m' + took + '\x1b[0m\r\n');
            this.startedAt = null;
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

            if (this.handle.busy()) {
                // A new command takes over the window: the one still running is let go, not stopped.
                this.finish(null);
            }

            this.announce(detail);
            this.handle.attachSession(urls, (state) => {
                if (!state.running) {
                    this.finish(state.exit);
                }

                this.state(state);
            });
        },

        showText(detail) {
            if (!this.handle) {
                return;
            }

            this.announce(detail);

            const text = String(detail.text || '').replace(/\r?\n/g, '\r\n');

            if (text !== '') {
                this.handle.write(text);
            }

            this.finish(detail.exit);
            this.state({ running: false, exit: detail.exit });
        },

        copy() {
            if (this.handle) {
                window.copyToClipboard(this.handle.text(), 'Output copied.');
            }
        },

        /** Empty the window and forget its transcript. A command still running keeps going. */
        clear() {
            if (!this.handle) {
                return;
            }

            if (this.handle.busy()) {
                this.handle.term.clear();
            } else {
                this.handle.term.reset();
            }

            this.transcript = '';
            this.decoder = null;
            this.showingHint = false;
            clearTimeout(this.saveTimer);
            this.saveTimer = null;
            this.save();
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
            if (this.saveTimer) {
                clearTimeout(this.saveTimer);
                this.save();
            }

            if (this.handle) {
                this.handle.dispose();
            }
        },
    };
};

/** A sidebar section that folds away: remembered per browser, always open when it holds the current page. */
window.navSection = function (key, holdsCurrentPage) {
    const storageKey = 'laravelify-nav-' + key;

    return {
        open: true,

        init() {
            try {
                const saved = localStorage.getItem(storageKey);
                if (saved !== null) this.open = saved === '1';
            } catch (e) {}

            if (holdsCurrentPage) this.open = true;
        },

        toggle() {
            this.open = !this.open;

            try {
                localStorage.setItem(storageKey, this.open ? '1' : '0');
            } catch (e) {}
        },
    };
};

/** Keep a value of the page in its address (search boxes the browser filters by itself), so a reload or a shared link shows the same. */
window.setUrlParam = function (name, value) {
    const url = new URL(window.location.href);

    if (value) url.searchParams.set(name, value);
    else url.searchParams.delete(name);

    window.history.replaceState(window.history.state, '', url);
};

window.urlParam = (name) => new URLSearchParams(window.location.search).get(name) || '';

/** The Ctrl+K palette: every sidebar link (read from the sidebar itself) plus a few quick actions. */
window.commandPalette = function (actions) {
    return {
        open: false,
        query: '',
        active: 0,
        items: [],

        get results() {
            const terms = this.query.toLowerCase().trim().split(/\s+/).filter(Boolean);

            if (terms.length === 0) return this.items;

            const groups = [...new Set(this.items.map((item) => item.group))];

            return this.items
                .map((item) => {
                    const label = item.label.toLowerCase();
                    const haystack = (label + ' ' + item.group + ' ' + (item.hint || '')).toLowerCase();
                    if (!terms.every((term) => haystack.includes(term))) return null;
                    const score = label.startsWith(terms[0]) ? 3 : label.includes(terms[0]) ? 2 : 1;
                    return { item, score };
                })
                .filter(Boolean)
                .sort((a, b) => groups.indexOf(a.item.group) - groups.indexOf(b.item.group) || b.score - a.score)
                .map((match) => match.item);
        },

        icon(name) {
            return this.$refs.icons.content.querySelector('[data-icon="' + name + '"]')?.innerHTML || '';
        },

        collect() {
            const seen = new Set();
            const pages = [];

            document.querySelectorAll('.sidebar-nav a.menu-item').forEach((link) => {
                const label = link.querySelector('span')?.textContent.trim();
                const id = link.getAttribute('href') + '|' + label;
                if (!label || seen.has(id)) return;
                seen.add(id);

                pages.push({
                    id,
                    label,
                    group: link.closest('.nav-block')?.querySelector('.nav-section-toggle > span')?.textContent.trim() || 'Workspace',
                    hint: link.target === '_blank' ? link.hostname : '',
                    icon: link.querySelector('svg.menu-icon')?.outerHTML || '',
                    href: link.getAttribute('href'),
                    external: link.target === '_blank',
                });
            });

            const dark = document.documentElement.classList.contains('dark');
            const quick = actions.map((action) => ({ ...action, id: 'action|' + action.label, group: 'Actions', icon: this.icon(action.icon) }));

            quick.push({
                id: 'action|theme',
                label: dark ? 'Switch to light theme' : 'Switch to dark theme',
                group: 'Actions',
                icon: this.icon('sun'),
                run: () => {
                    window.laravelifyTheme(dark ? 'light' : 'dark');
                },
            });

            quick.push({
                id: 'action|logout',
                label: 'Log out',
                group: 'Actions',
                icon: this.icon('logout'),
                run: () => document.querySelector('form[data-command-logout]')?.submit(),
            });

            this.items = [...pages, ...quick];
        },

        show() {
            this.collect();
            this.query = '';
            this.active = 0;
            this.open = true;
            this.$nextTick(() => this.$refs.input.focus());
        },

        close() {
            this.open = false;
        },

        shortcut(event) {
            const typing = event.target.closest('input, textarea, select, [contenteditable="true"], .cm-editor, .xterm');

            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
                event.preventDefault();
                this.open ? this.close() : this.show();
            } else if (event.key === '/' && !typing && !this.open && !event.ctrlKey && !event.metaKey && !event.altKey) {
                event.preventDefault();
                this.show();
            }
        },

        move(step) {
            const count = this.results.length;
            if (count === 0) return;
            this.active = (this.active + step + count) % count;
            this.$nextTick(() => document.getElementById('cmdk-item-' + this.active)?.scrollIntoView({ block: 'nearest' }));
        },

        run(item) {
            if (!item) return;
            this.close();

            if (item.run) {
                item.run();
            } else if (item.external) {
                window.open(item.href, '_blank', 'noopener');
            } else if (window.Livewire?.navigate) {
                window.Livewire.navigate(item.href);
            } else {
                window.location.href = item.href;
            }
        },
    };
};
