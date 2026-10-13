{{-- Download a backup: chosen parts as one .sql file, or the original backup file. --}}
<x-modal-frame class="modal-medium" open="$wire.downloadRunId !== null" close="$wire.closeDownload()">
    @if ($downloadRun)
        <form class="grid gap-4" wire:submit="startDownload">
            <div class="modal-head">
                <h2>Download <span class="font-mono">{{ $downloadRun->target }}</span></h2>
                <button class="icon-btn" type="button" wire:click="closeDownload" aria-label="Close"><x-menu-icon name="close" /></button>
            </div>
            <p class="-mt-2 text-[13px] text-muted">{{ $time->datetime($downloadRun->created_at) }} · {{ $downloadRun->sizeLabel() }}</p>

            @if ($downloadRun->isDatabase())
                <div class="segmented self-start" role="group">
                    <button type="button" aria-pressed="{{ $downloadMode === 'parts' ? 'true' : 'false' }}" wire:click="$set('downloadMode', 'parts')">Choose parts (.sql)</button>
                    <button type="button" aria-pressed="{{ $downloadMode === 'original' ? 'true' : 'false' }}" wire:click="$set('downloadMode', 'original')">Whole backup (.sql.gz)</button>
                </div>

                @if ($downloadMode === 'parts')
                    <div class="grid gap-2 sm:grid-cols-2">
                        @foreach ($parts as $key => $label)
                            <label class="check-card" wire:key="dp-{{ $key }}">
                                <input class="mt-0.5" type="checkbox" wire:model.live="downloadParts" value="{{ $key }}">
                                <span>
                                    <span class="block font-medium">{{ $label }}</span>
                                    <span class="block text-[12px] text-muted">{{ $key === 'tables' ? 'CREATE TABLE and every row' : 'Safe to run twice (DROP IF EXISTS first)' }}</span>
                                </span>
                            </label>
                        @endforeach
                    </div>
                    @error('downloadParts') <small class="error">{{ $message }}</small> @enderror

                    @if (in_array('tables', $downloadParts, true))
                        <div class="grid gap-2">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" wire:model.live="downloadAllTables">
                                <span class="font-medium">All tables</span>
                            </label>

                            @unless ($downloadAllTables)
                                <label class="fm-search">
                                    <x-menu-icon name="search" class="size-4" />
                                    <input type="search" wire:model.live.debounce.250ms="tableSearch" placeholder="Search tables" autocomplete="off">
                                </label>
                                <div class="pick-list" style="max-height: 16rem">
                                    @forelse ($downloadTableList as $table)
                                        <label @class(['pick-item cursor-pointer', 'is-selected' => in_array($table['name'], $downloadTables, true)]) wire:key="dt-{{ $table['name'] }}">
                                            <input type="checkbox" wire:model.live="downloadTables" value="{{ $table['name'] }}">
                                            <x-menu-icon name="table" />
                                            <span class="min-w-0 flex-1 truncate font-mono text-[13px]">{{ $table['name'] }}</span>
                                            @if ($table['rows'] !== null)
                                                <span class="shrink-0 text-[12px] tabular-nums text-muted">≈{{ number_format($table['rows']) }} rows</span>
                                            @endif
                                        </label>
                                    @empty
                                        <p class="bg-card px-3 py-6 text-center text-muted">No tables match.</p>
                                    @endforelse
                                </div>
                                <small class="text-muted">{{ count($downloadTables) }} selected. Triggers follow the tables you pick.</small>
                                @error('downloadTables') <small class="error">{{ $message }}</small> @enderror
                            @endunless
                        </div>
                    @endif
                @else
                    <p class="text-[13px] text-muted">The backup exactly as it is stored: one gzipped mysqldump with every table, routine, trigger, and event.</p>
                @endif
            @else
                <p class="text-[13px] text-muted">The backup exactly as it is stored: one <code>.tar.gz</code> archive. To get only some folders back on the server, use Restore and pick them.</p>
            @endif

            <p class="text-[12.5px] text-muted">The file is prepared on the server first (the backup is downloaded from your bucket), then your browser downloads it. Progress shows at the top of this page.</p>

            <div class="modal-foot">
                <button class="btn" type="button" wire:click="closeDownload">Cancel</button>
                <button class="btn btn-primary" type="submit" wire:loading.attr="disabled" wire:target="startDownload"><x-menu-icon name="download" /> Prepare download</button>
            </div>
        </form>
    @endif
</x-modal-frame>
