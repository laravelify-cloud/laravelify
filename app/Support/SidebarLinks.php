<?php

namespace App\Support;

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
