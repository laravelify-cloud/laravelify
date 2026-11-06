<?php

namespace App\Support;

/**
 * Everything Laravelify can deploy. A website has a type (its framework) and each
 * type runs one of three ways:
 *
 *   php     nginx → PHP-FPM, with Supervisor workers for Laravel
 *   node    nginx → a server kept running by PM2: Node.js, or Python, Go and Bun with their
 *           own toolchain (see TOOLCHAINS)
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

            // Python, Go and Bun servers run like Node apps (PM2 keeps them up behind nginx), with
            // their own toolchain on PATH (scripts/with-toolchain.sh), installed on demand.
            'django' => [
                'label' => 'Django',
                'group' => 'backend',
                'subgroup' => 'Python',
                'runtime' => self::RUNTIME_NODE,
                'toolchain' => 'python',
                'language' => 'Python',
                'strength' => 'Batteries included: ORM, admin, auth, migrations. Served by Gunicorn.',
                'best' => 'Content sites, SaaS backends, and admin-heavy apps.',
                'install' => 'uv venv --allow-existing --python "$LARAVELIFY_RUNTIME_VERSION" .venv && uv pip install -r requirements.txt gunicorn',
                'build' => 'python manage.py collectstatic --noinput && python manage.py migrate --noinput',
                'start' => 'gunicorn "$LARAVELIFY_WSGI" --bind "127.0.0.1:$PORT" --workers 2',
                'icon' => 'terminal',
            ],
            'flask' => [
                'label' => 'Flask',
                'group' => 'backend',
                'subgroup' => 'Python',
                'runtime' => self::RUNTIME_NODE,
                'toolchain' => 'python',
                'language' => 'Python',
                'strength' => 'Small and flexible: the most common Python web micro-framework.',
                'best' => 'APIs, dashboards, and small services.',
                'install' => 'uv venv --allow-existing --python "$LARAVELIFY_RUNTIME_VERSION" .venv && uv pip install -r requirements.txt gunicorn',
                'start' => 'gunicorn app:app --bind "127.0.0.1:$PORT" --workers 2',
                'icon' => 'terminal',
            ],
            'fastapi' => [
                'label' => 'FastAPI',
                'group' => 'backend',
                'subgroup' => 'Python',
                'runtime' => self::RUNTIME_NODE,
                'toolchain' => 'python',
                'language' => 'Python',
                'strength' => 'Fast async APIs with typed requests and automatic OpenAPI docs. Served by Uvicorn.',
                'best' => 'REST APIs, AI and ML services.',
                'install' => 'uv venv --allow-existing --python "$LARAVELIFY_RUNTIME_VERSION" .venv && uv pip install -r requirements.txt uvicorn',
                'start' => 'uvicorn main:app --host 127.0.0.1 --port "$PORT" --workers 2',
                'icon' => 'terminal',
            ],
            'go' => [
                'label' => 'Go',
                'group' => 'backend',
                'subgroup' => 'Go',
                'runtime' => self::RUNTIME_NODE,
                'toolchain' => 'go',
                'language' => 'Go',
                'strength' => 'One fast binary: Gin, Echo, Fiber, Chi, or plain net/http.',
                'best' => 'High-traffic APIs and microservices.',
                'install' => 'go mod download',
                'build' => 'go build -o .laravelify-app .',
                'start' => './.laravelify-app',
                'icon' => 'terminal',
            ],
            'spring' => [
                'label' => 'Spring Boot',
                'group' => 'backend',
                'subgroup' => 'Java',
                'runtime' => self::RUNTIME_NODE,
                'toolchain' => 'java',
                'language' => 'Java / Kotlin',
                'strength' => 'The enterprise standard: Spring MVC, Data, Security. Built with Maven or Gradle.',
                'best' => 'Enterprise APIs, banking, and large business apps.',
                'install' => 'true',
                'build' => 'if [ -x ./mvnw ]; then ./mvnw -q -B -DskipTests package; elif [ -x ./gradlew ]; then ./gradlew -q bootJar -x test; else mvn -q -B -DskipTests package; fi',
                'start' => 'java -jar "$(ls target/*.jar build/libs/*.jar 2>/dev/null | grep -v -- -plain | head -n 1)" --server.address=127.0.0.1 --server.port="$PORT"',
                'icon' => 'terminal',
            ],
            'aspnet' => [
                'label' => 'ASP.NET Core',
                'group' => 'backend',
                'subgroup' => 'C# / .NET',
                'runtime' => self::RUNTIME_NODE,
                'toolchain' => 'dotnet',
                'language' => 'C#',
                'strength' => 'Fast, typed web APIs and apps on .NET: minimal APIs, MVC, Razor, Blazor Server.',
                'best' => 'Business apps, APIs, and teams already on Microsoft tools.',
                'install' => 'dotnet restore',
                'build' => 'dotnet publish -c Release -o .laravelify-out --no-restore',
                'start' => 'dotnet ".laravelify-out/$(basename "$(ls *.csproj | head -n 1)" .csproj).dll"',
                'icon' => 'terminal',
            ],
            'bun' => [
                'label' => 'Bun',
                'group' => 'backend',
                'subgroup' => 'TypeScript / JavaScript',
                'runtime' => self::RUNTIME_NODE,
                'toolchain' => 'bun',
                'language' => 'TypeScript / JavaScript',
                'strength' => 'The fast all-in-one JavaScript runtime: Hono, Elysia, or any Bun server.',
                'best' => 'Fast APIs and TypeScript servers without a build step.',
                'install' => 'bun install',
                'start' => 'bun run start',
                'icon' => 'terminal',
            ],

            // Static site generators: built on the server, nginx serves the output.
            'hugo' => [
                'label' => 'Hugo',
                'group' => 'frontend',
                'subgroup' => 'Static sites',
                'runtime' => self::RUNTIME_STATIC,
                'toolchain' => 'hugo',
                'language' => 'Markdown / Go templates',
                'strength' => 'The fastest static site generator: thousands of pages in seconds.',
                'best' => 'Blogs, docs, and marketing sites.',
                'install' => 'true',
                'build' => 'hugo --minify',
                'output' => ['public'],
                'icon' => 'files',
            ],
            'gatsby' => [
                'label' => 'Gatsby',
                'group' => 'frontend',
                'subgroup' => 'Static sites',
                'runtime' => self::RUNTIME_STATIC,
                'language' => 'React',
                'strength' => 'React static sites with a GraphQL data layer and many plugins.',
                'best' => 'Content-rich marketing sites and blogs.',
                'build' => 'npm run build',
                'output' => ['public'],
                'icon' => 'files',
            ],
            'docusaurus' => [
                'label' => 'Docusaurus',
                'group' => 'frontend',
                'subgroup' => 'Static sites',
                'runtime' => self::RUNTIME_STATIC,
                'language' => 'React / MDX',
                'strength' => 'Documentation sites with versioning, search, and blog.',
                'best' => 'Product and open-source documentation.',
                'build' => 'npm run build',
                'output' => ['build'],
                'spa' => true,
                'icon' => 'files',
            ],
            'vitepress' => [
                'label' => 'VitePress',
                'group' => 'frontend',
                'subgroup' => 'Static sites',
                'runtime' => self::RUNTIME_STATIC,
                'language' => 'Vue / Markdown',
                'strength' => 'Fast Vue-powered docs from Markdown.',
                'best' => 'Documentation and simple content sites.',
                'build' => 'npm run docs:build',
                'output' => ['docs/.vitepress/dist', '.vitepress/dist'],
                'icon' => 'files',
            ],
            'eleventy' => [
                'label' => 'Eleventy',
                'group' => 'frontend',
                'subgroup' => 'Static sites',
                'runtime' => self::RUNTIME_STATIC,
                'language' => 'JavaScript / Markdown',
                'strength' => 'Simple and flexible static sites with any template language.',
                'best' => 'Blogs and small sites with little JavaScript.',
                'build' => 'npx @11ty/eleventy',
                'output' => ['_site'],
                'icon' => 'files',
            ],
        ];
    }

    /**
     * Toolchains other than Node.js, with the versions the panel offers first. Any other valid
     * version works too; a missing one is installed before the website uses it.
     *
     * @var array<string, array{label: string, versions: list<string>, default: string, pattern: string}>
     */
    public const TOOLCHAINS = [
        'python' => ['label' => 'Python', 'versions' => ['3.9', '3.10', '3.11', '3.12', '3.13'], 'default' => '3.12', 'pattern' => '/^3\.(8|9|1[0-4])(\.[0-9]{1,2})?$/'],
        'go' => ['label' => 'Go', 'versions' => ['1.22', '1.23', '1.24', '1.25'], 'default' => '1.24', 'pattern' => '/^1\.(1[8-9]|2[0-9])(\.[0-9]{1,2})?$/'],
        'bun' => ['label' => 'Bun', 'versions' => ['1.1', '1.2', '1.3'], 'default' => '1.2', 'pattern' => '/^1\.[0-9]{1,2}(\.[0-9]{1,2})?$/'],
        'java' => ['label' => 'Java', 'versions' => ['17', '21', '25'], 'default' => '21', 'pattern' => '/^(11|17|21|2[2-9])$/'],
        'dotnet' => ['label' => '.NET', 'versions' => ['8.0', '9.0', '10.0'], 'default' => '8.0', 'pattern' => '/^(6|7|8|9|10)\.0$/'],
        'hugo' => ['label' => 'Hugo', 'versions' => ['0.140', '0.145', '0.150'], 'default' => '0.145', 'pattern' => '/^0\.[0-9]{2,3}(\.[0-9]{1,2})?$/'],
    ];

    /** The toolchain a type runs with: node (the default) or one of TOOLCHAINS. */
    public static function toolchain(?string $type): string
    {
        return (string) (self::get($type)['toolchain'] ?? 'node');
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
