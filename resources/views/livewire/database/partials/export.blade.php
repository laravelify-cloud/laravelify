@if (session('export_error'))
    <div class="callout"><x-menu-icon name="danger" class="size-4 shrink-0 text-warning" /><p class="min-w-0 flex-1">{{ session('export_error') }}</p></div>
@endif

<x-modal-frame class="modal-medium" open="$wire.exportOpen" close="$wire.set('exportOpen', false)">
    <form class="grid gap-4" method="POST" action="{{ route('database.export') }}">
        @csrf
        <input type="hidden" name="database" value="{{ $exportDatabase }}">
        @if ($exportSingle)
            <input type="hidden" name="single" value="1">
            @foreach ($exportTables as $name)
                <input type="hidden" name="tables[]" value="{{ $name }}">
            @endforeach
        @endif
        <div class="modal-head">
            <div class="min-w-0">
                <p class="text-[12px] text-muted">Export SQL</p>
                <h2 class="mt-1 truncate font-mono">{{ $exportSingle ? ($exportTables[0] ?? $exportDatabase) : $exportDatabase }}</h2>
            </div>
            <button class="icon-btn" type="button" wire:click="$set('exportOpen', false)" aria-label="Close"><x-menu-icon name="close" /></button>
        </div>
        <label class="grid gap-2">
            <span class="text-[13px] font-medium">What to include</span>
            <select class="select" name="contents" aria-label="What to include">
                <option value="both" selected>Structure and data</option>
                <option value="structure">Structure only</option>
                <option value="data">Data only</option>
            </select>
        </label>
        @unless ($exportSingle)
            <label class="check-card">
                <input class="mt-0.5" type="checkbox" name="routines" value="1" checked>
                <span><span class="block font-medium">Routines, triggers, and events</span><span class="block text-[12.5px] text-muted">Included when the whole database is exported. A data-only file leaves them out.</span></span>
            </label>
            <div class="grid gap-2">
                <span class="text-[13px] font-medium">Tables</span>
                @if ($exportTables === [])
                    <p class="text-[12.5px] text-muted">This database has no tables. The file still includes views when you export the whole database.</p>
                @else
                    <div class="max-h-64 overflow-auto divide-y divide-line">
                        @foreach ($exportTables as $name)
                            <label class="flex items-center gap-2 px-3 py-2 text-[13px]"><input type="checkbox" name="tables[]" value="{{ $name }}" checked> <span class="font-mono">{{ $name }}</span></label>
                        @endforeach
                    </div>
                    <p class="text-[12.5px] text-muted">Every table includes views as well. A smaller choice is only those tables.</p>
                @endif
            </div>
        @endunless
        <div class="flex justify-end gap-2">
            <button class="btn" type="button" wire:click="$set('exportOpen', false)">Cancel</button>
            <button class="btn btn-primary" type="submit"><x-menu-icon name="download" /> Download .sql</button>
        </div>
    </form>
</x-modal-frame>
