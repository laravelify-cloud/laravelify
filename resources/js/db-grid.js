/*
 * The Database tool's rows grid (AG Grid Community, MIT). Loaded only on a table page.
 *
 * The server keeps the rows: paging, sorting, the search, and filters run in MySQL, so a click on
 * a column header asks Livewire to sort and the grid shows the page that comes back. The grid adds
 * what a spreadsheet does: resizing, moving, and pinning columns (kept per table in the browser),
 * ticking rows, and links between related rows.
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
    headerHeight: 38,
    spacing: 6,
    wrapperBorder: false,
    wrapperBorderRadius: 0,
    columnBorder: true,
    browserColorScheme: 'inherit',
});

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

let menu = null;

function closeMenu() {
    if (menu) {
        menu.remove();
        menu = null;
    }
}

/** The list of tables that point at a value, next to the clicked cell. */
function openMenu(anchor, column, value, relations, template, database) {
    closeMenu();
    menu = document.createElement('div');
    menu.className = 'db-rel-menu';
    const title = document.createElement('p');
    title.className = 'db-rel-title';
    title.textContent = 'Rows that point at ' + column + ' = ' + value;
    menu.appendChild(title);

    relations.forEach((relation) => {
        const link = document.createElement('a');
        link.className = 'db-rel-item';
        link.href = relatedUrl(template, relation, value);
        const name = document.createElement('span');
        name.className = 'font-mono';
        name.textContent = (relation.database !== database ? relation.database + '.' : '') + relation.table;
        const via = document.createElement('span');
        via.className = 'font-mono text-muted';
        via.textContent = '(' + relation.column + ')';
        link.append(name, via);
        link.addEventListener('click', (event) => {
            event.preventDefault();
            closeMenu();
            go(link.href);
        });
        menu.appendChild(link);
    });

    const box = anchor.getBoundingClientRect();
    menu.style.left = Math.max(8, Math.min(box.left, window.innerWidth - 300)) + 'px';
    menu.style.top = (box.bottom + 4) + 'px';
    document.body.appendChild(menu);
}

document.addEventListener('mousedown', (event) => {
    if (menu && !menu.contains(event.target)) closeMenu();
});
document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') closeMenu();
});
document.addEventListener('livewire:navigating', closeMenu);

function cellRenderer(column, payload) {
    return (params) => {
        const value = params.value;
        const span = document.createElement('span');

        if (value === null || value === undefined) {
            span.className = 'db-grid-null';
            span.textContent = 'NULL';

            return span;
        }

        const text = String(value);

        if (column.out) {
            const link = document.createElement('a');
            link.className = 'db-grid-link';
            link.href = relatedUrl(payload.urlTemplate, column.out, text);
            link.textContent = text;
            link.title = 'Open the ' + column.out.table + ' row with ' + column.out.column + ' = ' + text;
            link.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                go(link.href);
            });

            return link;
        }

        if (column.in.length > 0) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'db-grid-link';
            button.textContent = text;
            button.title = 'Rows in other tables that point at this one';
            button.addEventListener('click', (event) => {
                event.stopPropagation();
                openMenu(button, column.name, text, column.in, payload.urlTemplate, payload.database);
            });

            return button;
        }

        span.textContent = text.length > 200 ? text.slice(0, 200) + '…' : text;

        return span;
    };
}

function headerName(column) {
    let name = column.name;

    if (column.key) name += '  ·key';
    if (column.out) name += '  → ' + column.out.table;
    if (column.in.length > 0) name += '  ← ' + column.in.length;

    return name;
}

