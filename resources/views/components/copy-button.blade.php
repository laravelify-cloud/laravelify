{{-- Copies text to the clipboard and shows a check for a moment. $text is a JavaScript expression. --}}
@props(['text'])

<button
    type="button"
    {{ $attributes->class(['icon-btn copy-button']) }}
    x-data="{ copied: false }"
    @click.prevent="
        if (! navigator.clipboard) { window.toast('Copying needs a secure (https) connection.', 'error'); return; }
        navigator.clipboard.writeText(String({{ $text }})).then(() => { copied = true; setTimeout(() => copied = false, 1500); });
    "
    :title="copied ? 'Copied' : 'Copy'"
    :aria-label="copied ? 'Copied' : 'Copy'"
>
    <x-menu-icon name="copy" class="size-4" x-show="! copied" />
    <x-menu-icon name="check" class="size-4" style="color: var(--color-success)" x-show="copied" x-cloak />
</button>
