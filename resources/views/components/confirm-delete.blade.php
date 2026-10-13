@props([
    'title',
    'name',
    'action',
    'button' => 'Delete',
    'actions' => [],
])

<div x-data="{ open: false, typed: '' }" class="inline-flex">
    <button class="btn btn-danger" type="button" @click="open = true; typed = ''; $wire.confirmName = ''">{{ $button }}</button>

    <template x-teleport="body">
        <x-modal-frame class="modal-root-top" open="open" close="open = false">
            <div class="mb-4 flex items-start justify-between gap-3">
                <h2 class="text-lg font-semibold">{{ $title }}</h2>
                <button class="btn" type="button" @click="open = false">Close</button>
            </div>

            <div class="modal-warning">
                <p><strong>Warning</strong></p>
                <p>This operation is permanent and cannot be undone. Please think again before proceeding.</p>
            </div>

            <p>The following actions will be performed:</p>
            <ul class="modal-actions mb-4 mt-2 list-disc space-y-1 pl-5">
                @foreach ($actions as $line)
                    <li>{{ $line }}</li>
                @endforeach
            </ul>

            <p class="font-medium">Confirm actions</p>
            <p class="mb-2 text-muted">Please confirm by entering the name below.</p>
            <div class="mb-3 flex items-center gap-2">
                <input class="input" value="{{ $name }}" readonly>
                <button class="btn" type="button" @click="navigator.clipboard.writeText(@js($name))">Copy</button>
            </div>
            <label class="field">
                <span>Name</span>
                <input class="input" wire:model="confirmName" x-on:input="typed = $event.target.value" autocomplete="off">
                @error('confirmName') <small class="error">{{ $message }}</small> @enderror
            </label>

            <div class="mt-4 flex justify-end gap-2 border-t border-line pt-4">
                <button class="btn" type="button" @click="open = false">Cancel</button>
                <button class="btn btn-danger" type="button" wire:click="{{ $action }}" x-bind:disabled="typed !== @js($name)">{{ $button }}</button>
            </div>
        </x-modal-frame>
    </template>
</div>
