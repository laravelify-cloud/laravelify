/*
 * The Database tool's rows grid (AG Grid Community, MIT). Loaded only on a table page.
 *
 * Everything heavy happens in MySQL: the grid asks the server for rows as it scrolls (the Infinite
 * Row Model: blocks of 100, sorted and filtered there), the funnel on a header lists the column's
 * values from the server (with counts, like a spreadsheet filter) or takes a condition, and the ⋮
 * menu sorts, pins, sizes, hides, and shows columns. Column widths, order, pins, and hidden columns
 * are kept per table in the browser. A column marked encrypted (with a website's encryption method)
 * comes from the server decrypted, and the ⋮ menu marks it or shows it as stored.
 */
import { AllCommunityModule, ModuleRegistry, createGrid, themeQuartz } from 'ag-grid-community';

ModuleRegistry.registerModules([AllCommunityModule]);

const theme = themeQuartz.withParams({
    backgroundColor: 'var(--color-canvas)',
    foregroundColor: 'var(--color-ink)',
    headerBackgroundColor: 'var(--color-recessed)',
    headerTextColor: 'var(--color-ink)',
    borderColor: 'var(--color-line)',
    chromeBackgroundColor: 'var(--color-recessed)',
    oddRowBackgroundColor: 'color-mix(in srgb, var(--color-ink) 2.5%, transparent)',
    rowHoverColor: 'color-mix(in srgb, var(--color-accent) 8%, transparent)',
    selectedRowBackgroundColor: 'color-mix(in srgb, var(--color-accent) 16%, transparent)',
    accentColor: 'var(--color-accent)',
    fontFamily: 'var(--font-mono)',
    headerFontFamily: 'var(--font-mono)',
    fontSize: 12.5,
    headerFontSize: 12,
    headerFontWeight: 600,
    rowHeight: 34,
    headerHeight: 40,
    spacing: 6,
    wrapperBorder: false,
    wrapperBorderRadius: 0,
    columnBorder: true,
    browserColorScheme: 'inherit',
});

const NUMBER_TYPES = ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint', 'year'];

/** A fixed width by type (numbers fit 7 digits, text 220px), never narrower than the header needs. */
function widthFor(column) {
    const type = column.dataType;
    let width = 220;

    if (NUMBER_TYPES.includes(type)) width = 96;
    else if (['decimal', 'float', 'double'].includes(type)) width = 116;
    else if (['datetime', 'timestamp'].includes(type)) width = 176;
    else if (type === 'date') width = 120;
    else if (type === 'time') width = 100;
    else if (type === 'enum') width = 150;

    // The name, its badges, and the two buttons (funnel and menu) always fit.
    const badges = (column.key ? 1 : 0) + (column.out ? 1 : 0) + (column.in.length ? 1 : 0) + (column.encrypted ? 1 : 0);

    return Math.max(width, Math.ceil(column.name.length * 7.6) + 70 + badges * 26);
}

function go(url) {
    if (window.Livewire && typeof window.Livewire.navigate === 'function') {
        window.Livewire.navigate(url);
    } else {
        window.location.href = url;
    }
}

function relatedUrl(template, relation, value) {
    const base = template.replace('__DB__', encodeURIComponent(relation.database)).replace('__TABLE__', encodeURIComponent(relation.table));

    return base + '?' + new URLSearchParams({ ['where[' + relation.column + ']']: String(value) }).toString();
}

function el(tag, className, text) {
    const node = document.createElement(tag);
    if (className) node.className = className;
    if (text !== undefined) node.textContent = text;
    return node;
}

// One floating panel at a time: the related-rows list, a cell, a filter, or a column menu.
let popup = null;

function closePopup() {
    if (popup) {
        popup.remove();
        popup = null;
    }
}

function openPopup(anchor, node, width) {
    closePopup();
    popup = node;
    popup.classList.add('db-pop');
    popup.style.width = width + 'px';
    document.body.appendChild(popup);
    const box = anchor.getBoundingClientRect();
    const height = popup.offsetHeight;
    const below = box.bottom + 4 + height < window.innerHeight;
    popup.style.left = Math.max(8, Math.min(box.left, window.innerWidth - width - 8)) + 'px';
    popup.style.top = (below ? box.bottom + 4 : Math.max(8, box.top - height - 4)) + 'px';

    return popup;
}

