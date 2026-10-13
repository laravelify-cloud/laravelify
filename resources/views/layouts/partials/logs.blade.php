<script>
    window.copyToClipboard = function (text, message) {
        if (!navigator.clipboard) {
            window.toast('Copying needs a secure (https) connection.', 'error');

            return;
        }

        navigator.clipboard.writeText(text).then(
            () => window.toast(message || 'Copied to clipboard.'),
            () => window.toast('Could not copy to the clipboard.', 'error')
        );
    };

    /**
     * Terminal prompt: Up/Down history, plus a "/" command menu built from the
     * suggested commands and the app's own `php artisan list`.
     */
    window.terminalPrompt = function (scope, suggestions) {
        var key = 'laravelify-terminal-' + scope;
        var history = [];

        try {
            history = JSON.parse(localStorage.getItem(key) || '[]') || [];
        } catch (e) {}

        return {
            history: history,
            cursor: history.length,
            suggestions: suggestions,
            live: [],
            liveState: 'idle',
            menuOpen: false,
            menuQuery: '',
            results: [],
            active: 0,

            input() {
                return this.$refs.command;
            },

            setCommand(value) {
                this.$wire.command = value;
                this.input().value = value;
            },

            remember(command) {
                if (!command) {
                    return;
                }

                this.history = this.history.filter((item) => item !== command).concat([command]).slice(-50);
                this.cursor = this.history.length;

                try {
                    localStorage.setItem(key, JSON.stringify(this.history));
                } catch (e) {}
            },

            recall(step) {
                if (this.history.length === 0) {
                    return;
                }

                this.cursor = Math.min(this.history.length, Math.max(0, this.cursor + step));
                this.setCommand(this.cursor === this.history.length ? '' : this.history[this.cursor]);
            },

            onInput(event) {
                var value = event.target.value;

                if (value.startsWith('/')) {
                    this.openMenu(value.slice(1));
                } else if (this.menuOpen) {
                    this.menuOpen = false;
                }
            },

            openMenu(query) {
                this.menuQuery = query || '';
                this.menuOpen = true;
                this.active = 0;
                this.filter();
                this.loadLive();
            },

            toggleMenu() {
                if (this.menuOpen) {
                    this.closeMenu();

                    return;
                }

                this.setCommand('/');
                this.openMenu('');
                this.$nextTick(() => this.input().focus());
            },

            closeMenu() {
                this.menuOpen = false;

                if (this.input().value.startsWith('/')) {
                    this.setCommand('');
                }
            },

            loadLive() {
                if (this.liveState !== 'idle') {
                    return;
                }

                this.liveState = 'loading';
                this.$wire.artisanCommands().then((commands) => {
                    var known = this.suggestions.map((item) => item.command);

                    this.live = (commands || [])
                        .filter((item) => !known.includes(item.command))
                        .map((item) => ({ group: 'All artisan commands', command: item.command, description: item.description, danger: false }));
                    this.liveState = 'done';
                    this.filter();
                }, () => {
                    this.liveState = 'done';
                });
            },

            filter() {
                var terms = this.menuQuery.toLowerCase().split(/\s+/).filter(Boolean);
                var all = this.suggestions.concat(this.live);

                this.results = all.filter((item) => {
                    var haystack = (item.command + ' ' + item.description + ' ' + item.group).toLowerCase();

                    return terms.every((term) => haystack.includes(term));
                }).slice(0, 250);

                this.active = Math.min(this.active, Math.max(0, this.results.length - 1));
            },

            move(step) {
                if (this.results.length === 0) {
                    return;
                }

                this.active = (this.active + step + this.results.length) % this.results.length;
                this.$nextTick(() => {
                    var row = this.$refs.menu && this.$refs.menu.querySelector('[data-index="' + this.active + '"]');
                    row && row.scrollIntoView({ block: 'nearest' });
                });
            },

            pick(index) {
                var item = this.results[index];

                if (!item) {
                    return;
                }

                this.menuOpen = false;
                this.setCommand(item.command + ' ');
                this.$nextTick(() => {
                    var field = this.input();
                    field.focus();
                    field.setSelectionRange(field.value.length, field.value.length);
                });
            },

            onKey(event) {
                if (this.menuOpen) {
                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        event.preventDefault();
                        this.move(event.key === 'ArrowDown' ? 1 : -1);
                    } else if (event.key === 'Enter' || event.key === 'Tab') {
                        event.preventDefault();
                        this.pick(this.active);
                    } else if (event.key === 'Escape') {
                        event.preventDefault();
                        event.stopPropagation();
                        this.closeMenu();
                    }

                    return;
                }

                if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
                    event.preventDefault();
                    this.recall(event.key === 'ArrowUp' ? -1 : 1);
                }
            },

            isGroupStart(index) {
                return index === 0 || this.results[index - 1].group !== this.results[index].group;
            },
        };
    };

    window.deployLogs = function (options) {
        var wrap = true;

        try {
            wrap = localStorage.getItem('laravelify-log-wrap') !== 'off';
        } catch (e) {}

        return {
            live: options.live,
            name: options.name,
            follow: options.live,
            atBottom: true,
            wrap: wrap,
            fullscreen: false,
            query: '',
            matches: 0,
            programmatic: false,
            observer: null,

            init() {
                this.$watch('query', () => this.applySearch());
                this.$watch('fullscreen', () => this.$nextTick(() => this.follow && this.toBottom()));

                this.observer = new MutationObserver(() => {
                    if (this.query.trim() !== '') {
                        this.applySearch();
                    }

                    if (this.follow) {
                        this.toBottom();
                    }
                });

                this.observer.observe(this.$refs.lines, { childList: true, subtree: true, characterData: true });
                this.$nextTick(() => this.toBottom());
            },

            destroy() {
                this.observer && this.observer.disconnect();
            },

            toBottom() {
                var viewport = this.$refs.viewport;

                if (!viewport) {
                    return;
                }

                this.programmatic = true;
                viewport.scrollTop = viewport.scrollHeight;
                requestAnimationFrame(() => {
                    this.programmatic = false;
                });
            },

            onScroll() {
                if (this.programmatic) {
                    return;
                }

                var viewport = this.$refs.viewport;
                var atBottom = viewport.scrollHeight - viewport.scrollTop - viewport.clientHeight <= 12;
                this.atBottom = atBottom;
                this.follow = atBottom;
            },

            toggleFollow() {
                this.follow = !this.follow;

                if (this.follow) {
                    this.toBottom();
                }
            },

            toggleWrap() {
                this.wrap = !this.wrap;

                try {
                    localStorage.setItem('laravelify-log-wrap', this.wrap ? 'on' : 'off');
                } catch (e) {}
            },

            lines() {
                return Array.from(this.$refs.lines.querySelectorAll('[data-log-line]'));
            },

            highlight(element, text, query) {
                element.textContent = '';
                var lower = text.toLowerCase();
                var last = 0;
                var index = lower.indexOf(query);

                while (index !== -1) {
                    if (index > last) {
                        element.appendChild(document.createTextNode(text.slice(last, index)));
                    }

                    var mark = document.createElement('mark');
                    mark.className = 'log-highlight';
                    mark.textContent = text.slice(index, index + query.length);
                    element.appendChild(mark);
                    last = index + query.length;
                    index = lower.indexOf(query, last);
                }

                if (last < text.length) {
                    element.appendChild(document.createTextNode(text.slice(last)));
                }
            },

            applySearch() {
                var query = this.query.trim().toLowerCase();
                var count = 0;

                this.observer && this.observer.disconnect();

                this.lines().forEach((line) => {
                    var textElement = line.querySelector('[data-text]');
                    var text = textElement ? textElement.dataset.text : '';
                    var found = query === '' || text.toLowerCase().includes(query);

                    line.classList.toggle('hidden', !found);

                    if (!textElement) {
                        return;
                    }

                    if (query === '' || !found) {
                        textElement.textContent = text;
                    } else {
                        count++;
                        this.highlight(textElement, text, query);
                    }
                });

                this.matches = count;
                this.observer && this.observer.observe(this.$refs.lines, { childList: true, subtree: true, characterData: true });
            },

            visibleText() {
                return this.lines()
                    .filter((line) => !line.classList.contains('hidden'))
                    .map((line) => line.querySelector('[data-text]').dataset.text)
                    .join('\n');
            },

            copy() {
                window.copyToClipboard(this.visibleText(), 'Logs copied to clipboard.');
            },

            download() {
                var blob = new Blob([this.visibleText() + '\n'], { type: 'text/plain' });
                var url = URL.createObjectURL(blob);
                var link = document.createElement('a');
                link.href = url;
                link.download = this.name + '.log';
                link.click();
                URL.revokeObjectURL(url);
            },
        };
    };
</script>