function columnDefs(payload) {
    const defs = [{
        colId: '__row',
        headerName: '#',
        pinned: 'left',
        width: 70,
        resizable: false,
        sortable: false,
        suppressMovable: true,
        cellClass: 'db-ag-num',
        valueGetter: (params) => payload.offset + params.node.rowIndex + 1,
    }];

    payload.columns.forEach((column) => {
        defs.push({
            colId: column.name,
            field: column.name,
            headerName: headerName(column),
            headerTooltip: column.type + (column.out ? ' · points at ' + column.out.table + '.' + column.out.column : '') + (column.in.length ? ' · used by ' + column.in.map((r) => r.table + '.' + r.column).join(', ') : ''),
            sortable: true,
            // The server sorts; the grid keeps the order it gets.
            comparator: () => 0,
            sort: payload.sort === column.name ? payload.direction : null,
            pinned: column.key && payload.columns.length > 6 ? 'left' : null,
            cellClass: column.numeric ? 'db-ag-number' : null,
            cellRenderer: cellRenderer(column, payload),
            minWidth: 90,
            // Wide enough for the header (name and its key and link badges), never wider than 360px.
            width: Math.min(360, Math.max(110, headerName(column).length * 7.6 + 56)),
        });
    });

    return defs;
}

const storageKey = (payload) => 'laravelify-db-grid:' + payload.database + '.' + payload.table;

function saveState(api, payload) {
    try {
        const state = api.getColumnState().map(({ colId, width, pinned }) => ({ colId, width, pinned }));
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

/**
 * Show the grid in `element`. Returns `update(payload)` for new rows from the server.
 */
export function mountGrid(element, wire, first) {
    let payload = first;
    let quiet = false;

    const api = createGrid(element, {
        theme,
        columnDefs: columnDefs(payload),
        rowData: payload.rows,
        defaultColDef: { resizable: true, sortable: true, filter: false, suppressHeaderMenuButton: true },
        rowSelection: payload.canChange ? { mode: 'multiRow', checkboxes: true, headerCheckbox: true, enableClickSelection: false } : undefined,
        selectionColumnDef: { pinned: 'left', width: 44 },
        suppressCellFocus: true,
        enableCellTextSelection: true,
        tooltipShowDelay: 400,
        animateRows: false,
        overlayNoRowsTemplate: '<span class="text-muted">' + (payload.filtered ? 'No rows match.' : 'This table is empty.') + '</span>',
        onGridReady: (params) => restoreState(params.api, payload),
        onColumnMoved: (params) => params.finished && saveState(params.api, payload),
        onColumnResized: (params) => params.finished && saveState(params.api, payload),
        onColumnPinned: (params) => saveState(params.api, payload),
        onSortChanged: (params) => {
            if (quiet || params.source === 'api') return;
            const sorted = params.api.getColumnState().find((column) => column.sort);
            wire.call('sortTo', sorted ? sorted.colId : null, sorted ? sorted.sort : 'asc');
        },
        onSelectionChanged: (params) => {
            const keys = params.api.getSelectedRows().map((row) => JSON.stringify(Object.fromEntries(payload.keyColumns.map((column) => [column, row[column] ?? null]))));
            window.dispatchEvent(new CustomEvent('db-grid-selected', { detail: keys }));
        },
        onRowClicked: (params) => {
            const target = params.event && params.event.target;

            if (!payload.canChange || (target && target.closest && target.closest('a, button, input, .ag-selection-checkbox'))) return;

            wire.call('openRow', Object.fromEntries(payload.keyColumns.map((column) => [column, params.data[column] ?? null])));
        },
    });

    window.addEventListener('rows-deleted', () => api.deselectAll());

    return function update(next) {
        const columnsChanged = JSON.stringify(next.columns) !== JSON.stringify(payload.columns);
        payload = next;
        quiet = true;

        if (columnsChanged) {
            api.setGridOption('columnDefs', columnDefs(payload));
            restoreState(api, payload);
        } else {
            api.applyColumnState({ state: payload.columns.map((column) => ({ colId: column.name, sort: payload.sort === column.name ? payload.direction : null })) });
            api.refreshHeader();
        }

        api.setGridOption('rowData', payload.rows);
        api.deselectAll();
        quiet = false;
    };
}