document.addEventListener('mousedown', (event) => {
    if (popup && !popup.contains(event.target)) closePopup();
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closePopup();
});
document.addEventListener('livewire:navigating', closePopup);

function relatedMenu(anchor, column, value, relations, payload) {
    const menu = el('div', 'db-rel-menu');
    menu.appendChild(el('p', 'db-rel-title', 'Rows that point at ' + column + ' = ' + value));

    relations.forEach((relation) => {
        const link = el('a', 'db-rel-item');
        link.href = relatedUrl(payload.urlTemplate, relation, value);
        link.append(el('span', 'font-mono', (relation.database !== payload.database ? relation.database + '.' : '') + relation.table), el('span', 'font-mono text-muted', '(' + relation.column + ')'));
        link.addEventListener('click', (event) => {
            event.preventDefault();
            closePopup();
            go(link.href);
        });
        menu.appendChild(link);
    });

    openPopup(anchor, menu, 290);
}

/** A cell's whole value, to read and copy, and the way into its record. */
function cellPopup(anchor, column, value, row, grid) {
    const box = el('div', 'db-cell-pop');
    const head = el('div', 'db-pop-head');
    head.append(el('span', 'font-mono font-medium', column.name), el('span', 'text-muted text-[11px]', column.type));
    const body = el('pre', 'db-cell-value', value === null || value === undefined ? 'NULL' : String(value));
    if (value === null || value === undefined) body.classList.add('db-grid-null');
    const actions = el('div', 'db-pop-actions');
    const copy = el('button', 'btn btn-sm', 'Copy');
    copy.type = 'button';
    copy.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(value === null || value === undefined ? '' : String(value));
            copy.textContent = 'Copied';
        } catch (e) {
            copy.textContent = 'Could not copy';
        }
    });
    actions.appendChild(copy);

    if (grid.payload.canChange) {
        const open = el('button', 'btn btn-sm btn-primary', 'Open record');
        open.type = 'button';
        open.addEventListener('click', () => {
            closePopup();
            grid.openRow(row);
        });
        actions.appendChild(open);
    }

    if (typeof value === 'string' && value.endsWith('…')) {
        box.append(head, body, el('p', 'text-[11.5px] text-muted', 'Long values are cut here; Open record shows all of it.'), actions);
    } else {
        box.append(head, body, actions);
    }

    openPopup(anchor, box, 380);
}

function cellRenderer(column, grid) {
    return (params) => {
        const value = params.value;

        if (params.data === undefined) {
            return el('span', 'db-grid-loading', '…');
        }

        if (value === null || value === undefined) {
            return el('span', 'db-grid-null', 'NULL');
        }

        const text = String(value);

        if (column.out) {
            const link = el('a', 'db-grid-link', text);
            link.href = relatedUrl(grid.payload.urlTemplate, column.out, text);
            link.title = 'Open the ' + column.out.table + ' row with ' + column.out.column + ' = ' + text;
            link.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                go(link.href);
            });

            return link;
        }

        if (column.in.length > 0) {
            const button = el('button', 'db-grid-link', text);
            button.type = 'button';
            button.title = 'Rows in other tables that point at this one';
            button.addEventListener('click', (event) => {
                event.stopPropagation();
                relatedMenu(button, column.name, text, column.in, grid.payload);
            });

            return button;
        }

        return el('span', null, text);
    };
}

