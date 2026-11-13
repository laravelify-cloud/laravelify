<?php

namespace App\Plugins;

/**
 * HashiCorp Vault on file storage. Install initializes it (5 unseal keys, 3 needed) and saves the
 * keys and the root token encrypted. Vault starts sealed after every restart until unsealed.
 */
class VaultPlugin extends PluginDefinition
{
    /** Unseal keys Vault makes, and how many unseal it. */
    public const KEY_SHARES = 5;

    public const KEY_THRESHOLD = 3;

    public function key(): string
    {
        return 'vault';
    }

    public function label(): string
    {
        return 'HashiCorp Vault';
    }

    public function description(): string
    {
        return 'Secrets for your apps: keys, tokens, and passwords, encrypted and versioned.';
    }

    public function logo(): string
    {
        return 'V';
    }

    public function color(): string
    {
        return '#1f2328';
    }

    public function ports(): array
    {
        return ['vault' => 8200];
    }

    /** Vault makes its own keys and token at init. */
    public function credentials(): array
    {
        return [];
    }

    public function compose(array $ports): string
    {
        return <<<YAML
services:
  vault:
    image: hashicorp/vault:1.18
    command: ["server"]
    restart: unless-stopped
    cap_add:
      - IPC_LOCK
    environment:
      VAULT_ADDR: "http://127.0.0.1:8200"
      VAULT_LOCAL_CONFIG: '{"storage": {"file": {"path": "/vault/file"}}, "listener": [{"tcp": {"address": "0.0.0.0:8200", "tls_disable": true}}], "ui": true, "disable_mlock": true, "api_addr": "http://127.0.0.1:8200"}'
    ports:
      - "127.0.0.1:{$ports['vault']}:8200"
    volumes:
      - data:/vault/file
volumes:
  data:

YAML;
    }

    public function containerEnv(array $credentials): array
    {
        return [];
    }

    public function websiteEnv(array $ports, array $credentials): array
    {
        return [
            'VAULT_ADDR' => 'http://127.0.0.1:'.$ports['vault'],
            'VAULT_TOKEN' => (string) ($credentials['root_token'] ?? ''),
        ];
    }

    public function shownCredentials(array $ports, array $credentials): array
    {
        $shown = ['Address' => 'http://127.0.0.1:'.$ports['vault'], 'Root token' => (string) ($credentials['root_token'] ?? '')];

        foreach (array_values((array) ($credentials['unseal_keys'] ?? [])) as $index => $key) {
            $shown['Unseal key '.($index + 1)] = (string) $key;
        }

        return $shown;
    }

    public function sealable(): bool
    {
        return true;
    }

    public function webPort(): ?string
    {
        return 'vault';
    }
}
