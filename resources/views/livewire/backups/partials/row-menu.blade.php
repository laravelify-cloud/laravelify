{{-- A "more" button with a small menu. $items: label, icon, and either href or click (a Livewire call); danger, confirm optional. --}}
<div
    class="row-menu"
    x-data="{
        open: false,
        x: 0,
        y: 0,
        place(event) {
            const rect = event.currentTarget.getBoundingClientRect();
            this.x = Math.max(8, Math.min(rect.right - 184, window.innerWidth - 192));
            this.y = rect.bottom + 4;

            if (this.y + 200 > window.innerHeight) {
                this.y = Math.max(8, rect.top - 4 - Math.min(200, {{ count($items) * 34 + 10 }}));
            }

            this.open = ! this.open;
        },
    }"
>
    <button class="icon-btn" type="button" title="More" aria-label="More actions" :aria-expanded="open" @click.stop="place($event)"><x-menu-icon name="more" /></button>

    <template x-teleport="body">
        <div
            class="dropdown"
            role="menu"
            x-cloak
            x-show="open"
            x-transition:enter="dropdown-in"
            x-transition:enter-start="dropdown-from"
            x-transition:enter-end="dropdown-to"
            :style="`position: fixed; left: ${x}px; top: ${y}px; min-width: 11.5rem`"
            @click.outside="open = false"
            @keydown.escape.window="open = false"
            @scroll.window="open = false"
        >
            @foreach ($items as $item)
                @if (isset($item['href']))
                    <a class="dropdown-option" role="menuitem" href="{{ $item['href'] }}" wire:navigate @click="open = false"><x-menu-icon name="{{ $item['icon'] }}" /> {{ $item['label'] }}</a>
                @else
                    <button
                        @class(['dropdown-option', 'is-danger' => $item['danger'] ?? false])
                        role="menuitem"
                        type="button"
                        wire:click="{{ $item['click'] }}"
                        @isset($item['confirm']) wire:confirm="{{ $item['confirm'] }}" @endisset
                        @click="open = false"
                    ><x-menu-icon name="{{ $item['icon'] }}" /> {{ $item['label'] }}</button>
                @endif
            @endforeach
        </div>
    </template>
</div>
