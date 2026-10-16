<?php

namespace App\Livewire\Concerns;

use App\Models\CryptoMethod;
use App\Models\Website;
use App\Services\CryptoMethods;
use Illuminate\Support\Collection;
use Throwable;

/**
 * The Encryption tab of the Database tool: the websites' own encrypt/decrypt methods, to add,
 * change, delete, and try out.
 */
trait ManagesCryptoMethods
{
    public bool $cryptoOpen = false;

    public ?int $cryptoId = null;

    public string $cryptoName = '';

    public string $cryptoWebsite = '';

    public bool $cryptoBoot = true;

    public string $cryptoEncrypt = 'return encryptString($text);';

    public string $cryptoDecrypt = 'return decryptString($text);';

    public string $cryptoTryMethod = '';

    public string $cryptoTryText = '';

    /** @var array{encrypted?: string, decrypted?: ?string, error?: string}|null */
    public ?array $cryptoTried = null;

    public function newCryptoMethod(): void
    {
        $this->resetErrorBag();
        $this->cryptoId = null;
        $this->cryptoName = '';
        $this->cryptoWebsite = '';
        $this->cryptoBoot = true;
        $this->cryptoEncrypt = 'return encryptString($text);';
        $this->cryptoDecrypt = 'return decryptString($text);';
        $this->cryptoOpen = true;
    }

    public function editCryptoMethod(int $id): void
    {
        $method = CryptoMethod::query()->findOrFail($id);
        $this->resetErrorBag();
        $this->cryptoId = $method->id;
        $this->cryptoName = $method->name;
        $this->cryptoWebsite = (string) $method->website_id;
        $this->cryptoBoot = $method->boot_laravel;
        $this->cryptoEncrypt = (string) $method->encrypt_code;
        $this->cryptoDecrypt = (string) $method->decrypt_code;
        $this->cryptoOpen = true;
    }

    public function saveCryptoMethod(): void
    {
        $phpSites = $this->cryptoWebsites()->pluck('id')->map(fn ($id) => (string) $id)->all();

        $this->validate([
            'cryptoName' => ['required', 'string', 'max:80'],
            'cryptoWebsite' => ['required', 'in:'.implode(',', $phpSites ?: ['none'])],
            'cryptoEncrypt' => ['required', 'string', 'max:2000'],
            'cryptoDecrypt' => ['nullable', 'string', 'max:2000'],
        ], [
            'cryptoWebsite.in' => 'Choose a PHP or Laravel website: the code runs there.',
            'cryptoEncrypt.required' => 'Write the code that encrypts $text and returns the result.',
        ]);

        $method = $this->cryptoId ? CryptoMethod::query()->findOrFail($this->cryptoId) : new CryptoMethod;
        $method->fill([
            'name' => trim($this->cryptoName),
            'website_id' => (int) $this->cryptoWebsite,
            'boot_laravel' => $this->cryptoBoot,
            'encrypt_code' => trim($this->cryptoEncrypt),
            'decrypt_code' => trim($this->cryptoDecrypt) !== '' ? trim($this->cryptoDecrypt) : null,
        ])->save();

        $this->cryptoOpen = false;
        $this->cryptoTryMethod = (string) $method->id;
        $this->toast('Encryption method "'.$method->name.'" saved. Try it below.');
    }

    public function deleteCryptoMethod(int $id): void
    {
        CryptoMethod::query()->whereKey($id)->delete();
        $this->toast('Encryption method deleted.');
    }

    /**
     * Encrypt a text with a method, and decrypt the result again when the method can.
     */
    public function tryCryptoMethod(CryptoMethods $methods): void
    {
        $this->cryptoTried = null;
        $method = CryptoMethod::query()->find((int) $this->cryptoTryMethod);

        if (! $method || $this->cryptoTryText === '') {
            $this->cryptoTried = ['error' => 'Choose a method and type a text.'];

            return;
        }

        try {
            $encrypted = $methods->encrypt($method, $this->cryptoTryText);
            $this->cryptoTried = [
                'encrypted' => $encrypted,
                'decrypted' => $method->canDecrypt() ? ($methods->decrypt($method, [$encrypted])[0] ?? null) : null,
            ];
        } catch (Throwable $exception) {
            $this->cryptoTried = ['error' => $exception->getMessage()];
        }
    }

    /**
     * @return Collection<int, Website>
     */
    protected function cryptoWebsites()
    {
        return Website::query()->orderBy('name')->get()->filter(fn (Website $website): bool => $website->isPhp())->values();
    }
}
