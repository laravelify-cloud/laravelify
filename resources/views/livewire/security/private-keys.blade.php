<div class="flex flex-col gap-4">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-2xl font-semibold">Private keys</h2>
            <p class="text-muted">SSH and GitHub App keys. A GitHub App chooses one of these instead of pasting the PEM on the source page.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <x-confirm-delete
                title="Confirm unused key deletion?"
                name="unused"
                action="deleteUnused"
                button="Delete unused keys"
                :actions="['Every private key that is not selected by a GitHub App will be permanently deleted.']"
            />
            <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                <button class="btn btn-primary" type="button" @click="open = !open">New private key</button>
                <div class="card absolute right-0 z-20 mt-1 min-w-48 p-1" x-cloak x-show="open">
                    <button class="block w-full rounded-md px-3 py-2 text-left hover:bg-white/10" type="button" wire:click="generate('ed25519')" @click="open = false">Generate ED25519</button>
                    <button class="block w-full rounded-md px-3 py-2 text-left hover:bg-white/10" type="button" wire:click="generate('rsa')" @click="open = false">Generate RSA</button>
                    <button class="block w-full rounded-md px-3 py-2 text-left hover:bg-white/10" type="button" wire:click="$set('showCreate', true)" @click="open = false">Add manually</button>
                </div>
            </div>
        </div>
    </div>

    @if ($notice !== '')
        <p class="{{ $noticeOk ? 'status' : 'error' }}">{{ $notice }}</p>
    @endif

    <section class="card overflow-hidden">
        <table class="table">
            <thead>
                <tr>
                    <th>Private key</th>
                    <th>Description</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($keys as $key)
                    <tr>
                        <td class="font-medium">{{ $key->name }}</td>
                        <td class="text-muted">{{ $key->description ?: '—' }}</td>
                        <td>
                            @if ($key->isInUse())
                                <span class="text-emerald-300">In use</span>
                            @else
                                <span class="text-amber-300">Unused</span>
                            @endif
                        </td>
                        <td class="text-right">
                            <button class="btn" type="button" wire:click="openEditor({{ $key->id }})">Edit</button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-muted">No private keys yet. Generate one or paste a PEM.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </section>

    <div x-data="{ open: @entangle('showCreate') }">
        <x-modal-frame>
            <form class="grid gap-4" wire:submit="createManual">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold">Add private key</h2>
                    <button class="btn" type="button" wire:click="$set('showCreate', false)">Close</button>
                </div>
                <label class="field">
                    <span>Name</span>
                    <input class="input" wire:model="newName" required>
                    @error('newName') <small class="error">{{ $message }}</small> @enderror
                </label>
                <label class="field">
                    <span>Description</span>
                    <input class="input" wire:model="newDescription">
                </label>
                <label class="field">
                    <span>Private key</span>
                    <textarea class="textarea min-h-40" wire:model="newPrivateKey" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----" required></textarea>
                    @error('newPrivateKey') <small class="error">{{ $message }}</small> @enderror
                </label>
                <div class="flex justify-end">
                    <button class="btn btn-primary" type="submit">Continue</button>
                </div>
            </form>
        </x-modal-frame>
    </div>

    <div x-data="{ open: @entangle('editorOpen') }">
        <x-modal-frame class="modal-root-top" close="$wire.closeEditor()">
            <form class="grid gap-4" wire:submit="saveEditor">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold">Edit private key</h2>
                    <button class="btn" type="button" wire:click="closeEditor">Close</button>
                </div>
                <div class="grid gap-4 lg:grid-cols-2">
                    <label class="field">
                        <span>Name</span>
                        <input class="input" wire:model="editName" required>
                        @error('editName') <small class="error">{{ $message }}</small> @enderror
                    </label>
                    <label class="field">
                        <span>Description</span>
                        <input class="input" wire:model="editDescription">
                    </label>
                </div>
                <label class="field">
                    <span>Public key</span>
                    <textarea class="textarea min-h-24" readonly>{{ $editPublicKey }}</textarea>
                </label>
                <x-secret-input label="Private key" model="editPrivateKey" />
                @error('editPrivateKey') <small class="error">{{ $message }}</small> @enderror
                <div class="flex items-center justify-between gap-2">
                    <x-confirm-delete
                        title="Confirm private key deletion?"
                        :name="$editName"
                        action="deleteKey"
                        button="Delete private key"
                        :actions="['This private key will be permanently deleted.']"
                    />
                    <button class="btn btn-primary" type="submit">Save changes</button>
                </div>
            </form>
        </x-modal-frame>
    </div>
</div>
