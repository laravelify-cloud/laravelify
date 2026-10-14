{{--
    Confirm deleting the selected rows by typing "delete". The parent x-data has: selected (list),
    bulkDelete (bool), typed (string). $action is the Livewire call, like "$wire.deleteMany(selected)".
--}}
@props([
    'action',
    'what' => 'items',
    'actions' => [],
])

<template x-teleport="body">
    <x-modal-frame class="modal-root-top" open="bulkDelete" close="bulkDelete = false">
        <form class="flex flex-col gap-4" @submit.prevent="if (typed === 'delete') { $wire.confirmName = 'delete'; {{ $action }}; bulkDelete = false; typed = ''; }">
            <div class="modal-head">
                <h2 class="flex min-w-0 items-center gap-2">
                    <span class="grid size-8 shrink-0 place-items-center rounded-full" style="background: color-mix(in srgb, var(--color-danger) 14%, transparent); color: var(--color-danger)"><x-menu-icon name="trash" class="size-4" /></span>
                    <span class="truncate" x-text="'Delete ' + selected.length + ' {{ $what }}?'"></span>
                </h2>
                <button class="icon-btn" type="button" @click="bulkDelete = false" aria-label="Close"><x-menu-icon name="close" /></button>
            </div>

            <x-callout type="danger" title="This cannot be undone">
                This is permanent. Please think again before you continue.
            </x-callout>

            @if ($actions !== [])
                <div>
                    <p class="mb-2 text-[12.5px] font-medium">What will happen</p>
                    <ul class="delete-list">
                        @foreach ($actions as $line)
                            <li><span>{{ $line }}</span></li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="field">
                <span>Confirm</span>
                <small class="text-muted">Type the word below to confirm. Use the copy button to paste it.</small>
                <div class="copy-field">
                    <input class="input font-mono" value="delete" readonly tabindex="-1" aria-label="Text to type">
                    <x-copy-button text="'delete'" />
                </div>
                <input class="input" x-model="typed" autocomplete="off" spellcheck="false" placeholder="Type it here" aria-label="Confirm by typing delete">
            </div>

            <div class="modal-foot">
                <button class="btn" type="button" @click="bulkDelete = false">Cancel</button>
                <button class="btn btn-danger-solid" type="submit" :disabled="typed !== 'delete'">
                    <x-menu-icon name="trash" class="size-4" /> <span x-text="'Delete ' + selected.length"></span>
                </button>
            </div>
        </form>
    </x-modal-frame>
</template>
