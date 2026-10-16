{{--
    Pick the tables a database backup leaves out. $databases: the databases to browse. $model: the Livewire
    property (a list of "database.table" or "*.table") the picker reads and writes.
--}}
<div
    class="table-picker"
    x-data="{
        databases: @js(array_values($databases)),
        skip: $wire.entangle(@js($model)),
        current: null,
        tables: [],
        loading: false,
        error: null,
        query: '',
        order: 'name',
        rule: '',
        cache: {},
        async open(database) {
            this.current = database;
            this.query = '';
            this.error = null;
            if (this.cache[database]) { this.tables = this.cache[database]; return; }
            this.loading = true;
            this.tables = [];
            try {
                const answer = await $wire.tablesOf(database);
                this.tables = answer.tables;
                this.error = answer.error;
                if (!answer.error) this.cache[database] = answer.tables;
            } catch (e) {
                this.error = 'Could not read the tables.';
            }
            this.loading = false;
        },
        skipped(table) {
            return this.skip.includes(this.current + '.' + table) || this.skip.includes('*.' + table);
        },
        toggle(table) {
            const entry = this.current + '.' + table;
            this.skip = this.skip.includes(entry) ? this.skip.filter((item) => item !== entry) : [...this.skip, entry].sort();
        },
        setAll(skipAll) {
            const entries = this.tables.map((table) => this.current + '.' + table.name);
            const others = this.skip.filter((item) => !entries.includes(item));
            this.skip = skipAll ? [...others, ...entries].sort() : others;
        },
        count(database) {
            return this.skip.filter((item) => item.startsWith(database + '.')).length;
        },
        get shown() {
            const query = this.query.trim().toLowerCase();
            const list = query ? this.tables.filter((table) => table.name.toLowerCase().includes(query)) : [...this.tables];
            return this.order === 'size'
                ? list.sort((a, b) => (b.size || 0) - (a.size || 0))
                : list.sort((a, b) => a.name.localeCompare(b.name));
        },
        size(bytes) {
            if (bytes === null || bytes === undefined) return '';
            const units = ['B', 'KB', 'MB', 'GB', 'TB'];
            let value = bytes, unit = 0;
            while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit++; }
            return (unit === 0 ? value : value.toFixed(1)) + ' ' + units[unit];
        },
        get skippedSize() {
            return this.tables.filter((table) => this.skipped(table.name)).reduce((sum, table) => sum + (table.size || 0), 0);
        },
        get totalSize() {
            return this.tables.reduce((sum, table) => sum + (table.size || 0), 0);
        },
        addRule() {
            const rule = this.rule.trim();
            if (/^([A-Za-z0-9_-]{1,64}|\*)\.[A-Za-z0-9_$-]{1,64}$/.test(rule) && !this.skip.includes(rule)) {
                this.skip = [...this.skip, rule].sort();
            }
            this.rule = '';
        },
    }"
    x-init="databases.length && open(databases[0])"
>
    <div class="table-picker-grid">
        <div class="table-picker-dbs">
            <template x-for="database in databases" :key="database">
                <button type="button" class="table-picker-db" :class="current === database && 'is-active'" @click="open(database)">
                    <x-menu-icon name="database" class="size-4 shrink-0 text-muted" />
                    <span class="min-w-0 flex-1 truncate font-mono text-[12.5px]" x-text="database"></span>
                    <span class="pill" x-show="count(database) > 0" x-text="count(database) + ' skipped'"></span>
                </button>
            </template>
            <p class="px-2 py-3 text-[12.5px] text-muted" x-show="databases.length === 0">No databases found.</p>
        </div>

        <div class="table-picker-tables">
            <div class="flex flex-wrap items-center gap-2 border-b border-line p-2">
                <input class="input h-8 min-w-0 flex-1" type="search" x-model="query" placeholder="Search tables" aria-label="Search tables">
                <div class="segmented" role="group" aria-label="Sort tables">
                    <button type="button" class="text-[12px]" @click="order = 'name'" :aria-pressed="order === 'name'">Name</button>
                    <button type="button" class="text-[12px]" @click="order = 'size'" :aria-pressed="order === 'size'">Size</button>
                </div>
                <button class="btn btn-sm" type="button" @click="setAll(false)" :disabled="!tables.length">Back up all</button>
                <button class="btn btn-sm" type="button" @click="setAll(true)" :disabled="!tables.length">Skip all</button>
            </div>
            <div class="table-picker-list">
                <p class="flex items-center gap-2 p-3 text-[12.5px] text-muted" x-show="loading"><span class="loader-spinner" style="width:14px;height:14px"></span> Reading tables…</p>
                <p class="p-3 text-[12.5px] text-danger" x-show="error && !loading" x-text="error"></p>
                <template x-for="table in shown" :key="current + '.' + table.name">
                    <label class="table-picker-row" :class="skipped(table.name) && 'is-skipped'">
                        <input type="checkbox" :checked="!skipped(table.name)" :disabled="skip.includes('*.' + table.name)" @change="toggle(table.name)">
                        <span class="min-w-0 flex-1 truncate font-mono text-[12.5px]" x-text="table.name"></span>
                        <span class="text-[11.5px] text-muted" x-text="skip.includes('*.' + table.name) ? 'Skipped in every database' : (skipped(table.name) ? 'Skipped' : '')"></span>
                        <span class="w-24 shrink-0 text-right tabular-nums text-[12px] text-muted" x-text="size(table.size)" :title="table.rows !== null ? table.rows.toLocaleString() + ' rows' : ''"></span>
                    </label>
                </template>
                <p class="p-3 text-[12.5px] text-muted" x-show="!loading && !error && current && shown.length === 0">No tables.</p>
            </div>
            <p class="border-t border-line px-3 py-2 text-[12px] text-muted" x-show="tables.length && totalSize > 0">
                <span x-text="size(totalSize)"></span> in <span x-text="current"></span><template x-if="skippedSize > 0"><span> · <span x-text="size(skippedSize)"></span> skipped</span></template>
            </p>
        </div>
    </div>

    <div class="mt-3 flex flex-wrap items-center gap-2">
        <template x-for="entry in skip" :key="entry">
            <span class="pill font-mono">
                <span x-text="entry"></span>
                <button type="button" class="ml-1 opacity-70 hover:opacity-100" @click="skip = skip.filter((item) => item !== entry)" :aria-label="'Back up ' + entry">×</button>
            </span>
        </template>
        <span class="text-[12.5px] text-muted" x-show="skip.length === 0">Every table is backed up. Untick a table to leave it out.</span>
    </div>
    <div class="mt-2 flex max-w-md gap-2">
        <input class="input h-8 min-w-0 flex-1 font-mono text-[12.5px]" x-model="rule" @keydown.enter.prevent="addRule()" placeholder="*.sessions or app_db.telescope_entries" aria-label="Skip a table by name">
        <button class="btn btn-sm" type="button" @click="addRule()">Skip</button>
    </div>
</div>