/** The header filter: a list of the column's values (from the server, with counts), or a condition. */
function filterPopup(anchor, column, grid) {
    const current = grid.payload.columnFilters[column.name] || null;
    const box = el('div', 'db-filter');
    const tabs = el('div', 'segmented db-filter-tabs');
    const valuesTab = el('button', null, 'Values');
    const conditionTab = el('button', null, 'Condition');
    valuesTab.type = conditionTab.type = 'button';
    tabs.append(valuesTab, conditionTab);
    const valuesPane = el('div', 'db-filter-pane');
    const conditionPane = el('div', 'db-filter-pane');
    box.append(tabs, valuesPane, conditionPane);

    const show = (tab) => {
        valuesTab.setAttribute('aria-pressed', tab === 'values' ? 'true' : 'false');
        conditionTab.setAttribute('aria-pressed', tab === 'condition' ? 'true' : 'false');
        valuesPane.hidden = tab !== 'values';
        conditionPane.hidden = tab !== 'condition';
    };
    valuesTab.addEventListener('click', () => show('values'));
    conditionTab.addEventListener('click', () => show('condition'));

    // Values
    const find = el('input', 'input h-8 font-mono text-[12.5px]');
    find.type = 'search';
    find.name = 'db-filter-find';
    find.placeholder = 'Search values';
    const list = el('div', 'db-filter-list');
    const note = el('p', 'text-[11.5px] text-muted');
    valuesPane.append(find, list, note);

    const ticked = new Set(current && current.operator === 'in' ? (current.values || []).map(String) : []);
    let blanks = current && current.operator === 'in' ? !!current.blanks : false;
    let everything = !current || current.operator !== 'in';
    let shown = [];

    const row = (label, checked, onChange, count, muted) => {
        const line = el('label', 'db-filter-row');
        const box = el('input');
        box.type = 'checkbox';
        box.checked = checked;
        box.addEventListener('change', () => onChange(box.checked));
        const text = el('span', 'db-filter-value' + (muted ? ' text-muted italic' : ''), label);
        line.append(box, text);
        if (count !== undefined) line.appendChild(el('span', 'db-filter-count', count.toLocaleString()));
        return line;
    };

    const draw = () => {
        list.textContent = '';
        const values = shown.filter((item) => item.value !== null);
        const hasNull = shown.some((item) => item.value === null);
        const allTicked = everything || (values.every((item) => ticked.has(item.value)) && (!hasNull || blanks));

        list.appendChild(row('(Select all)', allTicked, (on) => {
            everything = false;
            values.forEach((item) => (on ? ticked.add(item.value) : ticked.delete(item.value)));
            if (hasNull) blanks = on;
            draw();
        }));

        if (hasNull) {
            const nulls = shown.find((item) => item.value === null);
            list.appendChild(row('(Empty)', everything || blanks, (on) => {
                if (everything) { everything = false; values.forEach((item) => ticked.add(item.value)); }
                blanks = on;
                draw();
            }, nulls.count, true));
        }

        values.forEach((item) => {
            list.appendChild(row(item.value === '' ? '(blank text)' : (item.label ?? item.value), everything || ticked.has(item.value), (on) => {
                if (everything) { everything = false; values.forEach((other) => ticked.add(other.value)); blanks = hasNull; }
                on ? ticked.add(item.value) : ticked.delete(item.value);
                draw();
            }, item.count, item.value === ''));
        });
    };

    let timer = null;
    const load = async () => {
        note.textContent = 'Reading values…';
        const answer = await grid.wire.columnValues(column.name, find.value);
        shown = answer.values || [];
        note.textContent = answer.error ? answer.error : (answer.more ? 'The most common values. Search to find others.' : shown.length + ' values');
        draw();
    };
    find.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(load, 300);
    });

    // Condition
    const operator = el('select', 'select h-8');
    operator.name = 'db-filter-operator';
    // An encrypted column can only be matched whole: the value is encrypted, then compared.
    const decrypts = column.encrypted && column.encrypted.decrypts && !column.raw;
    Object.entries(grid.payload.operators).filter(([value]) => !decrypts || ['equals', 'not_equals', 'null', 'not_null'].includes(value)).forEach(([value, label]) => {
        const option = el('option', null, label);
        option.value = value;
        operator.appendChild(option);
    });
    const value = el('input', 'input h-8 font-mono text-[12.5px]');
    value.name = 'db-filter-value';
    value.placeholder = 'Value';
    if (decrypts) value.placeholder = 'Whole value (decrypted)';
    if (current && current.operator !== 'in') {
        operator.value = current.operator;
        value.value = current.value || '';
    }
    const toggleValue = () => { value.hidden = ['null', 'not_null'].includes(operator.value); };
    operator.addEventListener('change', toggleValue);
    toggleValue();
    conditionPane.append(operator, value);

    // Buttons
    const actions = el('div', 'db-pop-actions');
    const clear = el('button', 'btn btn-sm', 'Clear');
    const apply = el('button', 'btn btn-sm btn-primary', 'Apply');
    clear.type = apply.type = 'button';
    actions.append(clear, apply);
    box.appendChild(actions);

    clear.addEventListener('click', () => {
        closePopup();
        grid.wire.setColumnFilter(column.name, { mode: 'none' });
    });
    apply.addEventListener('click', () => {
        closePopup();

        if (!conditionPane.hidden) {
            grid.wire.setColumnFilter(column.name, { mode: 'condition', operator: operator.value, value: value.value });
        } else if (everything && find.value === '') {
            grid.wire.setColumnFilter(column.name, { mode: 'none' });
        } else {
            const values = everything ? shown.filter((item) => item.value !== null).map((item) => item.value) : [...ticked];
            grid.wire.setColumnFilter(column.name, { mode: 'values', values, blanks: everything ? shown.some((item) => item.value === null) : blanks });
        }
    });

    show(current && current.operator !== 'in' ? 'condition' : 'values');
    openPopup(anchor, box, 300);
    load();
    find.focus();
}

