<?php

namespace App\Support;

/**
 * Guesses what a repository is from its composer.json, package.json, .nvmrc and index.html:
 * the framework (a SiteTypes key, and a ready-made stack for Laravel), and the PHP and Node
 * versions it asks for, matched to the versions this server offers.
 */
class ProjectDetector
{
    /**
     * @param  array{composer?: ?string, package?: ?string, nvmrc?: ?string, node_version?: ?string, index?: bool}  $files  The files' contents (null when missing).
     * @return array{type: ?string, stack: string, php_version: ?string, node_version: ?string, reason: ?string}
     */
    public static function detect(array $files): array
    {
        $composer = json_decode((string) ($files['composer'] ?? ''), true);
        $package = json_decode((string) ($files['package'] ?? ''), true);
        $composer = is_array($composer) ? $composer : null;
        $package = is_array($package) ? $package : null;

        $phpRequire = array_merge((array) ($composer['require'] ?? []), (array) ($composer['require-dev'] ?? []));
        $npm = array_merge((array) ($package['dependencies'] ?? []), (array) ($package['devDependencies'] ?? []));

        [$type, $stack, $reason] = self::framework($composer !== null, $phpRequire, $package !== null, $npm, (array) ($package['scripts'] ?? []), (bool) ($files['index'] ?? false));

        $nodeWanted = trim((string) ($files['nvmrc'] ?? $files['node_version'] ?? '')) ?: (string) ($package['engines']['node'] ?? '');

        return [
            'type' => $type,
            'stack' => $stack,
            'php_version' => isset($phpRequire['php']) ? self::phpVersion((string) $phpRequire['php']) : null,
            'node_version' => $package !== null || $nodeWanted !== '' ? self::nodeVersion($nodeWanted) : null,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $php
     * @param  array<string, mixed>  $npm
     * @param  array<string, mixed>  $scripts
     * @return array{?string, string, ?string}
     */
    private static function framework(bool $hasComposer, array $php, bool $hasPackage, array $npm, array $scripts, bool $hasIndex): array
    {
        if (isset($php['laravel/framework'])) {
            return match (true) {
                isset($php['inertiajs/inertia-laravel']) => ['laravel', 'inertia', 'laravel/framework and Inertia in composer.json'],
                isset($php['livewire/livewire']) => ['laravel', 'livewire', 'laravel/framework and Livewire in composer.json'],
                default => ['laravel', '', 'laravel/framework in composer.json'],
            };
        }

        $packages = [
            'next' => 'nextjs',
            'nuxt' => 'nuxt',
            '@sveltejs/kit' => 'sveltekit',
            'astro' => 'astro',
            '@angular/core' => 'angular',
            '@nestjs/core' => 'nestjs',
            'express' => 'express',
        ];

        foreach ($packages as $package => $type) {
            if (isset($npm[$package])) {
                return [$type, '', $package.' in package.json'];
            }
        }

        // A Vite single-page app, by its UI library.
        foreach (['react' => 'react', 'vue' => 'vue', 'svelte' => 'svelte'] as $package => $type) {
            if (isset($npm[$package])) {
                return [$type, '', $package.' in package.json'];
            }
        }

        if ($hasComposer) {
            return ['php', '', 'composer.json'];
        }

        if ($hasPackage && isset($scripts['start'])) {
            return ['node', '', 'a start script in package.json'];
        }

        if ($hasIndex) {
            return ['static', '', 'index.html'];
        }

        return [null, '', null];
    }

    /**
     * The newest PHP version this server has that the composer.json constraint allows,
     * like ^8.2 → 8.5, ~8.1.0 → 8.1, 8.3.* → 8.3, >=8.1 <8.4 → 8.3.
     */
    public static function phpVersion(string $constraint): ?string
    {
        $available = (array) config('laravelify.php_versions');
        $allowed = array_filter($available, fn (string $version): bool => self::allows($constraint, $version));

        return $allowed === [] ? null : (string) end($allowed);
    }

    /**
     * The Node version (a major) for what the project asks: .nvmrc ("20", "v22.3.0", "lts/*"),
     * or engines.node (">=20", "^18 || ^20", "20.x"). The lowest offered major that fits, or the newest.
     */
    public static function nodeVersion(string $wanted): ?string
    {
        $available = array_map('strval', (array) config('laravelify.node_versions'));
        $wanted = strtolower(trim($wanted));

        if ($wanted === '' || str_starts_with($wanted, 'lts') || $wanted === 'node' || $wanted === 'latest') {
            return end($available) ?: null;
        }

        if (preg_match('/^v?(\d+)(\.[\dx*]+)*$/', $wanted, $match)) {
            return in_array($match[1], $available, true) ? $match[1] : self::firstFrom($available, (int) $match[1]);
        }

        $fits = array_filter($available, fn (string $major): bool => self::allows($wanted, $major.'.0'));

        return $fits === [] ? (end($available) ?: null) : (string) reset($fits);
    }

    private static function firstFrom(array $available, int $major): ?string
    {
        foreach ($available as $version) {
            if ((int) $version >= $major) {
                return $version;
            }
        }

        return end($available) ?: null;
    }

    /**
     * Whether a version (like "8.3" or "20.0") satisfies a Composer / npm constraint. Covers the
     * forms projects use: ^, ~, >=, >, <=, <, =, x / * wildcards, ranges and || alternatives.
     */
    public static function allows(string $constraint, string $version): bool
    {
        foreach (preg_split('/\s*\|\|?\s*/', trim($constraint)) ?: [] as $alternative) {
            $parts = preg_split('/\s*,\s*|\s+(?=[<>=^~])/', trim($alternative)) ?: [];
            $ok = $parts !== [];

            foreach ($parts as $part) {
                $ok = $ok && self::matches(trim($part), $version);
            }

            if ($ok) {
                return true;
            }
        }

        return false;
    }

    private static function matches(string $part, string $version): bool
    {
        if ($part === '' || $part === '*') {
            return true;
        }

        if (! preg_match('/^(\^|~|>=|<=|>|<|=|==)?\s*v?(\d+)(?:\.(\d+|[x*]))?(?:\.(\d+|[x*]))?/', $part, $match)) {
            return false;
        }

        $operator = $match[1] ?? '';
        $major = (int) $match[2];
        $minorWild = ! isset($match[3]) || in_array($match[3], ['x', '*'], true);
        $minor = $minorWild ? 0 : (int) $match[3];
        [$vMajor, $vMinor] = array_map('intval', array_pad(explode('.', $version), 2, '0'));
        $compare = $vMajor <=> $major ?: $vMinor <=> $minor;

        return match ($operator) {
            '^' => $vMajor === $major && $compare >= 0,
            // ~8.1 allows 8.x from 8.1; ~8.1.0 (with a patch) only 8.1.
            '~' => $vMajor === $major && ($minorWild || (isset($match[4]) ? $vMinor === $minor : $vMinor >= $minor)),
            '>=' => $compare >= 0,
            '>' => $minorWild ? $vMajor > $major : $compare > 0,
            '<=' => $compare <= 0,
            '<' => $compare < 0,
            default => $vMajor === $major && ($minorWild || $vMinor === $minor),
        };
    }
}
