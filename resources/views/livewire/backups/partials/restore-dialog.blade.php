{{-- Restore in three steps: what (a database, or a file schedule), which backup, and how. --}}
@php
    $steps = [1 => $isDatabase ? 'Database' : 'Backup set', 2 => 'Backup', 3 => 'What to restore'];
@endphp

<x-modal-frame class="modal-wide" open="$wire.restoreOpen" close="$wire.closeRestore()">
    <div class="modal-head mb-2">
        <h2>Restore {{ $isDatabase ? 'a database' : 'files' }}</h2>
        <button class="icon-btn" type="button" wire:click="closeRestore" aria-label="Close"><x-menu-icon name="close" /></button>
    </div>

    <div class="steps" aria-label="Steps">
        @foreach ($steps as $number => $label)
            <span @class(['step', 'is-current' => $restoreStep === $number, 'is-done' => $restoreStep > $number])>
                <span class="step-number">{{ $restoreStep > $number ? '✓' : $number }}</span> {{ $label }}
            </span>
            @unless ($loop->last)
                <x-menu-icon name="chevron-right" class="size-3.5" />
            @endunless
        @endforeach
    </div>

    {{-- 1. What --}}
    @if ($restoreStep === 1)
        <p class="mb-3 text-[13px] text-muted">{{ $isDatabase ? 'Choose the database to restore.' : 'Choose the set of folders to restore from.' }}</p>
        <div class="pick-list">
            @forelse ($restoreTargets as $target)
                <button class="pick-item" type="button" wire:click="chooseRestoreTarget(@js($target['key']))" wire:key="rt-{{ $target['key'] }}">
                    <x-menu-icon name="{{ $isDatabase ? 'database' : 'backup' }}" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-medium {{ $isDatabase ? 'font-mono' : '' }}">{{ $target['label'] }}</span>
                        @if ($target['detail'] ?? null)
                            <span class="block truncate font-mono text-[12px] text-muted">{{ $target['detail'] }}</span>
                        @endif
                    </span>
                    <span class="shrink-0 text-right text-[12px] text-muted">
                        {{ $target['count'] }} {{ \Illuminate\Support\Str::plural('backup', $target['count']) }}
                        <span class="block">latest {{ $time->ago($target['latest']->created_at) }}</span>
                    </span>
                    <x-menu-icon name="chevron-right" />
                </button>
            @empty
                <p class="bg-card px-3 py-8 text-center text-muted">No backups to restore yet.</p>
            @endforelse
        </div>
    @endif

    {{-- 2. Which backup --}}
    @if ($restoreStep === 2)
        <p class="mb-3 text-[13px] text-muted">Choose the backup of <span class="font-mono text-ink">{{ $restoreChoices->first()?->isDatabase() ? $restoreTarget : ($restoreChoices->first()?->schedule?->name ?? '') }}</span> to restore from. Newest first.</p>
        <div class="pick-list">
            @forelse ($restoreChoices as $choice)
                <button class="pick-item" type="button" wire:click="chooseRestoreRun({{ $choice->id }})" wire:key="rc-{{ $choice->id }}">
                    <x-menu-icon name="clock" />
                    <span class="min-w-0 flex-1">
                        <span class="block font-medium">{{ $time->datetime($choice->created_at) }}</span>
                        <span class="block text-[12px] text-muted">{{ $choice->trigger === 'manual' ? 'Run now' : 'Scheduled' }} · {{ $choice->storage?->name }}{{ $choice->durationLabel() ? ' · took '.$choice->durationLabel() : '' }}</span>
                    </span>
                    <span class="shrink-0 text-[12px] text-muted">{{ $choice->sizeLabel() }}</span>
                    <x-menu-icon name="chevron-right" />
                </button>
            @empty
                <p class="bg-card px-3 py-8 text-center text-muted">No backups left for this choice.</p>
            @endforelse
        </div>
    @endif

    {{-- 3. How --}}
    @if ($restoreStep === 3 && $restoreRun)
        <form class="grid gap-4" wire:submit="restore">
            <div class="callout">
                <x-menu-icon name="{{ $isDatabase ? 'database' : 'backup' }}" class="size-4 shrink-0 text-muted" />
                <p class="min-w-0 flex-1 text-[13px]">
                    <span class="font-mono font-medium">{{ $restoreRun->target }}</span>
                    <span class="text-muted">· {{ $time->datetime($restoreRun->created_at) }} · {{ $restoreRun->sizeLabel() }}</span>
                </p>
            </div>

            @if ($restoreRun->isDatabase())
                <label class="field">
                    <span>Restore into database</span>
                    <input class="input font-mono" wire:model.live.debounce.400ms="restoreDatabase">
                    <small class="text-muted">
                        @if ($restoreDatabase === $restoreRun->target)
                            Same name: the live database is overwritten.
                        @else
                            A different name restores next to the live database. It is created when it does not exist.
                        @endif
                    </small>
                    @error('restoreDatabase') <small class="error">{{ $message }}</small> @enderror
                </label>

                <div class="field">
                    <span>What to restore</span>
                    <div class="segmented self-start" role="group">
                        <button type="button" aria-pressed="{{ $restoreWhole ? 'true' : 'false' }}" wire:click="$set('restoreWhole', true)">Whole database</button>
                        <button type="button" aria-pressed="{{ $restoreWhole ? 'false' : 'true' }}" wire:click="$set('restoreWhole', false)">Some tables</button>
                    </div>
                </div>

                @unless ($restoreWhole)
                    <div class="grid gap-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <label class="fm-search flex-1">
                                <x-menu-icon name="search" class="size-4" />
                                <input type="search" wire:model.live.debounce.250ms="tableSearch" placeholder="Search tables" autocomplete="off">
                            </label>
                            <button class="btn btn-sm" type="button" wire:click="selectTables(true)">Select {{ $tableSearch === '' ? 'all' : 'matching' }}</button>
                            <button class="btn btn-sm" type="button" wire:click="selectTables(false)">Clear</button>
                            <span class="text-[12px] text-muted">{{ count($restoreTables) }} selected</span>
                        </div>
                        <div class="pick-list">
                            @forelse ($restoreTableList as $table)
                                <label @class(['pick-item cursor-pointer', 'is-selected' => in_array($table['name'], $restoreTables, true)]) wire:key="rtt-{{ $table['name'] }}">
                                    <input type="checkbox" wire:model.live="restoreTables" value="{{ $table['name'] }}">
                                    <x-menu-icon name="table" />
                                    <span class="min-w-0 flex-1 truncate font-mono text-[13px]">{{ $table['name'] }}</span>
                                    @if ($table['rows'] !== null)
                                        <span class="shrink-0 text-[12px] tabular-nums text-muted">≈{{ number_format($table['rows']) }} rows · {{ $bytes($table['bytes']) }}</span>
                                    @endif
                                </label>
                            @empty
                                <p class="bg-card px-3 py-6 text-center text-muted">No tables match.</p>
                            @endforelse
                        </div>
                        @error('restoreTables') <small class="error">{{ $message }}</small> @enderror
                    </div>
                @endunless
            @else
                <div class="field">
                    <span>What to restore</span>
                    <div class="segmented self-start" role="group">
                        <button type="button" aria-pressed="{{ $restoreWhole ? 'true' : 'false' }}" wire:click="$set('restoreWhole', true)">Everything</button>
                        <button type="button" aria-pressed="{{ $restoreWhole ? 'false' : 'true' }}" wire:click="$set('restoreWhole', false)">Choose folders and files</button>
                    </div>
                </div>

                @unless ($restoreWhole)
                    @include('livewire.backups.partials.archive-browser', ['manifest' => $restoreManifest, 'run' => $restoreRun, 'selectable' => true])
                    @if ($restoreMembers !== [])
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($restoreMembers as $member)
                                <span class="pill gap-1 font-mono" wire:key="rm-{{ $member }}">
                                    {{ $member }}
                                    <button type="button" class="opacity-70 hover:opacity-100" wire:click="toggleMember(@js($member))" aria-label="Remove"><x-menu-icon name="close" class="size-3.5" /></button>
                                </span>
                            @endforeach
                        </div>
                    @endif
                    @error('restoreMembers') <small class="error">{{ $message }}</small> @enderror
                @endunless

                <div class="field">
                    <span>Where</span>
                    <div class="segmented self-start" role="group">
                        <button type="button" aria-pressed="{{ $restoreToOriginal ? 'false' : 'true' }}" wire:click="$set('restoreToOriginal', false)">A new restore folder</button>
                        <button type="button" aria-pressed="{{ $restoreToOriginal ? 'true' : 'false' }}" wire:click="$set('restoreToOriginal', true)">Original place</button>
                    </div>
                    <small class="text-muted">{{ $restoreToOriginal ? 'Files with the same name are overwritten.' : 'Safest: everything goes into laravelify-restore/ in the file manager root, and nothing is overwritten.' }}</small>
                </div>
            @endif

            @if ($restoreRun->isDatabase() || $restoreToOriginal)
                <label class="modal-warning mb-0 flex items-start gap-2">
                    <input class="mt-1" type="checkbox" wire:model="understood">
                    <span>I understand this overwrites {{ $restoreRun->isDatabase() ? 'data in '.($restoreDatabase ?: 'the database') : 'files' }} with the backup.</span>
                </label>
                @error('understood') <small class="error">{{ $message }}</small> @enderror
            @endif

            <div class="modal-foot">
                <button class="btn mr-auto" type="button" wire:click="restoreBack"><x-menu-icon name="up" class="-rotate-90" /> Back</button>
                <button class="btn" type="button" wire:click="closeRestore">Cancel</button>
                <button class="btn btn-primary" type="submit" wire:loading.attr="disabled" wire:target="restore"><x-menu-icon name="restore" /> Start restore</button>
            </div>
        </form>
    @elseif ($restoreStep === 2)
        <div class="modal-foot">
            <button class="btn mr-auto" type="button" wire:click="restoreBack"><x-menu-icon name="up" class="-rotate-90" /> Back</button>
            <button class="btn" type="button" wire:click="closeRestore">Cancel</button>
        </div>
    @endif
</x-modal-frame>
