{{-- The SQL box. $sqlTarget: the database it runs in; $sqlChoices (optional): databases to pick from. --}}
<div class="grid gap-4" x-data="{ run() { const box = $refs.sql; $wire.runSql(box.value.slice(box.selectionStart, box.selectionEnd), box.value); } }">
    <x-layer-card title="SQL" helper="{{ $sqlTarget !== '' ? 'Runs in '.$sqlTarget.'.' : 'Choose the database it runs in.' }} A selection runs on its own; otherwise the whole box runs. Ctrl+Enter runs. At most 200 rows come back.">
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
        <textarea x-ref="sql" class="textarea min-h-40 font-mono text-[12.5px]" wire:model="sql" spellcheck="false" aria-label="SQL" placeholder="SELECT * FROM table_name" @keydown.ctrl.enter.prevent="run()" @keydown.meta.enter.prevent="run()"></textarea>
        <div class="mt-3 flex flex-wrap items-center justify-between gap-2">
            <p class="text-[12.5px] text-muted">SQL you type here is not added to the change log, so it cannot be undone. Edits from the row dialog still can.</p>
            <button class="btn btn-primary" type="button" wire:loading.attr="disabled" wire:target="runSql" @click="run()"><x-menu-icon name="play" /> Run</button>
        </div>
    </x-layer-card>

    @if ($sqlError)
        <div class="callout"><x-menu-icon name="danger" class="size-4 shrink-0 text-warning" /><p class="min-w-0 flex-1">{{ $sqlError }}</p></div>
    @endif

    @foreach ($sqlResults as $index => $result)
        @if ($result['kind'] === 'rows')
            <x-layer-card title="{{ \Illuminate\Support\Str::limit($result['sql'], 80) }}" helper="{{ $result['truncated'] ? 'Showing 200 rows. More exist.' : count($result['rows']).' '.(count($result['rows']) === 1 ? 'row' : 'rows') }}" flush>
                <x-slot:actions>
                    @if ($result['rows'] !== [])
                        <button class="btn btn-sm" type="button" wire:click="downloadInserts({{ $index }})"><x-menu-icon name="download" class="size-4" /> SQL inserts</button>
                    @endif
                </x-slot:actions>
                @if ($result['rows'] === [])
                    <p class="table-empty">No rows.</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="table">
                            <thead>
                                <tr>
                                    @foreach ($result['columns'] as $column)
                                        <th class="font-mono">{{ $column }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($result['rows'] as $row)
                                    <tr>
                                        @foreach ($result['columns'] as $column)
                                            <td class="font-mono text-[13px]">
                                                @if (($row[$column] ?? null) === null)
                                                    <span class="italic text-muted">NULL</span>
                                                @else
                                                    {{ \Illuminate\Support\Str::limit($row[$column], 120) }}
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </x-layer-card>
        @else
            <div class="callout"><x-menu-icon name="check" class="size-4 shrink-0 text-success" /><p class="min-w-0 flex-1">{{ $result['message'] }}</p></div>
        @endif
    @endforeach
</div>
