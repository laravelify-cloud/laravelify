{{-- Pick what to deploy: Backend, Frontend, or Full-stack. Backend is split into PHP and TypeScript / JavaScript. --}}
@php
    use App\Support\SiteTypes;

    $stacks = collect(SiteTypes::stacks());
@endphp

<div class="flex flex-col gap-6">
    @foreach ($groups as $groupKey => $group)
        <section class="flex flex-col gap-3" aria-labelledby="type-group-{{ $groupKey }}">
            <div>
                <h3 class="text-[13px] font-semibold tracking-wide uppercase" id="type-group-{{ $groupKey }}">{{ $group['label'] }}</h3>
                <p class="text-[12.5px] text-muted">{{ $group['blurb'] }}</p>
            </div>

            @foreach (SiteTypes::inGroup($groupKey) as $subgroup => $types)
                <div class="flex flex-col gap-2">
                    @if ($subgroup !== '')
                        <p class="text-[12px] font-medium text-muted">{{ $subgroup }}</p>
                    @endif
                    <div class="type-grid">
                        @foreach ($types as $key => $item)
                            @include('livewire.websites.partials.type-card', ['key' => $key, 'item' => $item, 'stack' => '', 'selected' => $type === $key && $stack === ''])
                        @endforeach
                    </div>
                </div>
            @endforeach

            @if ($groupKey === 'fullstack')
                <div class="type-grid">
                    @foreach ($stacks as $stackKey => $item)
                        @include('livewire.websites.partials.type-card', ['key' => $item['type'], 'item' => $item, 'stack' => $stackKey, 'selected' => $type === $item['type'] && $stack === $stackKey])
                    @endforeach
                </div>
            @endif
        </section>
    @endforeach

    <div class="callout" role="note">
        <x-menu-icon name="link" class="size-4 shrink-0 text-muted" />
        <p class="min-w-0 flex-1 text-[12.5px] text-muted">
            <span class="font-medium text-ink">Backend and frontend as separate sites?</span>
            Create each as its own website in the same group: for example <b>Next.js</b> + <b>Express</b> or <b>NestJS</b> (the full-JavaScript stack), or <b>Vue.js</b> / <b>React</b> + <b>Laravel</b>. Point the frontend at the API's domain.
        </p>
    </div>
</div>
