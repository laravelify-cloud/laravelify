{{-- The file manager's trash: every deleted path with its versions, restore, and delete for good (with the authenticator code). --}}
@php
    $time = app(\App\Services\DisplayTime::class);
    $totalBytes = $trashGroups->flatMap(fn ($group) => $group['versions'])->sum('size_bytes');
@endphp

<div
    x-data="{
        otp: { open: false, action: 'empty', id: null, name: '', code: '', busy: false },
        expanded: [],
        ask(action, id = null, name = '') {
            this.otp = { open: true, action: action, id: id, name: name, code: '', busy: false };
            $wire.resetErrorBag('code');
            this.$nextTick(() => this.$refs.code && this.$refs.code.focus());
        },
        submit() {
            if (this.otp.code.length < 6 || this.otp.busy) {
                return;
            }

            this.otp.busy = true;
            const call = this.otp.action === 'empty' ? $wire.emptyTrash(this.otp.code) : $wire.purgeTrash(this.otp.id, this.otp.code);

            call.then((done) => {
                this.otp.busy = false;

                if (done) {
                    this.otp.open = false;
                } else {
                    this.otp.code = '';
                    this.$nextTick(() => this.$refs.code && this.$refs.code.focus());
                }
            });
        },
        toggle(path) {
            this.expanded = this.expanded.includes(path) ? this.expanded.filter((item) => item !== path) : this.expanded.concat([path]);
        },
    }"
>
    <div class="fm-toolbar">
        <div class="fm-nav">
            <button class="btn btn-sm" type="button" wire:click="closeTrash"><x-menu-icon name="up" class="-rotate-90" /> Back to files</button>
            <span class="flex items-center gap-2 font-medium"><x-menu-icon name="trash" class="size-4 text-muted" /> Trash</span>
            <span class="text-[12.5px] text-muted">{{ $trashGroups->count() }} {{ \Illuminate\Support\Str::plural('item', $trashGroups->count()) }} · {{ \Illuminate\Support\Number::fileSize((int) $totalBytes, precision: 1) }}</span>
        </div>
        <div class="fm-tools">
            <button class="btn btn-sm btn-danger" type="button" @click="ask('empty')" @disabled($trashGroups->isEmpty())><x-menu-icon name="trash" /> Empty trash</button>
        </div>
    </div>

    <x-callout type="info" title="Deleted files wait here" class="mx-4 mb-3">
        Restore puts a file or folder back where it was. If the same path was deleted more than once, open it to choose which version to bring back. Deleting for good asks for your authenticator code.
    </x-callout>

    @if ($trashGroups->isEmpty())
        <section class="fm-state">
            <span class="fm-state-badge"><x-menu-icon name="trash" /></span>
            <h3>The trash is empty</h3>
            <p>Files and folders you delete in the file manager appear here.</p>
        </section>
    @else
        <div class="fm-table" role="table">
            <div class="fm-row fm-row-head trash-row" role="row">
                <span role="columnheader">Name</span>
                <span role="columnheader" class="fm-col-size">Size</span>
                <span role="columnheader" class="fm-col-date">Deleted</span>
                <span role="columnheader" class="sr-only">Actions</span>
            </div>

            @foreach ($trashGroups as $group)
                @php
                    $latest = $group['versions']->first();
                    $count = $group['versions']->count();
                @endphp
                <div class="fm-row trash-row" role="row" wire:key="trash-{{ md5($group['path']) }}">
                    <span class="fm-name-cell" role="cell">
                        @include('livewire.websites.partials.file-icon', ['kind' => $group['dir'] ? 'folder' : 'file'])
                        <span class="min-w-0">
                            <span class="fm-name block">{{ $group['name'] }}</span>
                            <span class="block truncate text-[12px] text-muted">from /{{ $latest->parentPath() }}</span>
                        </span>
                        @if ($count > 1)
                            <button class="pill shrink-0" type="button" @click="toggle(@js($group['path']))" :aria-expanded="expanded.includes(@js($group['path']))">
                                {{ $count }} versions <x-menu-icon name="chevron-down" class="size-3.5" />
                            </button>
                        @endif
                    </span>
                    <span role="cell" class="fm-col-size">{{ $latest->sizeLabel() }}</span>
                    <span role="cell" class="fm-col-date">{{ $time->datetime($latest->created_at) }}</span>
                    <span role="cell" class="row-actions">
                        <button class="btn btn-sm" type="button" wire:click="restoreTrash({{ $latest->id }})" title="{{ $count > 1 ? 'Restore the newest version' : 'Restore' }}"><x-menu-icon name="restore" /> Restore</button>
                        <button class="icon-btn icon-btn-danger" type="button" @click="ask('purge', {{ $latest->id }}, @js($group['name']))" title="Delete for good" aria-label="Delete {{ $group['name'] }} for good"><x-menu-icon name="trash" /></button>
                    </span>
                </div>

                @if ($count > 1)
                    <div class="trash-versions" x-cloak x-show="expanded.includes(@js($group['path']))">
                        @foreach ($group['versions'] as $index => $version)
                            <div class="trash-version" wire:key="trash-version-{{ $version->id }}">
                                <span class="pill">{{ $index === 0 ? 'Newest' : 'Version '.($count - $index) }}</span>
                                <span class="min-w-0 flex-1 text-[13px]">Deleted {{ $time->datetime($version->created_at) }}{{ $version->user ? ' by '.$version->user->name : '' }}</span>
                                <span class="text-[12px] tabular-nums text-muted">{{ $version->sizeLabel() }}</span>
                                <button class="btn btn-sm" type="button" wire:click="restoreTrash({{ $version->id }})"><x-menu-icon name="restore" /> Restore this</button>
                                <button class="icon-btn icon-btn-danger" type="button" @click="ask('purge', {{ $version->id }}, @js($group['name']))" title="Delete this version for good" aria-label="Delete this version for good"><x-menu-icon name="trash" /></button>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endforeach
        </div>
    @endif

    {{-- Authenticator code before deleting for good --}}
    <template x-teleport="body">
        <x-modal-frame class="modal-root-top" open="otp.open" close="otp.open = false">
            <form class="flex flex-col gap-4" @submit.prevent="submit()">
                <div class="modal-head">
                    <h2 class="flex items-center gap-2">
                        <span class="grid size-8 place-items-center rounded-full" style="background: color-mix(in srgb, var(--color-danger) 14%, transparent); color: var(--color-danger)"><x-menu-icon name="lock" class="size-4" /></span>
                        <span x-text="otp.action === 'empty' ? 'Empty the trash?' : 'Delete ' + otp.name + ' for good?'"></span>
                    </h2>
                    <button class="icon-btn" type="button" @click="otp.open = false" aria-label="Close"><x-menu-icon name="close" /></button>
                </div>

                <x-callout type="danger" title="This cannot be undone">
                    <span x-text="otp.action === 'empty' ? 'Everything in the trash is deleted from the disk.' : 'This version is deleted from the disk.'"></span>
                    To confirm it is you, enter the code from your authenticator app.
                </x-callout>

                <label class="field">
                    <span>Authenticator code</span>
                    <input class="input otp-input" x-ref="code" x-model="otp.code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="000000" @input="otp.code = otp.code.replace(/\D/g, '').slice(0, 6)">
                    @error('code') <small class="error">{{ $message }}</small> @enderror
                </label>

                <div class="modal-foot">
                    <button class="btn" type="button" @click="otp.open = false">Cancel</button>
                    <button class="btn btn-danger-solid" type="submit" :disabled="otp.code.length < 6 || otp.busy">
                        <span class="task-spinner" x-show="otp.busy" x-cloak></span>
                        <x-menu-icon name="trash" class="size-4" x-show="! otp.busy" />
                        <span x-text="otp.action === 'empty' ? 'Empty trash' : 'Delete for good'"></span>
                    </button>
                </div>
            </form>
        </x-modal-frame>
    </template>
</div>