/** The ⋮ menu of a column: sort, pin, size, hide, choose columns, reset. */
function columnMenu(anchor, column, grid) {
    const api = grid.api;
    const menu = el('div', 'db-menu');
    const item = (label, action, active) => {
        const button = el('button', 'db-menu-item' + (active ? ' is-active' : ''), label);
        button.type = 'button';
        button.addEventListener('click', () => {
            closePopup();
            action();
        });
        menu.appendChild(button);
    };
    const line = () => menu.appendChild(el('hr', 'db-menu-line'));
    const state = api.getColumnState().find((entry) => entry.colId === column.name) || {};

    item('↑  Sort ascending', () => api.applyColumnState({ state: [{ colId: column.name, sort: 'asc' }], defaultState: { sort: null } }), state.sort === 'asc');
    item('↓  Sort descending', () => api.applyColumnState({ state: [{ colId: column.name, sort: 'desc' }], defaultState: { sort: null } }), state.sort === 'desc');
    if (state.sort) item('    Remove sort', () => api.applyColumnState({ defaultState: { sort: null } }));
    line();
    item('Pin left', () => api.setColumnsPinned([column.name], 'left'), state.pinned === 'left');
    item('Pin right', () => api.setColumnsPinned([column.name], 'right'), state.pinned === 'right');
    if (state.pinned) item('No pin', () => api.setColumnsPinned([column.name], null));
    line();
    item('Fit this column to its content', () => api.autoSizeColumns([column.name]));
    item('Fit all columns to their content', () => api.autoSizeAllColumns());
    line();
    if (grid.payload.methods.length) {
        item('🔒  Encrypted with…' + (column.encrypted ? '  (' + column.encrypted.name + ')' : '') + '  ▸', () => encryptionMenu(anchor, column, grid), !!column.encrypted);
        if (column.encrypted && column.encrypted.decrypts) item(column.raw ? '    Show decrypted' : '    Show as stored (encrypted)', () => grid.wire.toggleRawColumn(column.name));
        line();
    }
    item('Hide this column', () => api.setColumnsVisible([column.name], false));
    item('Choose columns…', () => chooseColumns(anchor, grid));
    item('Reset columns', () => grid.resetColumns());

    openPopup(anchor, menu, 240);
}

/** The methods a column can be marked encrypted with: its values are then shown and searched decrypted. */
function encryptionMenu(anchor, column, grid) {
    const menu = el('div', 'db-menu');
    menu.appendChild(el('p', 'db-rel-title', column.name + ' is encrypted with'));
    const choose = (label, id, active) => {
        const button = el('button', 'db-menu-item' + (active ? ' is-active' : ''), (active ? '✓  ' : '    ') + label);
        button.type = 'button';
        button.addEventListener('click', () => {
            closePopup();
            grid.wire.setColumnEncryption(column.name, id);
        });
        menu.appendChild(button);
    };

    grid.payload.methods.forEach((method) => choose(method.name, method.id, column.encrypted && column.encrypted.id === method.id));
    menu.appendChild(el('hr', 'db-menu-line'));
    choose('Not encrypted', null, !column.encrypted);
    menu.appendChild(el('p', 'db-menu-note', 'Only this setting is saved. The data stays as it is.'));
    openPopup(anchor, menu, 260);
}

function chooseColumns(anchor, grid) {
    const box = el('div', 'db-filter');
    box.appendChild(el('p', 'db-rel-title', 'Columns to show'));
    const list = el('div', 'db-filter-list');
    grid.payload.columns.forEach((column) => {
        const line = el('label', 'db-filter-row');
        const check = el('input');
        check.type = 'checkbox';
        check.checked = grid.api.getColumn(column.name)?.isVisible() ?? true;
        check.addEventListener('change', () => grid.api.setColumnsVisible([column.name], check.checked));
        line.append(check, el('span', 'db-filter-value font-mono', column.name));
        list.appendChild(line);
    });
    box.appendChild(list);
    openPopup(anchor, box, 260);
}

