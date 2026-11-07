{{-- A sidebar section that folds away. It remembers being folded per browser, and always opens when it holds the current page. --}}
@props([
    'key',
    'label',
    'active' => false,
])

<div class="nav-block" x-data="navSection(@js($key), @js((bool) $active))">
    <div class="nav-section-row">
        <button class="nav-section-toggle" type="button" @click="toggle()" :aria-expanded="open.toString()" aria-expanded="true">
            <span>{{ $label }}</span>
            <x-menu-icon name="chevron-down" class="nav-caret" />
        </button>
        {{ $action ?? '' }}
    </div>
    <div class="nav-group" x-show="open" x-collapse>
        {{ $slot }}
    </div>
</div>
