{{-- Pick what to deploy. Tabs for Backend / Frontend / Full-stack, a search box, and pins kept in this browser. --}}
@php
    use App\Support\SiteTypes;

    $cards = [];

    foreach (SiteTypes::all() as $key => $item) {
        $cards[] = ['key' => $key, 'stack' => '', 'item' => $item, 'group' => $item['group']];
    }

    foreach (SiteTypes::stacks() as $stackKey => $item) {
        $cards[] = ['key' => $item['type'], 'stack' => $stackKey, 'item' => $item, 'group' => $item['group']];
    }

    $selectedGroup = SiteTypes::stacks()[$stack]['group'] ?? SiteTypes::all()[$type]['group'] ?? array_key_first($groups);
@endphp

<div
    class="flex flex-col gap-3"
    x-on:type-detected.window="tab = $event.detail.group; query = ''"
    x-data="{
        tab: @js($selectedGroup),
        query: '',
        pins: [],
        init() {
            try { this.pins = JSON.parse(localStorage.getItem('laravelify.pinned-types') || '[]'); } catch (e) { this.pins = []; }
        },
        isPinned(id) { return this.pins.includes(id); },
        togglePin(id) {
            this.pins = this.isPinned(id) ? this.pins.filter((pin) => pin !== id) : [...this.pins, id];
            try { localStorage.setItem('laravelify.pinned-types', JSON.stringify(this.pins)); } catch (e) {}
        },
        shows(group, text) {
            const term = this.query.trim().toLowerCase();
            return term === '' ? group === this.tab : text.includes(term);
        },
    }"
>
    <div class="flex flex-wrap items-center gap-2">
        <div class="segmented" role="group" aria-label="Type of site">
            @foreach ($groups as $groupKey => $group)
                <button type="button" :aria-pressed="query.trim() === '' && tab === '{{ $groupKey }}'" x-on:click="tab = '{{ $groupKey }}'; query = ''">{{ $group['label'] }}</button>
            @endforeach
        </div>
        <input class="input min-w-40 flex-1" type="search" x-model="query" placeholder="Search frameworks" aria-label="Search frameworks">
    </div>

    <div class="type-grid">
        @foreach ($cards as $card)
            @include('livewire.websites.partials.type-card', [
                'key' => $card['key'],
                'item' => $card['item'],
                'stack' => $card['stack'],
                'group' => $card['group'],
                'pinId' => $card['stack'] !== '' ? $card['key'].':'.$card['stack'] : $card['key'],
                'selected' => $type === $card['key'] && $stack === $card['stack'],
            ])
        @endforeach
    </div>

    <p class="text-[12.5px] text-muted">Backend and frontend as separate sites? Create each as its own website and point the frontend at the API's domain.</p>
</div>
