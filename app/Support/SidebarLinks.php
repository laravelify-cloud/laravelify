<?php

namespace App\Support;

use App\Models\Plugin;
use App\Models\ServerService;
use App\Models\Setting;

/**
 * The "Links" section of the sidebar: phpMyAdmin (one-click sign-in) and the
 * links saved on Settings → Links.
 */
class SidebarLinks
{
    public const DEFAULTS = [
        ['label' => 'Cloudflare', 'url' => 'https://dash.cloudflare.com'],
        ['label' => 'GitHub', 'url' => 'https://github.com'],
    ];

    /**
     * @return list<array{label: string, url: string}>
     */
    public static function custom(): array
    {
        $saved = Setting::getValue('sidebar_links');

        if ($saved === null) {
            return self::DEFAULTS;
        }

        $links = json_decode($saved, true);

        return is_array($links)
            ? array_values(array_filter($links, fn ($link): bool => is_array($link) && self::validUrl($link['url'] ?? null) && filled($link['label'] ?? null)))
            : [];
    }

    /** What each plugin's web page is called in the sidebar, and its path. */
    public const PLUGIN_PAGES = [
        'vault' => ['label' => 'Vault UI', 'path' => '/ui/', 'icon' => 'lock'],
        'minio' => ['label' => 'MinIO console', 'path' => '/', 'icon' => 's3'],
    ];

    /**
     * The web pages of the installed, running plugins (Vault UI, MinIO console): on
     * their domain when they have one, on 127.0.0.1:<port> (not localhost, which another program on this computer may hold over IPv6) when the panel itself is opened on
     * localhost (their ports answer on 127.0.0.1 only), otherwise the Plugins page, where a
     * domain can be set.
     *
     * @return list<array{label: string, url: string, icon: string, external: bool, title: string}>
     */
    public static function plugins(?string $panelHost = null): array
    {
        try {
            $plugins = Plugin::query()->where('status', Plugin::RUNNING)->orderBy('key')->get();
        } catch (\Throwable) {
            return [];
        }

        $local = in_array(strtolower((string) $panelHost), ['localhost', '127.0.0.1', '::1', '[::1]'], true);
        $links = [];

        foreach ($plugins as $plugin) {
            $definition = $plugin->definition();
            $port = $definition->webPort() !== null ? ($plugin->ports[$definition->webPort()] ?? null) : null;

            if ($port === null) {
                continue;
            }

            $page = self::PLUGIN_PAGES[$plugin->key] ?? ['label' => $definition->label(), 'path' => '/', 'icon' => 'link'];

            if ($plugin->domain) {
                $links[] = ['label' => $page['label'], 'url' => 'https://'.$plugin->domain.$page['path'], 'icon' => $page['icon'], 'external' => true, 'title' => $plugin->domain.' (basic auth: '.$plugin->basic_auth_user.')'];
            } elseif ($local) {
                $links[] = ['label' => $page['label'], 'url' => 'http://127.0.0.1:'.$port.$page['path'], 'icon' => $page['icon'], 'external' => true, 'title' => '127.0.0.1:'.$port];
            } else {
                $links[] = ['label' => $page['label'], 'url' => route('plugins.index'), 'icon' => $page['icon'], 'external' => false, 'title' => 'Its port answers on the server only: open it on a domain from the Plugins page'];
            }
        }

        return $links;
    }

    public static function phpMyAdminAvailable(): bool
    {
        $domain = (string) ServerService::query()->where('kind', 'phpmyadmin')->value('domain');

        return $domain !== '' && $domain !== 'phpmyadmin.internal';
    }

    public static function validUrl(mixed $url): bool
    {
        return is_string($url) && preg_match('#^https?://[^\s"\'<>]+$#i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
