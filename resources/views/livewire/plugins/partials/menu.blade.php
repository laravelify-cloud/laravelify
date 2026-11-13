{{-- The "More" menu of a plugin card. $items: label, icon, and click (a Livewire call), gate (a Livewire call after the
     authenticator-code check), or event (a window event); danger and confirm optional. --}}
<div
    class="row-menu"
    x-data="{
        open: false,
        x: 0,
        y: 0,
        place(event) {
            const rect = event.currentTarget.getBoundingClientRect();
            const height = {{ count($items) * 34 + 10 }};
            this.x = Math.max(8, Math.min(rect.right - 200, window.innerWidth - 208));
            this.y = rect.bottom + 4 + height > window.innerHeight ? Math.max(8, rect.top - 4 - height) : rect.bottom + 4;
            this.open = ! this.open;
        },
    }"
>
    <button class="btn btn-sm" type="button" title="More actions" aria-label="More actions" aria-haspopup="menu" :aria-expanded="open" @click.stop="place($event)" @disabled($disabled ?? false)>
        <x-menu-icon name="more" /> More
    </button>

    <template x-teleport="body">
        <div
            class="dropdown"
            role="menu"
            x-cloak
            x-show="open"
            x-transition:enter="dropdown-in"
            x-transition:enter-start="dropdown-from"
            x-transition:enter-end="dropdown-to"
            :style="`position: fixed; left: ${x}px; top: ${y}px; min-width: 12.5rem`"
            @click.outside="open = false"
            @keydown.escape.window="open = false"
            @scroll.window="open = false"
        >
            @foreach ($items as $item)
                <button
                    @class(['dropdown-option', 'is-danger' => $item['danger'] ?? false])
                    role="menuitem"
                    type="button"
                    @isset($item['gate'])
                        @click="open = false; if (await Alpine.store('secretGate').request($wire)) $wire.{{ $item['gate'] }}"
                    @elseif (isset($item['event']))
                        @click="open = false; $dispatch(@js($item['event']))"
                    @else
                        wire:click="{{ $item['click'] }}"
                        @if (! empty($item['confirm'])) wire:confirm="{{ $item['confirm'] }}" @endif
                        @click="open = false"
                    @endisset
                ><x-menu-icon name="{{ $item['icon'] }}" /> {{ $item['label'] }}</button>
            @endforeach
        </div>
    </template>
</div>
