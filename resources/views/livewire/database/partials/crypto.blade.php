{{-- Encryption methods: a website's own encrypt/decrypt code, to search encrypted columns and read them. --}}
<div class="grid gap-6">
    <x-layer-card title="Encryption methods" helper="When a website saves some columns encrypted (like encryptString() in its helpers), add its method here: search can then encrypt what you type before it looks, and a record can be shown decrypted. The code runs in the website's folder, as the website's user, with its app loaded, so it uses the website's own helpers and keys." flush>
        <x-slot:actions>
            <button class="btn btn-primary" type="button" wire:click="newCryptoMethod"><x-menu-icon name="plus" /> Add method</button>
        </x-slot:actions>
        <div class="overflow-x-auto">
            <table class="table">
                <thead><tr><th>Method</th><th>Runs in</th><th>Encrypt</th><th>Decrypt</th><th class="w-40"></th></tr></thead>
                <tbody>
                    @forelse ($cryptoMethods as $method)
                        <tr wire:key="crypto-{{ $method->id }}">
                            <td class="font-medium">{{ $method->name }}</td>
                            <td class="text-muted">{{ $method->website?->name ?? 'Deleted website' }}{{ $method->boot_laravel ? ' · app loaded' : '' }}</td>
                            <td class="max-w-xs truncate font-mono text-[12.5px] text-muted" title="{{ $method->encrypt_code }}">{{ \Illuminate\Support\Str::limit((string) $method->encrypt_code, 60) }}</td>
                            <td class="max-w-xs truncate font-mono text-[12.5px] text-muted">{{ $method->canDecrypt() ? \Illuminate\Support\Str::limit((string) $method->decrypt_code, 60) : '—' }}</td>
                            <td>
                                <div class="row-actions w-full">
                                    <button class="btn btn-sm" type="button" wire:click="editCryptoMethod({{ $method->id }})">Edit</button>
                                    <button class="icon-btn" type="button" wire:click="deleteCryptoMethod({{ $method->id }})" wire:confirm="Delete the method {{ $method->name }}?" aria-label="Delete"><x-menu-icon name="trash" /></button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="table-empty">No methods yet. Add one to search encrypted columns.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-layer-card>

    @if ($cryptoMethods->isNotEmpty())
        <x-layer-card title="Try a method" helper="Encrypt a text with a method; it is decrypted again to check the method both ways.">
            <form class="flex flex-wrap items-end gap-2" wire:submit="tryCryptoMethod">
                <label class="field w-56">
                    <span class="label">Method</span>
                    <select class="select" wire:model="cryptoTryMethod" aria-label="Method">
                        <option value="">Choose a method</option>
                        @foreach ($cryptoMethods as $method)
                            <option value="{{ $method->id }}">{{ $method->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="field min-w-0 flex-1">
                    <span class="label">Text</span>
                    <input class="input font-mono" wire:model="cryptoTryText" placeholder="AZE1234567" aria-label="Text to encrypt">
                </label>
                <button class="btn btn-primary" type="submit" wire:loading.attr="disabled" wire:target="tryCryptoMethod">Encrypt</button>
            </form>
            @if ($cryptoTried)
                <div class="mt-3 grid gap-2 font-mono text-[12.5px]">
                    @if (isset($cryptoTried['error']))
                        <p class="error">{{ $cryptoTried['error'] }}</p>
                    @else
                        <p><span class="text-muted">Encrypted:</span> <span class="break-all">{{ $cryptoTried['encrypted'] }}</span></p>
                        <p><span class="text-muted">Decrypted again:</span> {{ $cryptoTried['decrypted'] ?? '— (no decrypt code, or it did not decrypt)' }}</p>
                    @endif
                </div>
            @endif
        </x-layer-card>
    @endif

    <x-modal-frame class="modal-wide" open="$wire.cryptoOpen" close="$wire.set('cryptoOpen', false)">
        <form class="grid gap-4" wire:submit="saveCryptoMethod">
            <div class="modal-head">
                <h2>{{ $cryptoId ? 'Edit encryption method' : 'New encryption method' }}</h2>
                <button class="icon-btn" type="button" wire:click="$set('cryptoOpen', false)" aria-label="Close"><x-menu-icon name="close" /></button>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="field">
                    <span class="label">Name</span>
                    <input class="input" wire:model="cryptoName" placeholder="ffkristal passport">
                    @error('cryptoName') <small class="error">{{ $message }}</small> @enderror
                </label>
                <label class="field">
                    <span class="label">Runs in the website</span>
                    <select class="select" wire:model="cryptoWebsite">
                        <option value="">Choose a PHP or Laravel website</option>
                        @foreach ($cryptoWebsites as $website)
                            <option value="{{ $website->id }}">{{ $website->name }}</option>
                        @endforeach
                    </select>
                    @error('cryptoWebsite') <small class="error">{{ $message }}</small> @enderror
                </label>
            </div>
            <label class="flex items-center gap-2 text-[13px]"><input type="checkbox" wire:model="cryptoBoot"> Load the website's Laravel app first (its helpers, config, and .env keys)</label>
            <label class="field">
                <span class="label">Encrypt: the body of <code>function (string $text)</code>, returning the encrypted text</span>
                <textarea class="textarea min-h-24 font-mono text-[12.5px]" wire:model="cryptoEncrypt" spellcheck="false"></textarea>
                @error('cryptoEncrypt') <small class="error">{{ $message }}</small> @enderror
            </label>
            <label class="field">
                <span class="label">Decrypt (optional): the same, returning the plain text</span>
                <textarea class="textarea min-h-20 font-mono text-[12.5px]" wire:model="cryptoDecrypt" spellcheck="false"></textarea>
            </label>
            <p class="text-[12.5px] text-muted">Search only finds an encrypted value when the method gives the same result every time (a fixed IV, like Crypto::encrypt in Laravel sites made with a static IV). Without the app, plain PHP works too: <code>return openssl_encrypt($text, 'AES-256-CBC', 'key', 0, substr(hash('sha256', 'key'), 0, 16));</code></p>
            <div class="flex justify-end gap-2">
                <button class="btn" type="button" wire:click="$set('cryptoOpen', false)">Cancel</button>
                <button class="btn btn-primary" type="submit">Save</button>
            </div>
        </form>
    </x-modal-frame>
</div>
