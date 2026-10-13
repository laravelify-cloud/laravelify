{{-- Look inside a backup: tables, procedures, functions, triggers, and events, or the folders of a file backup. --}}
@php
    $perPage = \App\Livewire\Backups\Index::TABLES_PER_PAGE;
    $search = mb_strtolower(trim($viewSearch));
    $items = [];
    $pages = 1;

    if ($viewRun && $viewRun->isDatabase() && $viewManifest) {
        $all = array_values(array_filter($viewManifest[$viewTab] ?? [], fn ($item) => $search === '' || str_contains(mb_strtolower($item['name']), $search)));
        $pages = max(1, (int) ceil(count($all) / $perPage));
        $page = min(max(1, $viewPage), $pages);
        $items = array_slice($all, ($page - 1) * $perPage, $perPage);
        $total = count($all);
    }
@endphp

<x-modal-frame class="modal-wide" open="$wire.viewRunId !== null" close="$wire.closeView()">
    @if ($viewRun)
        <div class="modal-head mb-1">
            <h2>Inside <span class="font-mono">{{ $viewRun->target }}</span></h2>
            <button class="icon-btn" type="button" wire:click="closeView" aria-label="Close"><x-menu-icon name="close" /></button>
        </div>
        <p class="mb-4 text-[13px] text-muted">{{ $time->datetime($viewRun->created_at) }} · {{ $viewRun->sizeLabel() }} · {{ $viewRun->schedule?->name ?? 'Deleted schedule' }}</p>

        @if (! $viewRun->isDatabase())
            @include('livewire.backups.partials.archive-browser', ['manifest' => $viewManifest, 'run' => $viewRun, 'selectable' => false])
        @elseif ($viewManifest === null)
            <div class="pick-list">
                <p class="flex items-center justify-center gap-2 bg-card px-3 py-10 text-center text-muted">
                    @if (in_array($viewRun->id, $inspecting, true))
                        <span class="task-spinner"></span> Reading the backup. This downloads it once; big backups take a moment.
                    @else
                        The contents list of this backup is not available.
                    @endif
                </p>
            </div>
        @else
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div class="segmented" role="tablist" aria-label="Part of the backup">
                    @foreach ($parts as $key => $label)
                        <button type="button" role="tab" aria-pressed="{{ $viewTab === $key ? 'true' : 'false' }}" wire:click="setViewTab('{{ $key }}')">
                            <span class="inline-flex items-center gap-1.5">{{ \Illuminate\Support\Str::before($label, ' (') }} <span class="text-muted tabular-nums">{{ count($viewManifest[$key] ?? []) }}</span></span>
                        </button>
                    @endforeach
                </div>
                <label class="fm-search">
                    <x-menu-icon name="search" class="size-4" />
                    <input type="search" wire:model.live.debounce.250ms="viewSearch" placeholder="Search {{ strtolower(\Illuminate\Support\Str::before($parts[$viewTab], ' (')) }}" autocomplete="off">
                </label>
            </div>

            <div class="pick-list" style="max-height: 26rem">
                @forelse ($items as $item)
                    <div class="bg-card" wire:key="vi-{{ $viewTab }}-{{ $item['name'] }}">
                        <button class="pick-item" type="button" wire:click="showItem(@js($item['name']))" aria-expanded="{{ $viewItem === $item['name'] ? 'true' : 'false' }}">
                            <x-menu-icon name="{{ $viewTab === 'tables' ? 'table' : 'variables' }}" />
                            <span class="min-w-0 flex-1 truncate font-mono text-[13px]">{{ $item['name'] }}</span>
                            @if ($viewTab === 'tables')
                                <span class="shrink-0 text-[12px] tabular-nums text-muted">≈{{ number_format($item['rows']) }} rows · {{ $bytes($item['bytes']) }}</span>
                            @elseif ($viewTab === 'triggers' && ($item['table'] ?? '') !== '')
                                <span class="shrink-0 text-[12px] text-muted">on {{ $item['table'] }}</span>
                            @endif
                            <x-menu-icon name="{{ $viewItem === $item['name'] ? 'chevron-down' : 'chevron-right' }}" />
                        </button>
                        @if ($viewItem === $item['name'])
                            @php $sql = $viewTab === 'tables' ? ($item['create'] ?: '-- No CREATE TABLE statement found.') : $item['sql']; @endphp
                            <div class="px-3 pb-3" x-data>
                                <div class="mb-1.5 flex justify-end">
                                    <button class="btn btn-sm" type="button" @click="navigator.clipboard && navigator.clipboard.writeText(@js($sql)).then(() => window.toast('SQL copied.'))"><x-menu-icon name="copy" /> Copy SQL</button>
                                </div>
                                <pre class="sql-block">{{ $sql }}</pre>
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="bg-card px-3 py-8 text-center text-muted">{{ $search !== '' ? 'Nothing matches.' : 'This backup has none.' }}</p>
                @endforelse
            </div>

            @if ($pages > 1)
                <div class="pager">
                    <span>{{ $total }} {{ strtolower(\Illuminate\Support\Str::before($parts[$viewTab], ' (')) }} · page {{ min(max(1, $viewPage), $pages) }} of {{ $pages }}</span>
                    <span class="flex gap-1.5">
                        <button class="btn btn-sm" type="button" wire:click="$set('viewPage', {{ max(1, $viewPage - 1) }})" @disabled($viewPage <= 1)>Previous</button>
                        <button class="btn btn-sm" type="button" wire:click="$set('viewPage', {{ min($pages, $viewPage + 1) }})" @disabled($viewPage >= $pages)>Next</button>
                    </span>
                </div>
            @endif
        @endif

        <div class="modal-foot">
            <button class="btn mr-auto" type="button" wire:click="openDownload({{ $viewRun->id }})" @click="$wire.closeView()"><x-menu-icon name="download" /> Download</button>
            <button class="btn" type="button" wire:click="closeView">Close</button>
            <button class="btn btn-primary" type="button" wire:click="openRestore({{ $viewRun->id }})" @click="$wire.closeView()"><x-menu-icon name="restore" /> Restore</button>
        </div>
    @endif
</x-modal-frame>
