<div class="flex flex-col gap-6">
    @include('livewire.partials.followed-tasks')

    <div class="php-tabs" role="tablist" aria-label="PHP sections">
        @foreach (['version' => 'PHP version', 'extensions' => 'PHP extensions', 'options' => 'PHP options'] as $key => $label)
            <button type="button" role="tab" aria-selected="{{ $section === $key ? 'true' : 'false' }}" wire:click="$set('section', '{{ $key }}')">{{ $label }}</button>
        @endforeach
    </div>

    @if ($section === 'version')
        <x-layer-card title="PHP version" helper="Changing the version restarts the website's PHP processes; it takes a few seconds. Check that your code and packages support the version you pick.">
            <x-slot:actions>
                <button class="btn btn-primary" type="button" wire:click="saveVersion" wire:loading.attr="disabled" @disabled($php_version === $website->php_version)>Update</button>
            </x-slot:actions>
            <div class="php-versions" role="radiogroup">
                @foreach ($phpVersions as $version)
                    @php
                        $installed = in_array($version, $installedVersions, true) || $installedVersions === [];
                    @endphp
                    <label class="php-version {{ $php_version === $version ? 'is-picked' : '' }}">
                        <input type="radio" wire:model.live="php_version" value="{{ $version }}">
                        <span class="php-version-text">
                            <span class="php-version-name">PHP {{ $version }}</span>
                            @if ($website->php_version === $version)
                                <small>Current version</small>
                            @elseif (! $installed)
                                <small>Not installed: installed when you pick it</small>
                            @else
                                <small>Installed</small>
                            @endif
                        </span>
                        @if ($website->php_version === $version)
                            <span class="repo-row-badge">current</span>
                        @endif
                    </label>
                @endforeach
            </div>
            @error('php_version') <small class="error">{{ $message }}</small> @enderror
        </x-layer-card>
    @elseif ($section === 'extensions')
        @if ($builtIn !== [])
            <x-layer-card title="Built-in extensions" helper="Compiled into PHP {{ $website->php_version }}: always on.">
                <div class="php-checks">
                    @foreach ($builtIn as $module)
                        <label class="php-check is-fixed" data-tip="Built into PHP {{ $website->php_version }}: always on."><input type="checkbox" checked disabled> <span>{{ $module }}</span></label>
                    @endforeach
                </div>
            </x-layer-card>
        @endif

        <x-layer-card title="Extensions" helper="For PHP {{ $website->php_version }} on this server: a change applies to every website on PHP {{ $website->php_version }}. Ticking one that is not installed installs it when you save.">
            <x-slot:actions>
                <input class="input h-8 w-44" type="search" wire:model.live.debounce.200ms="extensionFilter" placeholder="Filter extensions" aria-label="Filter extensions">
            </x-slot:actions>
            <div class="php-checks">
                @forelse ($extensions as $extension)
                    <label class="php-check" data-tip="{{ $extension['help'] }}{{ $extension['installed'] ? '' : ' Not installed yet: installed when you save.' }}" wire:key="ext-{{ $extension['module'] }}">
                        <input type="checkbox" wire:model.live="extensionState.{{ $extension['module'] }}">
                        <span>{{ $extension['label'] }}</span>
                        @if (($extensionState[$extension['module']] ?? false) !== $extension['enabled'])
                            <span class="php-changed" title="Not saved yet">•</span>
                        @endif
                    </label>
                @empty
                    <p class="text-[13px] text-muted">No extension matches.</p>
                @endforelse
            </div>
            <div class="php-foot">
                <span class="text-[12.5px] text-muted">{{ $pendingChanges > 0 ? $pendingChanges.' '.\Illuminate\Support\Str::plural('change', $pendingChanges).' not saved yet. Hover an extension to see what it does.' : 'Hover an extension to see what it does.' }}</span>
                <button class="btn" type="button" wire:click="resetExtensions" @disabled($pendingChanges === 0)>Reset</button>
                <button class="btn btn-primary" type="button" wire:click="saveExtensions" wire:loading.attr="disabled" @disabled($pendingChanges === 0)>Save</button>
            </div>
        </x-layer-card>
    @else
        <x-layer-card title="Options" helper="This website's own PHP settings, in its own PHP-FPM pool: other websites keep theirs.">
            <div class="php-checks php-checks-2">
                @foreach ($flagList as $key => $flag)
                    <label class="php-check" data-tip="{{ $flag['help'] }} PHP's default: {{ $flag['default'] }}." wire:key="flag-{{ $key }}">
                        <input type="checkbox" wire:model="flags.{{ \App\Support\PhpOptions::field($key) }}">
                        <span>{{ $key }}</span>
                        <small class="text-muted">{{ $flag['label'] }}</small>
                    </label>
                @endforeach
            </div>

            <div class="mt-6 grid gap-5">
                @foreach ($optionList as $key => $option)
                    @php
                        $field = \App\Support\PhpOptions::field($key);
                    @endphp
                    <label class="field" wire:key="opt-{{ $field }}">
                        <span class="font-mono text-[13px] font-semibold">{{ $key }}</span>
                        @if (isset($choices[$key]))
                            <select class="input" wire:model="options.{{ $field }}">
                                <option value="">Default ({{ $choices[$key][$option['default']] ?? $option['default'] }})</option>
                                @foreach ($choices[$key] as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        @elseif (in_array($key, ['disable_functions', 'include_path'], true))
                            <textarea class="textarea font-mono text-[12.5px]" rows="2" wire:model="options.{{ $field }}" placeholder="{{ $option['default'] !== '' ? 'Default: '.$option['default'] : 'e.g. '.$option['example'] }}" spellcheck="false"></textarea>
                        @else
                            <input class="input font-mono" wire:model="options.{{ $field }}" placeholder="Default: {{ $option['default'] }} · e.g. {{ $option['example'] }}" spellcheck="false">
                        @endif
                        <small class="text-muted">{{ $option['help'] }} Empty keeps PHP's default.</small>
                        @error('options.'.$field) <small class="error">{{ $message }}</small> @enderror
                    </label>
                @endforeach
            </div>

            <div class="php-foot">
                <span></span>
                <button class="btn" type="button" wire:click="resetOptions">Reset</button>
                <button class="btn btn-primary" type="button" wire:click="saveOptions" wire:loading.attr="disabled">Save</button>
            </div>
        </x-layer-card>
    @endif
</div>