/** The header: the name with its key and link badges, the sort arrow, the filter funnel, and ⋮. */
function headerComponent(grid) {
    return class {
        init(params) {
            this.params = params;
            const column = params.column.getColDef().context.column;
            this.gui = el('div', 'db-head');
            const label = el('button', 'db-head-label');
            label.type = 'button';
            label.append(el('span', 'db-head-name', column.name));
            const badge = (className, text, title) => {
                const node = el('span', 'db-grid-badge ' + className, text);
                node.title = title;
                label.append(node);
            };
            if (column.key) badge('is-key', 'PK', 'Key column');
            if (column.out) badge('is-link', '→', 'Points at ' + column.out.table + '.' + column.out.column);
            if (column.encrypted) badge('is-enc', '🔒', (column.raw ? 'Shown as stored; encrypted with ' : (column.encrypted.decrypts ? 'Shown decrypted with ' : 'Encrypted with ')) + column.encrypted.name);
            if (column.in.length) badge('is-link', '←' + column.in.length, 'Used by ' + column.in.map((relation) => relation.table + '.' + relation.column).join(', '));
            this.arrow = el('span', 'db-head-sort');
            label.append(this.arrow);
            label.title = column.type;
            label.addEventListener('click', (event) => params.progressSort(event.shiftKey));

            const funnel = el('button', 'db-head-btn' + (grid.payload.filtered.includes(column.name) ? ' is-active' : ''));
            funnel.type = 'button';
            funnel.title = 'Filter this column';
            funnel.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M10 18h4v-2h-4zM3 6v2h18V6zm3 7h12v-2H6z"/></svg>';
            funnel.addEventListener('click', (event) => {
                event.stopPropagation();
                filterPopup(funnel, column, grid);
            });

            const more = el('button', 'db-head-btn');
            more.type = 'button';
            more.title = 'Sort, pin, size, and hide';
            more.innerHTML = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 8a2 2 0 1 0 0-4 2 2 0 0 0 0 4m0 2a2 2 0 1 0 0 4 2 2 0 0 0 0-4m0 6a2 2 0 1 0 0 4 2 2 0 0 0 0-4"/></svg>';
            more.addEventListener('click', (event) => {
                event.stopPropagation();
                columnMenu(more, column, grid);
            });

            this.gui.append(label, funnel, more);
            this.onSort = () => this.drawSort();
            params.column.addEventListener('sortChanged', this.onSort);
            this.drawSort();
        }

        drawSort() {
            const sort = this.params.column.getSort();
            this.arrow.textContent = sort === 'asc' ? '↑' : (sort === 'desc' ? '↓' : '');
        }

        getGui() {
            return this.gui;
        }

        refresh() {
            return false;
        }

        destroy() {
            this.params.column.removeEventListener('sortChanged', this.onSort);
        }
    };
}

function columnDefs(grid) {
    const Header = headerComponent(grid);
    const defs = [{
        colId: '__row',
        headerName: '#',
        pinned: 'left',
        width: 70,
        resizable: false,
        sortable: false,
        suppressMovable: true,
        cellClass: 'db-ag-num',
        valueGetter: (params) => (params.node.rowIndex ?? 0) + 1,
    }];

    grid.payload.columns.forEach((column) => {
        defs.push({
            colId: column.name,
            field: column.name,
            headerName: column.name,
            headerComponent: Header,
            context: { column },
            sortable: true,
            sort: grid.payload.sort === column.name ? grid.payload.direction : null,
            pinned: column.key && grid.payload.columns.length > 6 ? 'left' : null,
            cellClass: column.numeric ? 'db-ag-number' : null,
            cellRenderer: cellRenderer(column, grid),
            tooltipValueGetter: (params) => (params.value === null || params.value === undefined ? null : String(params.value)),
            width: widthFor(column),
            minWidth: 70,
        });
    });

    return defs;
}

const storageKey = (payload) => 'laravelify-db-grid:' + payload.database + '.' + payload.table;

function saveState(api, payload) {
    try {
        const state = api.getColumnState().map(({ colId, width, pinned, hide }) => ({ colId, width, pinned, hide }));
        localStorage.setItem(storageKey(payload), JSON.stringify(state));
    } catch (e) {}
}

