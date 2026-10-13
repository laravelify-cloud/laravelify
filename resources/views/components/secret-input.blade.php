@props([
    'label',
    'model',
    // Key of a saved secret the eye can fetch from the server (after the authenticator code).
    'secret' => null,
    // A value is saved but not loaded into the page.
    'saved' => false,
    'placeholder' => null,
    'disabled' => false,
])

<label {{ $attributes->class(['field']) }} x-data="secretField(@js(['model' => $model, 'secret' => $secret]))">
    <span class="flex items-center gap-2">
        {{ $label }}
        @if ($saved)
            <span class="pill" style="height: 18px; font-size: 10.5px">Saved</span>
        @endif
    </span>
    <span class="relative block">
        <input
            class="input pr-10 font-mono"
            :type="shown ? 'text' : 'password'"
            wire:model="{{ $model }}"
            autocomplete="new-password"
            spellcheck="false"
            placeholder="{{ $placeholder ?? ($saved ? '•••••••• Saved. Click the eye to show it, or type to replace it.' : '') }}"
            @disabled($disabled)
        >
        <button
            class="icon-btn absolute right-[3px] top-[3px]"
            style="width: 26px; height: 26px"
            type="button"
            @click="toggle()"
            :title="shown ? 'Hide' : 'Show (asks for your authenticator code)'"
            :aria-label="shown ? 'Hide {{ $label }}' : 'Show {{ $label }}'"
            tabindex="-1"
        >
            <span class="loader-spinner" style="width: 13px; height: 13px; border-width: 2px" x-cloak x-show="loading"></span>
            <x-menu-icon name="eye" class="size-4" x-show="!shown && !loading" />
            <x-menu-icon name="eye-off" class="size-4" x-cloak x-show="shown && !loading" />
        </button>
    </span>
    @error($model) <small class="error">{{ $message }}</small> @enderror
</label>
