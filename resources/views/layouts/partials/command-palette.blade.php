{{-- Ctrl+K / ⌘K: jump to any page in the sidebar (read from it, so new links show up here too) or run a quick action. --}}
<div
    class="cmdk-root"
    x-data="commandPalette(@js([
        ['label' => 'New group', 'hint' => 'Create a group for a product or client', 'icon' => 'plus', 'href' => route('groups.create')],
        ['label' => 'Account security', 'hint' => 'Password and two-factor', 'icon' => 'shield', 'href' => route('account.security')],
    ]))"
    x-cloak
    x-show="open"
    @command-palette.window="show()"
    @keydown.window="shortcut($event)"
    role="dialog"
    aria-modal="true"
    aria-label="Search pages and actions"
>
    <div class="cmdk-backdrop" x-show="open" x-transition.opacity.duration.150ms @click="close()"></div>
    <div
        class="cmdk-panel"
        x-show="open"
        x-transition:enter="modal-pop-in"
        x-transition:enter-start="modal-pop-from"
        x-transition:enter-end="modal-pop-to"
        x-transition:leave="modal-pop-out"
        x-transition:leave-start="modal-pop-to"
        x-transition:leave-end="modal-pop-from"
        @keydown.arrow-down.prevent="move(1)"
        @keydown.arrow-up.prevent="move(-1)"
        @keydown.enter.prevent="run(results[active])"
        @keydown.escape.prevent.stop="close()"
    >
        <label class="cmdk-search">
            <x-menu-icon name="search" />
            <input x-ref="input" x-model="query" @input="active = 0" type="text" placeholder="Search pages, tools and actions…" autocomplete="off" spellcheck="false" role="combobox" aria-controls="cmdk-list" :aria-activedescendant="'cmdk-item-' + active">
            <kbd class="kbd">Esc</kbd>
        </label>
        <div class="cmdk-list" id="cmdk-list" role="listbox" x-ref="list">
            <template x-for="(item, index) in results" :key="item.id">
                <div>
                    <p class="cmdk-group" x-show="index === 0 || results[index - 1].group !== item.group" x-text="item.group"></p>
                    <button
                        class="cmdk-item"
                        type="button"
                        role="option"
                        :id="'cmdk-item-' + index"
                        :class="index === active && 'is-active'"
                        :aria-selected="(index === active).toString()"
                        @mousemove="active = index"
                        @click="run(item)"
                    >
                        <span class="cmdk-icon" x-html="item.icon"></span>
                        <span class="cmdk-text">
                            <span class="cmdk-label" x-text="item.label"></span>
                            <span class="cmdk-hint" x-show="item.hint" x-text="item.hint"></span>
                        </span>
                        <x-menu-icon name="external" class="cmdk-trail" x-show="item.external" />
                        <x-menu-icon name="chevron-right" class="cmdk-trail" x-show="! item.external" />
                    </button>
                </div>
            </template>
            <p class="cmdk-empty" x-show="results.length === 0">Nothing matches “<span x-text="query"></span>”.</p>
        </div>
        <div class="cmdk-foot">
            <span><kbd class="kbd">↑</kbd><kbd class="kbd">↓</kbd> move</span>
            <span><kbd class="kbd">↵</kbd> open</span>
            <span class="ml-auto"><kbd class="kbd">Ctrl K</kbd> anywhere</span>
        </div>
    </div>
    <template x-ref="icons">
        <span data-icon="plus"><x-menu-icon name="plus" /></span>
        <span data-icon="shield"><x-menu-icon name="shield" /></span>
        <span data-icon="sun"><x-menu-icon name="sun" /></span>
        <span data-icon="logout"><x-menu-icon name="logout" /></span>
    </template>
</div>
