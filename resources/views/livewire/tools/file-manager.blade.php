<div class="flex flex-col gap-6">
    <x-page-header title="File manager" summary="Browse, edit, upload, and download files across every site. Changes are made as the panel's system user.">
        <x-slot:actions>
            <span class="pill font-mono">{{ $root }}</span>
        </x-slot:actions>
    </x-page-header>

    <livewire:websites.files key="global-files" />
</div>
