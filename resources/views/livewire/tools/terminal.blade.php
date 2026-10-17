<div class="flex flex-col gap-6">
    <x-page-header title="Terminal" summary="Run commands in any site folder. Commands run as that site's user, can write only inside its folder, and stop after 15 minutes. Click in the window to answer a question a command asks.">
        <x-slot:actions>
            <span class="pill font-mono">{{ $root }}</span>
        </x-slot:actions>
    </x-page-header>

    <livewire:websites.terminal key="global-terminal" />
</div>
