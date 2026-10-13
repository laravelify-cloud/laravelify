<div class="flex flex-col gap-6">
    <x-page-header title="Terminal" summary="Run commands in any site folder. Commands run as www-data, can write only inside the sites folder, and stop after 60 seconds.">
        <x-slot:actions>
            <span class="pill font-mono">{{ $root }}</span>
        </x-slot:actions>
    </x-page-header>

    <livewire:websites.terminal key="global-terminal" />
</div>
