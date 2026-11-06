{{-- The SQL editor (CodeMirror): syntax colors and autocomplete of keywords, tables and columns.
     The hidden textarea keeps wire:model="sql"; run(selection, sql) comes from the parent's x-data. --}}
<div class="sql-editor" wire:ignore
    x-data="sqlEditor(@js($dialect), @js($placeholder))"
    @sql-run.window="runNow()"
    @sql-schema-changed.window="loadSchema()"
    @sql-set.window="setSql($event.detail)">
    <textarea x-ref="source" class="sr-only" wire:model="sql" tabindex="-1" aria-hidden="true"></textarea>
    <div x-ref="host" class="sql-editor-host"></div>
</div>
