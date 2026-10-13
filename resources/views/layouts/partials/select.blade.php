<script>
    /*
     * Every <select> in the panel opens this searchable list (Coolify listbox style)
     * instead of the browser's native one. The <select> itself stays: it keeps its
     * value, wire:model, and form behavior, and the panel is attached to <body>, so
     * Livewire updates never touch it. Touch devices keep their native picker.
     * Opt out with data-native on a select.
     */
    (function () {
        if (window.laravelifySelect) return;

        var SEARCH_FROM = 7;
        var panel = null;
        var state = null;

        function usable(select) {
            return select instanceof HTMLSelectElement
                && !select.multiple
                && select.size <= 1
                && !select.disabled
                && !select.hasAttribute('data-native')
                && !window.matchMedia('(pointer: coarse)').matches;
        }

        function items(select) {
            var list = [];

            Array.prototype.forEach.call(select.children, function (child) {
                if (child.tagName === 'OPTGROUP') {
                    list.push({ group: child.label });
                    Array.prototype.forEach.call(child.children, function (option) {
                        list.push({ option: option, group: child.label });
                    });
                } else if (child.tagName === 'OPTION') {
                    list.push({ option: child, group: null });
                }
            });

            return list;
        }

        function el(tag, className, text) {
            var node = document.createElement(tag);
            if (className) node.className = className;
            if (text !== undefined) node.textContent = text;
            return node;
        }

        function close(refocus) {
            if (!state) return;
            var select = state.select;
            select.removeAttribute('data-open');
            select.setAttribute('aria-expanded', 'false');
            panel.classList.remove('is-open');
            window.removeEventListener('resize', place);
            document.removeEventListener('scroll', onScroll, true);
            state = null;
            if (refocus) select.focus({ preventScroll: true });
        }

        function choose(option) {
            if (!state || option.disabled) return;
            var select = state.select;
            var changed = select.value !== option.value;
            select.value = option.value;
            close(true);

            if (changed) {
                select.dispatchEvent(new Event('input', { bubbles: true }));
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        function render() {
            var query = state.input ? state.input.value.trim().toLowerCase() : '';
            var list = state.list;
            list.textContent = '';
            state.visible = [];

            var pendingGroup = null;

            state.items.forEach(function (item) {
                if (!item.option) { pendingGroup = item.group; return; }

                var option = item.option;
                var haystack = (option.textContent + ' ' + option.value + ' ' + (item.group || '')).toLowerCase();
                if (query && haystack.indexOf(query) === -1) return;

                if (pendingGroup !== null && item.group === pendingGroup) {
                    list.appendChild(el('div', 'select-panel-group', pendingGroup));
                    pendingGroup = null;
                }

                var row = el('div', 'select-panel-option');
                row.setAttribute('role', 'option');
                row.setAttribute('aria-selected', option.selected ? 'true' : 'false');
                if (option.disabled) row.classList.add('is-disabled');
                if (option.value === '' ) row.classList.add('is-placeholder');
                row.appendChild(el('span', 'select-panel-label', option.textContent.trim() || ' '));

                var check = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
                check.setAttribute('viewBox', '0 0 24 24');
                check.setAttribute('class', 'select-panel-check');
                check.innerHTML = '<path fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" d="M5 12.5l4.5 4.5L19 7.5"/>';
                row.appendChild(check);

                var index = state.visible.length;
                row.addEventListener('mousemove', function () { activate(index, false); });
                row.addEventListener('mousedown', function (event) { event.preventDefault(); });
                row.addEventListener('click', function () { choose(option); });

                state.visible.push({ option: option, row: row });
                list.appendChild(row);
            });

            if (!state.visible.length) {
                list.appendChild(el('div', 'select-panel-empty', query ? 'No matches for "' + query + '"' : 'No options'));
            }

            var selected = state.visible.findIndex(function (entry) { return entry.option.selected; });
            activate(selected >= 0 && !query ? selected : firstEnabled(), true);
        }

        function firstEnabled() {
            return state.visible.findIndex(function (entry) { return !entry.option.disabled; });
        }

        function activate(index, scroll) {
            if (!state) return;
            state.visible.forEach(function (entry, position) {
                entry.row.classList.toggle('is-active', position === index);
            });
            state.active = index;

            if (scroll && index >= 0 && state.visible[index]) {
                state.visible[index].row.scrollIntoView({ block: 'nearest' });
            }
        }

        function move(step) {
            if (!state.visible.length) return;
            var index = state.active;

            for (var tries = 0; tries < state.visible.length; tries++) {
                index = (index + step + state.visible.length) % state.visible.length;
                if (!state.visible[index].option.disabled) break;
            }

            activate(index, true);
        }

        function place() {
            if (!state) return;
            var rect = state.select.getBoundingClientRect();
            var width = Math.max(rect.width, 220);
            var left = Math.min(Math.max(8, rect.left), window.innerWidth - width - 8);
            var below = window.innerHeight - rect.bottom - 8;
            var above = rect.top - 8;
            var wanted = Math.min(panel.scrollHeight, 360);
            var up = below < Math.min(wanted, 240) && above > below;

            panel.style.width = width + 'px';
            panel.style.left = left + 'px';
            panel.style.maxHeight = Math.max(160, Math.min(360, up ? above : below)) + 'px';
            panel.style.top = up ? '' : (rect.bottom + 4) + 'px';
            panel.style.bottom = up ? (window.innerHeight - rect.top + 4) + 'px' : '';
            panel.classList.toggle('is-up', up);
        }

        function onScroll(event) {
            if (state && panel.contains(event.target)) return;
            place();
        }

        function open(select) {
            if (state && state.select === select) { close(true); return; }
            close(false);

            if (!panel) {
                panel = el('div', 'select-panel');
                panel.setAttribute('role', 'listbox');
                document.body.appendChild(panel);
            }

            panel.textContent = '';
            var all = items(select);
            var count = all.filter(function (item) { return item.option; }).length;
            state = { select: select, items: all, input: null, list: null, visible: [], active: -1 };

            if (count >= SEARCH_FROM || select.hasAttribute('data-search')) {
                var search = el('div', 'select-panel-search');
                search.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM20 20l-4-4"/></svg>';
                var input = el('input');
                input.type = 'text';
                input.placeholder = 'Search ' + count + ' options…';
                input.setAttribute('autocomplete', 'off');
                input.setAttribute('spellcheck', 'false');
                input.addEventListener('input', render);
                input.addEventListener('keydown', onKey);
                search.appendChild(input);
                panel.appendChild(search);
                state.input = input;
            }

            state.list = el('div', 'select-panel-list');
            panel.appendChild(state.list);
            render();

            select.setAttribute('data-open', '');
            select.setAttribute('aria-expanded', 'true');
            panel.classList.add('is-open');
            place();
            window.addEventListener('resize', place);
            document.addEventListener('scroll', onScroll, true);

            if (state.input) {
                state.input.focus({ preventScroll: true });
            } else {
                panel.tabIndex = -1;
                panel.focus({ preventScroll: true });
            }

            if (state.active >= 0) state.visible[state.active].row.scrollIntoView({ block: 'nearest' });
        }

        function onKey(event) {
            if (!state) return;

            if (event.key === 'ArrowDown') { event.preventDefault(); move(1); }
            else if (event.key === 'ArrowUp') { event.preventDefault(); move(-1); }
            else if (event.key === 'Home') { event.preventDefault(); activate(firstEnabled(), true); }
            else if (event.key === 'End') { event.preventDefault(); activate(state.visible.length - 1, true); }
            else if (event.key === 'Enter') {
                event.preventDefault();
                var entry = state.visible[state.active];
                if (entry) choose(entry.option);
            } else if (event.key === 'Escape') {
                // Only close the list, not a modal the select sits in.
                event.preventDefault();
                event.stopPropagation();
                close(true);
            }
            else if (event.key === 'Tab') { close(false); }
            else if (!state.input && event.key.length === 1 && !event.ctrlKey && !event.metaKey) {
                // Without a search box, typing jumps to the first option starting with that letter.
                var letter = event.key.toLowerCase();
                var index = state.visible.findIndex(function (entry) { return entry.option.textContent.trim().toLowerCase().indexOf(letter) === 0; });
                if (index >= 0) activate(index, true);
            }
        }

        document.addEventListener('mousedown', function (event) {
            var select = event.target.closest && event.target.closest('select');

            if (select && usable(select)) {
                event.preventDefault();
                select.focus({ preventScroll: true });
                open(select);
                return;
            }

            if (state && panel && !panel.contains(event.target)) close(false);
        }, true);

        document.addEventListener('keydown', function (event) {
            if (state && panel && panel.contains(document.activeElement) && document.activeElement !== state.input) {
                onKey(event);
                return;
            }

            var select = event.target;
            if (!(select instanceof HTMLSelectElement) || !usable(select)) return;

            var opens = event.key === ' ' || event.key === 'Enter' || ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && event.altKey);

            if (opens || ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && !state)) {
                event.preventDefault();
                open(select);
            }
        }, true);

        // Leaving the page (wire:navigate) or a Livewire update removing the select closes the list.
        document.addEventListener('livewire:navigating', function () { close(false); });
        document.addEventListener('livewire:init', function () {
            Livewire.hook('commit', function (commit) {
                commit.succeed(function () {
                    queueMicrotask(function () {
                        if (state && !document.body.contains(state.select)) close(false);
                    });
                });
            });
        });

        window.laravelifySelect = { open: open, close: close };
    })();
</script>
