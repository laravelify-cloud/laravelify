<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class PrivateKey extends Model
{
    protected $fillable = [
        'name',
        'description',
        'private_key',
        'public_key',
        'is_git_related',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'is_git_related' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (PrivateKey $key): void {
            $key->public_key = self::derivePublicKey((string) $key->private_key);
        });
    }

    /**
     * @return HasMany<GithubApp, $this>
     */
    public function githubApps(): HasMany
    {
        return $this->hasMany(GithubApp::class);
    }

    public function isInUse(): bool
    {
        if (array_key_exists('github_apps_count', $this->attributes)) {
            return (int) $this->github_apps_count > 0;
        }

        return $this->githubApps()->exists();
    }

    public static function derivePublicKey(string $privateKey): ?string
    {
        $key = openssl_pkey_get_private(self::normalize($privateKey));

        if ($key === false) {
            return null;
        }

        $details = openssl_pkey_get_details($key);

        return is_array($details) ? ($details['key'] ?? null) : null;
    }

    /**
     * @return array{private: string, public: string}
     */
    public static function generate(string $type): array
    {
        $type = $type === 'ed25519' ? 'ed25519' : 'rsa';
        $config = $type === 'ed25519'
            ? ['private_key_type' => OPENSSL_KEYTYPE_ED25519]
            : ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];

        $opensslConfig = self::opensslConfigPath();

        if ($opensslConfig !== null) {
            $config['config'] = $opensslConfig;
        }

        $key = openssl_pkey_new($config);

        if ($key === false) {
            throw new RuntimeException('OpenSSL could not generate a '.$type.' key.');
        }

        $exported = openssl_pkey_export($key, $private, null, $opensslConfig ? ['config' => $opensslConfig] : []);

        if ($exported !== true || ! is_string($private) || $private === '') {
            throw new RuntimeException('OpenSSL could not export the '.$type.' key.');
        }

        $public = self::derivePublicKey($private);

        if ($public === null) {
            throw new RuntimeException('OpenSSL could not read the new public key.');
        }

        return ['private' => $private, 'public' => $public];
    }

    public static function normalize(string $privateKey): string
    {
        return str_replace(['\\r\\n', '\\n', "\r\n"], ["\n", "\n", "\n"], trim($privateKey));
    }

    private static function opensslConfigPath(): ?string
    {
        $candidates = array_filter([
            getenv('OPENSSL_CONF') ?: null,
            'C:/php/extras/ssl/openssl.cnf',
            'C:/Program Files/Git/usr/ssl/openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/lib/ssl/openssl.cnf',
            '/usr/local/ssl/openssl.cnf',
        ]);

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }
}