function restoreState(api, payload) {
    try {
        const saved = JSON.parse(localStorage.getItem(storageKey(payload)) || 'null');

        if (Array.isArray(saved)) {
            api.applyColumnState({ state: saved, applyOrder: true });
        }
    } catch (e) {}
}

function keysOf(payload, row) {
    return Object.fromEntries(payload.keyColumns.map((column) => [column, row[column] ?? null]));
}

/**
 * Show the grid in `element`. Returns `update(payload)` for when the search, filters, or columns change.
 */
export function mountGrid(element, wire, first) {
    const grid = { payload: first, wire, api: null };

    grid.openRow = (row) => wire.call('openRow', keysOf(grid.payload, row));

    const datasource = {
        getRows: async (params) => {
            const sorted = params.sortModel && params.sortModel[0];

            try {
                const answer = await wire.fetchRows(params.startRow, params.endRow, sorted ? sorted.colId : null, sorted ? sorted.sort : 'asc');
                params.successCallback(answer.rows || [], answer.total ?? -1);
            } catch (e) {
                params.failCallback();
            }
        },
    };

    grid.api = createGrid(element, {
        theme,
        rowModelType: 'infinite',
        datasource,
        cacheBlockSize: 100,
        maxBlocksInCache: 30,
        infiniteInitialRowCount: Math.min(100, first.total || 1),
        getRowId: first.keyColumns.length ? (params) => JSON.stringify(keysOf(grid.payload, params.data)) : undefined,
        columnDefs: columnDefs(grid),
        defaultColDef: { resizable: true, sortable: true, filter: false, suppressHeaderMenuButton: true },
        rowSelection: first.canChange ? { mode: 'multiRow', checkboxes: true, headerCheckbox: false, enableClickSelection: false } : undefined,
        selectionColumnDef: { pinned: 'left', width: 44 },
        suppressCellFocus: true,
        enableCellTextSelection: true,
        tooltipShowDelay: 500,
        tooltipShowMode: 'whenTruncated',
        overlayNoRowsTemplate: '<span class="text-muted">' + (first.anyFilter ? 'No rows match.' : 'This table is empty.') + '</span>',
        onGridReady: (params) => restoreState(params.api, grid.payload),
        onColumnMoved: (params) => params.finished && saveState(params.api, grid.payload),
        onColumnResized: (params) => params.finished && saveState(params.api, grid.payload),
        onColumnPinned: (params) => saveState(params.api, grid.payload),
        onColumnVisible: (params) => saveState(params.api, grid.payload),
        onSelectionChanged: (params) => {
            const keys = params.api.getSelectedRows().map((row) => JSON.stringify(keysOf(grid.payload, row)));
            window.dispatchEvent(new CustomEvent('db-grid-selected', { detail: keys }));
        },
        onCellClicked: (params) => {
            const target = params.event && params.event.target;

            if (!params.data || params.colDef.colId === '__row' || !params.colDef.context || (target && target.closest && target.closest('a, button, input, .ag-selection-checkbox'))) return;

            const cell = target && target.closest ? (target.closest('.ag-cell') || target) : element;
            cellPopup(cell, params.colDef.context.column, params.value, params.data, grid);
        },
        onRowDoubleClicked: (params) => {
            if (grid.payload.canChange && params.data) {
                closePopup();
                grid.openRow(params.data);
            }
        },
    });

    grid.resetColumns = () => {
        try {
            localStorage.removeItem(storageKey(grid.payload));
        } catch (e) {}
        grid.api.resetColumnState();
    };

    window.addEventListener('rows-deleted', () => {
        grid.api.deselectAll();
        grid.api.purgeInfiniteCache();
    });

    return function update(next) {
        const columnsChanged = JSON.stringify(next.columns) !== JSON.stringify(grid.payload.columns);
        const rowsChanged = next.version !== grid.payload.version || next.total !== grid.payload.total;
        const filtersChanged = JSON.stringify(next.filtered) !== JSON.stringify(grid.payload.filtered);
        grid.payload = next;

        if (columnsChanged || filtersChanged) {
            grid.api.setGridOption('columnDefs', columnDefs(grid));
            if (columnsChanged) restoreState(grid.api, grid.payload);
        }

        if (rowsChanged || columnsChanged) {
            grid.api.deselectAll();
            grid.api.purgeInfiniteCache();
        }
    };
}
