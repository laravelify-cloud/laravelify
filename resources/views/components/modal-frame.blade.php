@props([
    'open' => 'open',
    'close' => 'open = false',
])

<div
    {{ $attributes->merge(['class' => 'modal-root']) }}
    x-cloak
    x-show="{{ $open }}"
    x-transition:enter="modal-shell-in"
    x-transition:enter-start="modal-shell-from"
    x-transition:enter-end="modal-shell-to"
    x-transition:leave="modal-shell-out"
    x-transition:leave-start="modal-shell-to"
    x-transition:leave-end="modal-shell-from"
    @keydown.escape.window="if ({{ $open }}) { {{ $close }} }"
>
    <div
        class="modal-backdrop"
        x-show="{{ $open }}"
        x-transition:enter="modal-fade-in"
        x-transition:enter-start="modal-fade-from"
        x-transition:enter-end="modal-fade-to"
        x-transition:leave="modal-fade-out"
        x-transition:leave-start="modal-fade-to"
        x-transition:leave-end="modal-fade-from"
        @click="{{ $close }}"
    ></div>
    <div
        class="card modal-panel"
        role="dialog"
        aria-modal="true"
        x-show="{{ $open }}"
        x-transition:enter="modal-pop-in"
        x-transition:enter-start="modal-pop-from"
        x-transition:enter-end="modal-pop-to"
        x-transition:leave="modal-pop-out"
        x-transition:leave-start="modal-pop-to"
        x-transition:leave-end="modal-pop-from"
        @click.stop
    >
        {{ $slot }}
    </div>
</div>
