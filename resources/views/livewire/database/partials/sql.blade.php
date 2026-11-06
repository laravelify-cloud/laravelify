{{-- The SQL box. $sqlTarget: the database it runs in; $sqlChoices (optional): databases to pick from. --}}
<div class="grid gap-4" x-data="{ run(selection, sql) { $wire.runSql(selection, sql); } }">
    <x-layer-card title="SQL" helper="{{ $sqlTarget !== '' ? 'Runs in '.$sqlTarget.'.' : 'Choose the database it runs in.' }} A selection runs on its own; otherwise the whole box runs. Ctrl+Enter runs. At most 1,000 rows come back.">
        @isset($sqlChoices)
            <x-slot:actions>
                <select class="select h-8 w-auto font-mono text-[12.5px]" wire:model.live="sqlIn" aria-label="Database">
                    <option value="">Choose a database</option>
                    @foreach ($sqlChoices as $choice)
                        <option value="{{ $choice }}">{{ $choice }}</option>
                    @endforeach
                </select>
            </x-slot:actions>
        @endisset
        @include('livewire.database.partials.sql-editor', ['dialect' => 'mysql', 'placeholder' => 'SELECT * FROM table_name'])
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <p class="text-[12.5px] text-muted">SQL you type here is not added to the change log, so it cannot be undone. Edits from the row dialog still can.</p>
            <button class="btn btn-primary" type="button" wire:loading.attr="disabled" wire:target="runSql" @click="$dispatch('sql-run')"><x-menu-icon name="play" /> Run</button>
        </div>
    </x-layer-card>

    @if ($sqlError)
        <div class="callout"><x-menu-icon name="danger" class="size-4 shrink-0 text-warning" /><p class="min-w-0 flex-1">{{ $sqlError }}</p></div>
    @endif

    @foreach ($sqlResults as $index => $result)
        @if ($result['kind'] === 'rows')
            <x-layer-card title="{{ \Illuminate\Support\Str::limit($result['sql'], 80) }}" helper="{{ $result['truncated'] ? 'Showing the first 1,000 rows. More exist.' : count($result['rows']).' '.(count($result['rows']) === 1 ? 'row' : 'rows') }}" flush>
                <x-slot:actions>
                    @if ($result['rows'] !== [])
                        <button class="btn btn-sm" type="button" wire:click="downloadInserts({{ $index }})"><x-menu-icon name="download" class="size-4" /> SQL inserts</button>
                    @endif
                </x-slot:actions>
                @if ($result['rows'] === [])
                    <p class="table-empty">No rows.</p>
                @else
                    @include('livewire.database.partials.result-grid', [
                        'columns' => $result['columns'],
                        'rows' => array_map(fn (array $row): array => array_map(fn (string $column) => $row[$column] ?? null, $result['columns']), $result['rows']),
                    ])
                @endif
            </x-layer-card>
        @else
            <div class="callout"><x-menu-icon name="check" class="size-4 shrink-0 text-success" /><p class="min-w-0 flex-1">{{ $result['message'] }}</p></div>
        @endif
    @endforeach
</div>
