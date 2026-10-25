@props([
    'title',
    'name',
    'action',
    'button' => 'Delete',
    'actions' => [],
    'small' => false,
])

<div x-data="{ open: false, typed: '' }" class="inline-flex">
    <button @class(['btn btn-danger', 'btn-sm' => $small]) type="button" @click="open = true; typed = ''; $wire.confirmName = ''">{{ $button }}</button>

    <template x-teleport="body">
        <x-modal-frame class="modal-root-top" open="open" close="open = false">
            <div class="mb-4 flex items-start justify-between gap-3">
                <h2 class="text-lg font-semibold">{{ $title }}</h2>
                <button class="btn" type="button" @click="open = false">Close</button>
            </div>

            <x-callout type="danger" title="This cannot be undone" class="mb-4">
                This is permanent. Please think again before you continue.
            </x-callout>

            <p class="mb-2 text-[12.5px] font-medium">What will happen</p>
            <ul class="delete-list mb-4">
                @foreach ($actions as $line)
                    <li><span>{{ $line }}</span></li>
                @endforeach
            </ul>

            <p class="font-medium">Confirm</p>
            <p class="mb-2 text-muted">Type the name below to confirm. Use the copy button to paste it.</p>
            <div class="copy-field mb-3">
                <input class="input font-mono" value="{{ $name }}" readonly tabindex="-1">
                <x-copy-button :text="\Illuminate\Support\Js::from($name)->toHtml()" />
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
