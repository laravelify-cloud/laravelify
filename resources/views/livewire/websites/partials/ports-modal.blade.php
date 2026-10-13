{{--
    "Used ports" button and modal: every reserved or listening port on the server and who uses it.
    $model is the Livewire property the "Use" button fills (port or http_port).
--}}
<div class="inline-flex" x-data="{
    open: false,
    loading: false,
    failed: false,
    query: '',
    scope: 'all',
    data: { rows: [], next_free: null, checked: true },
    async show() {
        this.open = true;
        this.loading = true;
        this.failed = false;
        try {
            this.data = await $wire.portMap();
        } catch (error) {
            this.failed = true;
        }
        this.loading = false;
        this.$nextTick(() => this.$refs.search && this.$refs.search.focus());
    },
    get rows() {
        const query = this.query.trim().toLowerCase();
        return this.data.rows.filter((row) => {
            if (this.scope === 'sites' && row.kind !== 'site') return false;
            if (this.scope === 'listening' && !row.listening) return false;
            if (!query) return true;
            return [String(row.port), row.owner, row.detail, row.processes.join(' ')].join(' ').toLowerCase().includes(query);
        });
    },
    use(port) {
        $wire.set(@js($model), port);
        this.open = false;
    },
}">
    <button class="btn" type="button" @click="show()" title="See which ports are taken">
        <x-menu-icon name="list" /> Used ports
    </button>

    <template x-teleport="body">
        <x-modal-frame class="ports-modal" open="open" close="open = false">
            <div class="modal-head">
                <div>
                    <h2>Ports on this server</h2>
                    <p class="text-[12.5px] text-muted">Reserved by sites and services, and what is listening right now.</p>
                </div>
                <button class="icon-btn" type="button" @click="open = false" aria-label="Close"><x-menu-icon name="close" /></button>
            </div>

            <template x-if="data.next_free && !loading">
                <div class="ports-free">
                    <span class="mon-dot is-up"></span>
                    <span>Next free port: <b class="font-mono" x-text="data.next_free"></b></span>
                    <button class="btn btn-primary ml-auto h-7 px-2.5 text-[12px]" type="button" @click="use(data.next_free)">Use it</button>
                </div>
            </template>

            <div class="mt-3 flex flex-wrap items-center gap-2">
                <input class="input h-8 min-w-0 flex-1" type="search" x-ref="search" x-model="query" placeholder="Search port, site, or process">
                <div class="segmented" role="group" aria-label="Filter ports">
                    <button type="button" @click="scope = 'all'" :aria-pressed="scope === 'all'" class="text-[12px]">All</button>
                    <button type="button" @click="scope = 'sites'" :aria-pressed="scope === 'sites'" class="text-[12px]">Sites</button>
                    <button type="button" @click="scope = 'listening'" :aria-pressed="scope === 'listening'" class="text-[12px]">Listening</button>
                </div>
            </div>

            <div class="ports-list mt-3">
                <template x-if="loading">
                    <div class="flex items-center justify-center gap-2.5 py-10 text-[13px] text-muted"><span class="loader-spinner" style="width:16px;height:16px"></span> Checking ports…</div>
                </template>
                <template x-if="failed && !loading">
                    <p class="py-10 text-center text-[13px] text-danger">Could not read the ports. Try again.</p>
                </template>
                <template x-if="!loading && !failed">
                    <div>
                        <template x-for="row in rows" :key="row.port">
                            <div class="ports-row">
                                <span class="ports-port font-mono" x-text="row.port"></span>
                                <div class="min-w-0 flex-1">
                                    <p class="flex flex-wrap items-center gap-1.5">
                                        <template x-if="row.url"><a class="truncate font-medium hover:text-accent" :href="row.url" x-text="row.owner"></a></template>
                                        <template x-if="!row.url"><span class="truncate font-medium" x-text="row.owner"></span></template>
                                        <span class="pill" x-text="row.kind === 'site' ? 'Site' : (row.kind === 'service' ? 'Service' : 'Other')"></span>
                                    </p>
                                    <p class="truncate text-[12px] text-muted">
                                        <span x-text="row.detail"></span>
                                        <template x-if="row.processes.length"><span class="font-mono" x-text="' · ' + row.processes.join(', ')"></span></template>
                                    </p>
                                </div>
                                <span class="status-badge shrink-0" :style="'--status-color:' + (row.listening ? 'var(--color-success)' : 'var(--color-muted)')" x-text="row.listening ? (row.public ? 'Listening · public' : 'Listening · local') : 'Reserved'"></span>
                            </div>
                        </template>
                        <p class="py-8 text-center text-[13px] text-muted" x-show="rows.length === 0">No ports match.</p>
                    </div>
                </template>
            </div>

            <p class="mt-3 text-[12px] text-muted" x-show="!loading && !data.checked">Live listening ports are only checked on the server; this list shows reserved ports.</p>
        </x-modal-frame>
    </template>
</div>
