<?php

namespace App\Support;

/**
 * Everything Laravelify can deploy. A website has a type (its framework) and each
 * type runs one of three ways:
 *
 *   php     nginx → PHP-FPM, with Supervisor workers for Laravel
 *   node    nginx → a Node server kept running by PM2
 *   static  nginx serves the files of a build folder (single-page apps, static sites)
 */
final class SiteTypes
{
    public const RUNTIME_PHP = 'php';

    public const RUNTIME_NODE = 'node';

    public const RUNTIME_STATIC = 'static';

    /**
     * @var array<string, array{label: string, blurb: string}>
     */
    public const GROUPS = [
        'backend' => ['label' => 'Backend', 'blurb' => 'APIs, admin panels, and server apps'],
        'frontend' => ['label' => 'Frontend', 'blurb' => 'Single-page apps and static sites, built once and served by nginx'],
        'fullstack' => ['label' => 'Full-stack', 'blurb' => 'Frontend and server in one app'],
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            'laravel' => [
                'label' => 'Laravel',
                'group' => 'backend',
                'subgroup' => 'PHP',
                'runtime' => self::RUNTIME_PHP,
                'language' => 'PHP',
                'strength' => 'Batteries included: queues, scheduler, Eloquent, Reverb, Livewire, and Inertia.',
                'best' => 'APIs, SaaS backends, and admin panels.',
                'icon' => 'runtime',
            ],
            'php' => [
                'label' => 'PHP',
                'group' => 'backend',
                'subgroup' => 'PHP',
                'runtime' => self::RUNTIME_PHP,
                'language' => 'PHP',
                'strength' => 'Any front-controller PHP app on PHP-FPM: Symfony, Slim, CodeIgniter, or your own.',
                'best' => 'Existing PHP apps and custom backends.',
                'icon' => 'runtime',
            ],
            'express' => [
                'label' => 'Express',
                'group' => 'backend',
                'subgroup' => 'TypeScript / JavaScript',
                'runtime' => self::RUNTIME_NODE,
                'language' => 'JavaScript / TypeScript',
                'strength' => 'Minimal and flexible: the most common Node.js server.',
                'best' => 'REST APIs, webhooks, and small services.',
                'start' => 'npm start',
                'icon' => 'terminal',
            ],
            'nestjs' => [
                'label' => 'NestJS',
                'group' => 'backend',
                'subgroup' => 'TypeScript / JavaScript',
                'runtime' => self::RUNTIME_NODE,
                'language' => 'TypeScript',
                'strength' => 'Structured and testable: modules, dependency injection, built-in validation.',
                'best' => 'Large APIs and enterprise back ends.',
                'build' => 'npm run build',
                'start' => 'node dist/main.js',
                'icon' => 'terminal',
            ],
            'node' => [
                'label' => 'Node.js',
                'group' => 'backend',
                'subgroup' => 'TypeScript / JavaScript',
                'runtime' => self::RUNTIME_NODE,
                'language' => 'JavaScript / TypeScript',
                'strength' => 'Any Node app with a start command: Fastify, Hono, Koa, bots, workers.',
                'best' => 'Anything that listens on a port.',
                'start' => 'npm start',
                'icon' => 'terminal',
            ],
            'react' => [
                'label' => 'React',
                'group' => 'frontend',
                'runtime' => self::RUNTIME_STATIC,
                'language' => 'TypeScript / JavaScript',
                'strength' => 'Massive ecosystem: over 40% market share, and the default choice for jobs worldwide.',
                'best' => 'Interactive SPAs, dynamic web tools, and SaaS dashboards.',
                'build' => 'npm run build',
                'output' => ['dist', 'build'],
                'spa' => true,
                'icon' => 'files',
            ],
            'vue' => [
                'label' => 'Vue.js',
                'group' => 'frontend',
                'runtime' => self::RUNTIME_STATIC,
                'language' => 'JavaScript',
                'strength' => 'Gentle learning curve and a clean single-file-component architecture.',
                'best' => 'Rapid development and mid-sized web portals.',
                'build' => 'npm run build',
                'output' => ['dist'],
                'spa' => true,
                'icon' => 'files',
            ],
            'angular' => [
                'label' => 'Angular',
                'group' => 'frontend',
                'runtime' => self::RUNTIME_STATIC,
                'language' => 'TypeScript',
                'strength' => 'Enterprise rigor: batteries included with a router, forms, and data layers.',
                'best' => 'Large corporate software and dashboards built by big teams.',
                'build' => 'npm run build',
                'output' => ['dist/*/browser', 'dist/*', 'dist'],
                'spa' => true,
                'icon' => 'files',
            ],
            'svelte' => [
                'label' => 'Svelte',
                'group' => 'frontend',
                'runtime' => self::RUNTIME_STATIC,
                'language' => 'TypeScript / JavaScript',
                'strength' => 'A build-time compiler: no virtual DOM, ultra-lean JavaScript output.',
                'best' => 'High-performance apps that must load instantly on slow devices.',
                'build' => 'npm run build',
                'output' => ['dist', 'build'],
                'spa' => true,
                'icon' => 'files',
            ],
            'astro' => [
                'label' => 'Astro',
                'group' => 'frontend',
                'runtime' => self::RUNTIME_STATIC,
                'language' => 'Any framework',
                'strength' => 'Islands architecture: ships zero client-side JavaScript by default.',
                'best' => 'Content-heavy sites, blogs, portfolios, and marketing pages.',
                'build' => 'npm run build',
                'output' => ['dist'],
                'spa' => false,
                'icon' => 'files',
            ],
            'static' => [
                'label' => 'Static HTML',
                'group' => 'frontend',
                'runtime' => self::RUNTIME_STATIC,
                'language' => 'HTML / CSS / JS',
                'strength' => 'No build step: the repository files are served as they are.',
                'best' => 'Landing pages, documentation exports, and plain sites.',
                'output' => ['.'],
                'spa' => false,
                'icon' => 'files',
            ],
            'nextjs' => [
                'label' => 'Next.js',
                'group' => 'fullstack',
                'runtime' => self::RUNTIME_NODE,
                'language' => 'TypeScript / JavaScript',
                'strength' => 'React with server-side rendering: full-stack and SEO friendly.',
                'best' => 'E-commerce, landing pages, and SEO-heavy apps.',
                'build' => 'npm run build',
                'standalone' => true,
                'icon' => 'terminal',
            ],
            'nuxt' => [
                'label' => 'Nuxt',
                'group' => 'fullstack',
                'runtime' => self::RUNTIME_NODE,
                'language' => 'TypeScript / JavaScript',
                'strength' => 'The Vue meta-framework: server rendering, file routing, and an API layer.',
                'best' => 'Vue apps that need SEO and a server.',
                'build' => 'npm run build',
                'start' => 'node .output/server/index.mjs',
                'icon' => 'terminal',
            ],
            'sveltekit' => [
                'label' => 'SvelteKit',
                'group' => 'fullstack',
                'runtime' => self::RUNTIME_NODE,
                'language' => 'TypeScript / JavaScript',
                'strength' => 'Svelte with routing and server rendering. Needs adapter-node.',
                'best' => 'Fast full-stack apps with little code.',
                'build' => 'npm run build',
                'start' => 'node build/index.js',
                'icon' => 'terminal',
            ],
        ];
    }

    /**
     * Ready-made stacks shown in the picker. They create a normal website of the
     * given type, and say what to pair it with.
     *
     * @return array<string, array{type: string, label: string, group: string, language: string, strength: string, best: string, icon: string}>
     */
    public static function stacks(): array
    {
        return [
            'livewire' => [
                'type' => 'laravel',
                'label' => 'Laravel + Livewire',
                'group' => 'fullstack',
                'language' => 'PHP',
                'strength' => 'Reactive UI written in PHP: no separate frontend to build or host.',
                'best' => 'The modern SaaS stack, with one codebase.',
                'icon' => 'runtime',
            ],
            'inertia' => [
                'type' => 'laravel',
                'label' => 'Laravel + Vue / React (Inertia)',
                'group' => 'fullstack',
                'language' => 'PHP + TypeScript / JavaScript',
                'strength' => 'A Vue or React frontend served by Laravel. Assets are built on every deploy.',
                'best' => 'SPA feel with Laravel routing and auth.',
                'icon' => 'runtime',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }

    /**
     * One type's definition. Unknown types fall back to Laravel, so an old or
     * hand-edited row never breaks a page.
     *
     * @return array<string, mixed>
     */
    public static function get(?string $type): array
    {
        return self::all()[$type] ?? self::all()['laravel'];
    }

    public static function exists(?string $type): bool
    {
        return array_key_exists((string) $type, self::all());
    }

    public static function runtime(?string $type): string
    {
        return (string) self::get($type)['runtime'];
    }

    public static function label(?string $type): string
    {
        return (string) self::get($type)['label'];
    }

    /**
     * Types of a group, with Backend split into PHP and TypeScript / JavaScript.
     *
     * @return array<string, array<string, array<string, mixed>>> subgroup (or '') => type => definition
     */
    public static function inGroup(string $group): array
    {
        $result = [];

        foreach (self::all() as $key => $definition) {
            if ($definition['group'] === $group) {
                $result[(string) ($definition['subgroup'] ?? '')][$key] = $definition;
            }
        }

        return $result;
    }
}
