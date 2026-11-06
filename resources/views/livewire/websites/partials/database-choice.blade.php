{{-- A database for the new website. PostgreSQL and MongoDB can be picked when the server does not have them yet: the form asks to install them first. --}}
@php
    $engines = \App\Support\DatabaseEngines::available();
    $missing = array_values(array_filter(\App\Services\DatabaseServers::INSTALLABLE, fn ($engine) => ! \App\Support\DatabaseEngines::has($engine)));
    $chosenEngine = $db_engine !== '' && in_array($db_engine, [...$engines, ...$missing], true) ? $db_engine : $engines[0];
    $chosenMissing = in_array($chosenEngine, $missing, true);
    $chosenLabel = \App\Support\DatabaseEngines::label($chosenEngine);
@endphp

<div class="flex flex-col gap-2" x-data="{ on: $wire.entangle('provision_database') }">
    @include('livewire.partials.followed-tasks')
    <label class="flex items-center gap-2">
        <input type="checkbox" x-model="on">
        <span>Create a database for this site</span>
    </label>
    <div class="field pl-6" x-show="on" x-cloak>
        <div class="flex flex-wrap gap-2">
            @foreach ([...$engines, ...$missing] as $engine)
                <button class="choice {{ $chosenEngine === $engine ? 'active' : '' }}" type="button" wire:click="$set('db_engine', '{{ $engine }}')">
                    {{ \App\Support\DatabaseEngines::label($engine) }}
                    @if (in_array($engine, $missing, true))
                        <span class="db-not-installed">not installed</span>
                    @endif
                </button>
            @endforeach
        </div>
        @if ($chosenMissing)
            <div class="callout mt-1">
                <x-menu-icon name="database" class="size-4 shrink-0 text-warning" />
                <div class="grid min-w-0 flex-1 gap-2">
                    <p>{{ $chosenLabel }} is not installed on this server. Do you want to install it now? It takes a few minutes and runs in the background; then create the site. Or use {{ collect($engines)->map(fn ($engine) => \App\Support\DatabaseEngines::label($engine))->implode(' or ') }}.</p>
                    <div class="flex flex-wrap gap-2">
                        <button class="btn btn-primary btn-sm" type="button" wire:click="installDatabaseEngine('{{ $chosenEngine }}')"><x-menu-icon name="download" class="size-4" /> Install {{ $chosenLabel }}</button>
                        @foreach ($engines as $engine)
                            <button class="btn btn-sm" type="button" wire:click="$set('db_engine', '{{ $engine }}')">Use {{ \App\Support\DatabaseEngines::label($engine) }}</button>
                        @endforeach
                    </div>
                </div>
            </div>
        @else
            <small class="text-muted">{{ \App\Support\DatabaseEngines::hint($chosenEngine) }}</small>
        @endif
        @error('db_engine') <small class="error">{{ $message }}</small> @enderror
    </div>
</div>
