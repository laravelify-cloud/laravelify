<div class="flex flex-col gap-6" x-data="{ open: @entangle('showForm') }">
    <x-page-header title="Backup storage" summary="Backups are sent to an S3 bucket you own: Amazon S3, DigitalOcean Spaces, Cloudflare R2, Backblaze B2, Wasabi, or MinIO. The keys are stored encrypted.">
        <x-slot:actions>
            <button class="btn btn-primary" type="button" wire:click="create"><x-menu-icon name="plus" /> Add storage</button>
        </x-slot:actions>
    </x-page-header>

    <section class="layer-card-body is-flush">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Bucket</th>
                    <th>Status</th>
                    <th class="w-28">Schedules</th>
                    <th class="w-64"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($storages as $storage)
                    <tr wire:key="storage-{{ $storage->id }}">
                        <td>
                            <span class="flex items-center gap-2.5 font-medium">
                                <span class="stat-icon size-7"><x-menu-icon name="s3" class="size-4" /></span>
                                {{ $storage->name }}
                                @if ($storage->is_default)
                                    <span class="pill">Default</span>
                                @endif
                            </span>
                        </td>
                        <td class="text-muted">
                            {{ $storage->bucket }}{{ $storage->prefix() !== '' ? '/'.$storage->prefix() : '' }}
                            <span class="block text-[12px]">{{ $storage->endpoint ?: 'Amazon S3' }} · {{ $storage->region }}</span>
                        </td>
                        <td>
                            @if ($storage->last_error)
                                <span class="status-badge" style="--status-color: var(--color-danger)" title="{{ $storage->last_error }}">Failed</span>
                                <span class="block max-w-xs text-[12px] text-muted">{{ \Illuminate\Support\Str::limit($storage->last_error, 110) }}</span>
                            @elseif ($storage->checked_at)
                                <span class="status-badge" style="--status-color: var(--color-success)">Connected</span>
                            @else
                                <span class="status-badge" style="--status-color: var(--color-muted)">Not tested</span>
                            @endif
                        </td>
                        <td class="text-muted">{{ $storage->schedules_count }}</td>
                        <td class="text-right">
                            <div class="flex flex-wrap justify-end gap-1.5">
                                <button class="btn" type="button" wire:click="test({{ $storage->id }})" wire:loading.attr="disabled" wire:target="test({{ $storage->id }})">Test</button>
                                @unless ($storage->is_default)
                                    <button class="btn" type="button" wire:click="makeDefault({{ $storage->id }})">Make default</button>
                                @endunless
                                <button class="btn" type="button" wire:click="edit({{ $storage->id }})">Edit</button>
                                <button class="btn btn-danger" type="button" wire:click="delete({{ $storage->id }})" wire:confirm="Remove {{ $storage->name }}? Backups already in the bucket stay there.">Remove</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="table-empty">No storage yet. Add an S3 bucket, then create a backup schedule.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <x-modal-frame close="$wire.set('showForm', false)">
        <form class="grid gap-4" wire:submit="save">
            <div class="flex items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">{{ $editingId ? 'Edit storage' : 'Add S3 storage' }}</h2>
                <button class="btn" type="button" wire:click="$set('showForm', false)">Close</button>
            </div>

            <label class="field">
                <span>Name</span>
                <input class="input" wire:model="name" placeholder="Spaces, nyc3" required>
                @error('name') <small class="error">{{ $message }}</small> @enderror
            </label>

            <label class="field">
                <span>Provider</span>
                <select class="select" wire:model.live="provider">
                    @foreach ($providers as $key => $preset)
                        <option value="{{ $key }}">{{ $preset['label'] }}</option>
                    @endforeach
                </select>
                <small class="text-muted">{{ $providers[$provider]['hint'] }}</small>
            </label>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="field">
                    <span>Endpoint</span>
                    <input class="input font-mono" wire:model="endpoint" placeholder="empty for Amazon S3">
                    @error('endpoint') <small class="error">{{ $message }}</small> @enderror
                </label>
                <label class="field">
                    <span>Region</span>
                    <input class="input font-mono" wire:model.live.debounce.400ms="region" required>
                    @error('region') <small class="error">{{ $message }}</small> @enderror
                </label>
                <label class="field">
                    <span>Bucket</span>
                    <input class="input font-mono" wire:model="bucket" required>
                    @error('bucket') <small class="error">{{ $message }}</small> @enderror
                </label>
                <label class="field">
                    <span>Folder in the bucket</span>
                    <input class="input font-mono" wire:model="path" placeholder="laravelify">
                    @error('path') <small class="error">{{ $message }}</small> @enderror
                </label>
                <label class="field">
                    <span>Access key</span>
                    <input class="input font-mono" wire:model="access_key" autocomplete="off" spellcheck="false" placeholder="{{ $editingId ? 'Saved. Type to replace it.' : '' }}">
                    @error('access_key') <small class="error">{{ $message }}</small> @enderror
                </label>
                <label class="field">
                    <span>Secret key</span>
                    <input class="input font-mono" type="password" wire:model="secret_key" autocomplete="new-password" spellcheck="false" placeholder="{{ $editingId ? 'Saved. Type to replace it.' : '' }}">
                    @error('secret_key') <small class="error">{{ $message }}</small> @enderror
                </label>
            </div>

            <p class="text-[12.5px] text-muted">The keys need read, write, and delete access to this bucket. Saving writes a small test file and removes it.</p>

            <div class="flex justify-end gap-2 border-t border-line pt-4">
                <button class="btn" type="button" wire:click="$set('showForm', false)">Cancel</button>
                <button class="btn btn-primary" type="submit" wire:loading.attr="disabled" wire:target="save">Save and test</button>
            </div>
        </form>
    </x-modal-frame>
</div>
