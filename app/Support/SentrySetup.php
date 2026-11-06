<?php

namespace App\Support;

use App\Models\Website;
use RuntimeException;

/**
 * The code changes that send a website's errors to GlitchTip, per kind of site: which Sentry SDK
 * to install, and where its setup goes (the same steps the GlitchTip tab shows). Files are read
 * and written through callables, so this works on any checkout. Each change is made once: a file
 * that already mentions Sentry is left alone. When a file does not look as expected, it stops
 * with what to do by hand instead of guessing.
 */
class SentrySetup
{
    private const NOTE = 'Errors go to GlitchTip (added by Laravelify).';

    /** The Sentry browser bundle a plain HTML site loads, the version the guide shows too. */
    public const CDN = 'https://browser.sentry-cdn.com/11.9.0/bundle.min.js';

    /** SDK versions written into dependency files, for runtimes whose package manager is not run here. */
    public const JAVA_VERSION = '8.16.0';

    public const DOTNET_VERSION = '6.12.1';

    public const BUN_VERSION = '^11.11.0';

    /** A key of a GlitchTip on a panel, as it is written in code (https://<key>@<panel>/glitchtip/<project>). */
    public const DSN_PATTERN = '#https?://[0-9a-f]{16,}@[A-Za-z0-9.:-]+/glitchtip/\d+#';

    /**
     * The file that holds the key itself, for sites that have no .env when they run (the key is
     * built into the page or the code). Null for the rest: they read it from .env.
     */
    public static function keyFile(Website $website): ?string
    {
        return match ($website->type) {
            'angular' => 'src/main.ts',
            'php' => self::phpEntry($website),
            'static' => self::staticEntry($website),
            default => null,
        };
    }

    private static function phpEntry(Website $website): string
    {
        $folder = trim((string) ($website->public_dir ?: 'public'), '/');

        return ($folder === '' || $folder === '.' ? '' : $folder.'/').'index.php';
    }

    private static function staticEntry(Website $website): string
    {
        $folder = trim((string) $website->public_dir, '/');

        return ($folder === '' || $folder === '.' ? '' : $folder.'/').'index.html';
    }

    /**
     * The package to install, and with which package manager. Null for plain HTML (the CDN script).
     *
     * @return array{manager: 'npm'|'composer', package: string}|null
     */
    public static function package(string $type): ?array
    {
        return match ($type) {
            'laravel' => ['manager' => 'composer', 'package' => 'sentry/sentry-laravel'],
            'php' => ['manager' => 'composer', 'package' => 'sentry/sentry'],
            'nextjs' => ['manager' => 'npm', 'package' => '@sentry/nextjs'],
            'nuxt' => ['manager' => 'npm', 'package' => '@sentry/nuxt'],
            'vue' => ['manager' => 'npm', 'package' => '@sentry/vue'],
            'angular' => ['manager' => 'npm', 'package' => '@sentry/angular'],
            'react', 'svelte', 'sveltekit', 'astro' => ['manager' => 'npm', 'package' => '@sentry/browser'],
            // Written into pom.xml, the .csproj, requirements.txt or package.json by apply(): their
            // package managers run on the next deploy.
            'static', 'spring', 'aspnet', 'django', 'flask', 'fastapi', 'bun', 'go',
            'hugo', 'gatsby', 'docusaurus', 'vitepress', 'eleventy' => null,
            default => ['manager' => 'npm', 'package' => '@sentry/node'],
        };
    }

    /**
     * Change the website's code. Returns the files written, relative to the project.
     *
     * @param  callable(string): ?string  $read  A file's contents, or null when it does not exist.
     * @param  callable(string, string): void  $write
     * @param  callable(string): bool  $exists  Whether a file or folder exists.
     * @param  callable(string): list<string>  $list  The files in a folder, recursively, relative to the project.
     * @return list<string>
     */
    public static function apply(Website $website, string $dsn, callable $read, callable $write, callable $exists, callable $list): array
    {
        $changes = new SentryChanges($read, $write, $exists);

        // A key in the code from an earlier GlitchTip (turned off and on again) becomes this one.
        if (($keyFile = self::keyFile($website)) !== null) {
            $changes->replace($keyFile, self::DSN_PATTERN, $dsn);
        }

        match ($website->type) {
            'laravel' => self::laravel($changes),
            'php' => self::php($changes, $website, $dsn),
            'nextjs' => self::nextjs($changes),
            'nuxt' => self::nuxt($changes),
            'react', 'svelte' => self::vite($changes),
            'vue' => self::vue($changes),
            'sveltekit' => self::sveltekit($changes),
            'astro' => self::astro($changes, $list),
            'angular' => self::angular($changes, $dsn),
            'static' => self::static($changes, $website, $dsn),
            'spring' => self::spring($changes),
            'aspnet' => self::aspnet($changes, $list),
            'django', 'flask', 'fastapi' => self::python($changes, $website, $list),
            'bun' => self::bun($changes, $website),
            'go' => throw new RuntimeException('Go needs go.sum updated with the code: run go get github.com/getsentry/sentry-go, add sentry.Init(sentry.ClientOptions{Dsn: os.Getenv("SENTRY_DSN")}) at the start of main(), and push. The GlitchTip tab shows the steps.'),
            'hugo', 'gatsby', 'docusaurus', 'vitepress', 'eleventy' => throw new RuntimeException('Static site builders put the Sentry script in their own layout or theme: add the two script tags from the GlitchTip tab to your base layout’s <head>.'),
            default => self::node($changes, $website),
        };

        return $changes->written();
    }

    private static function laravel(SentryChanges $changes): void
    {
        $changes->edit('bootstrap/app.php', function (string $code): string {
            $pattern = '/(->withExceptions\(function \(Exceptions \$exceptions\)(?:\s*:\s*void)?\s*\{[ \t]*\n)/';

            if (preg_match($pattern, $code) !== 1) {
                throw new RuntimeException('bootstrap/app.php has no ->withExceptions(function (Exceptions $exceptions) { ... }). Add \Sentry\Laravel\Integration::handles($exceptions); inside it by hand.');
            }

            return (string) preg_replace($pattern, "\$1        \\Sentry\\Laravel\\Integration::handles(\$exceptions);\n", $code, 1);
        });
    }

    private static function php(SentryChanges $changes, Website $website, string $dsn): void
    {
        $folder = trim((string) ($website->public_dir ?: 'public'), '/');
        $folder = $folder === '.' ? '' : $folder;
        $entry = ($folder === '' ? '' : $folder.'/').'index.php';
        $up = $folder === '' ? '' : str_repeat('/..', substr_count($folder, '/') + 1);

        $changes->edit($entry, function (string $code) use ($up, $dsn, $entry): string {
            if (preg_match('/^<\?php[ \t]*\n/', $code) !== 1) {
                throw new RuntimeException($entry.' does not start with <?php. Add \Sentry\init([\'dsn\' => \'...\']); at its top by hand.');
            }

            $autoload = str_contains($code, 'vendor/autoload.php') ? '' : "require_once __DIR__.'{$up}/vendor/autoload.php';\n";

            return (string) preg_replace('/^<\?php[ \t]*\n/', "<?php\n\n// ".self::NOTE."\n{$autoload}\\Sentry\\init(['dsn' => ".var_export($dsn, true)."]);\n", $code, 1);
        });
    }

    private static function nextjs(SentryChanges $changes): void
    {
        $base = $changes->exists('src/app') || $changes->exists('src/pages') ? 'src/' : '';

        $changes->create($base.'instrumentation.ts', '// '.self::NOTE."\nimport * as Sentry from '@sentry/nextjs';\n\nexport function register() {\n  Sentry.init({ dsn: process.env.SENTRY_DSN });\n}\n\nexport const onRequestError = Sentry.captureRequestError;\n");
        $changes->create($base.'instrumentation-client.ts', '// '.self::NOTE."\nimport * as Sentry from '@sentry/nextjs';\n\nSentry.init({ dsn: process.env.NEXT_PUBLIC_SENTRY_DSN });\n");
    }

    private static function nuxt(SentryChanges $changes): void
    {
        // @sentry/nuxt asks for an optional Vite version newer Nuxt does not use: npm accepts it with this.
        $changes->edit('.npmrc', fn (string $code) => ltrim(rtrim($code)."\nlegacy-peer-deps=true\n"), create: true, unless: 'legacy-peer-deps');

        $config = self::firstOf($changes, ['nuxt.config.ts', 'nuxt.config.js', 'nuxt.config.mjs']);
        $changes->edit($config, function (string $code) use ($config): string {
            if (str_contains($code, 'runtimeConfig')) {
                throw new RuntimeException($config.' already has runtimeConfig. Add public: { sentryDsn: \'\' } to it and \'@sentry/nuxt/module\' to modules by hand.');
            }

            if (preg_match('/modules:\s*\[/', $code) === 1) {
                $code = (string) preg_replace('/modules:\s*\[/', "modules: ['@sentry/nuxt/module', ", $code, 1);
                $add = '';
            } else {
                $add = "  modules: ['@sentry/nuxt/module'],\n";
            }

            if (preg_match('/defineNuxtConfig\(\{[ \t]*\n/', $code) !== 1) {
                throw new RuntimeException($config.' has no defineNuxtConfig({ ... }). Follow the steps in the GlitchTip tab by hand.');
            }

            return (string) preg_replace('/(defineNuxtConfig\(\{[ \t]*\n)/', "\$1{$add}  // ".self::NOTE." The key comes from NUXT_PUBLIC_SENTRY_DSN.\n  runtimeConfig: { public: { sentryDsn: '' } },\n", $code, 1);
        });

        $changes->create('sentry.client.config.ts', '// '.self::NOTE."\nimport * as Sentry from '@sentry/nuxt';\n\nSentry.init({ dsn: useRuntimeConfig().public.sentryDsn });\n");
    }

    /** React and Svelte: errors reach the browser, where @sentry/browser catches them. */
    private static function vite(SentryChanges $changes): void
    {
        $entry = self::firstOf($changes, ['src/main.tsx', 'src/main.ts', 'src/main.jsx', 'src/main.js', 'src/index.tsx', 'src/index.jsx', 'src/index.js']);
        $changes->edit($entry, fn (string $code) => self::afterImports($code, "import * as Sentry from '@sentry/browser';", '// '.self::NOTE."\nSentry.init({ dsn: import.meta.env.VITE_SENTRY_DSN });"));
    }

    /** Vue handles component errors itself (and only logs them), so @sentry/vue hooks into the app. */
    private static function vue(SentryChanges $changes): void
    {
        $entry = self::firstOf($changes, ['src/main.ts', 'src/main.js']);
        $changes->edit($entry, function (string $code) use ($entry): string {
            $init = '// '.self::NOTE."\nSentry.init({ app, dsn: import.meta.env.VITE_SENTRY_DSN });\n\n";

            if (preg_match('/^createApp\(([^\n]*?)\)\.mount\(([^\n]*?)\);?[ \t]*$/m', $code) === 1) {
                $code = (string) preg_replace('/^createApp\(([^\n]*?)\)\.mount\(([^\n]*?)\);?[ \t]*$/m', "const app = createApp(\$1)\n\n{$init}app.mount(\$2)", $code, 1);
            } elseif (preg_match('/^(\s*)app\.mount\(/m', $code) === 1 && preg_match('/\bconst app = createApp\(/', $code) === 1) {
                $code = (string) preg_replace('/^(\s*)app\.mount\(/m', $init.'$1app.mount(', $code, 1);
            } else {
                throw new RuntimeException($entry.' does not create the app as createApp(App).mount(...) or const app = createApp(App). Add Sentry.init({ app, dsn: import.meta.env.VITE_SENTRY_DSN }) after createApp by hand.');
            }

            return self::afterImports($code, "import * as Sentry from '@sentry/vue';", '');
        });
    }

    /**
     * SvelteKit: errors while loading or rendering go to handleError, the rest to the browser. The
     * key is read when the app starts. SvelteKit 3 only passes variables declared in src/env.ts
     * (defineEnvVars) to $app/env/public; SvelteKit 2 has $env/dynamic/public.
     */
    private static function sveltekit(SentryChanges $changes): void
    {
        $ts = ! $changes->exists('src/hooks.client.js');
        $file = $ts ? 'src/hooks.client.ts' : 'src/hooks.client.js';
        $kit3 = preg_match('/"@sveltejs\/kit"\s*:\s*"[^"\d]*(\d+)/', (string) $changes->read('package.json'), $version) === 1 && (int) $version[1] >= 3;

        if ($kit3) {
            $env = $changes->exists('src/env.js') ? 'src/env.js' : 'src/env.ts';
            $value = str_ends_with($env, '.ts') ? '(value: string | undefined)' : '(value)';
            $entry = '  // '.self::NOTE." Read when the app starts, from .env.\n  PUBLIC_SENTRY_DSN: { public: true, schema: {$value} => value },\n";

            $changes->edit($env, function (string $code) use ($env, $entry): string {
                if ($code === '') {
                    return "import { defineEnvVars } from '@sveltejs/kit/env';\n\nexport const variables = defineEnvVars({\n{$entry}});\n";
                }

                if (preg_match('/defineEnvVars\(\{[ \t]*\n/', $code) !== 1) {
                    throw new RuntimeException($env.' has no defineEnvVars({ ... }). Add PUBLIC_SENTRY_DSN: { public: true, schema: (value) => value } to your variables by hand.');
                }

                return (string) preg_replace('/(defineEnvVars\(\{[ \t]*\n)/', '$1'.str_replace(['\\', '$'], ['\\\\', '\\$'], $entry), $code, 1);
            }, create: true);

            $setup = "import * as Sentry from '@sentry/browser';\nimport { PUBLIC_SENTRY_DSN } from '\$app/env/public';";
            $init = '// '.self::NOTE."\nSentry.init({ dsn: PUBLIC_SENTRY_DSN });";
        } else {
            $setup = "import * as Sentry from '@sentry/browser';\nimport { env } from '\$env/dynamic/public';";
            $init = '// '.self::NOTE."\nSentry.init({ dsn: env.PUBLIC_SENTRY_DSN });";
        }

        $changes->edit($file, function (string $code) use ($setup, $init, $file): string {
            if (str_contains($code, 'handleError')) {
                throw new RuntimeException($file.' already has a handleError hook. Call Sentry.captureException(error) in it by hand.');
            }

            return rtrim(self::afterImports($code, $setup, $init))."\n\nexport const handleError = ({ error }) => {\n  Sentry.captureException(error);\n};\n";
        }, create: true);
    }

    /** Astro: the setup script goes into every layout (or, without layouts, every page) with a <head>. */
    private static function astro(SentryChanges $changes, callable $list): void
    {
        $files = array_values(array_filter($list('src'), fn (string $file) => str_ends_with($file, '.astro')));
        $layouts = array_values(array_filter($files, fn (string $file) => str_starts_with($file, 'src/layouts/')));
        $targets = array_values(array_filter($layouts !== [] ? $layouts : $files, fn (string $file) => str_contains((string) $changes->read($file), '</head>')));

        if ($targets === []) {
            throw new RuntimeException('No .astro layout or page with a <head> was found. Add the script from the GlitchTip tab to your layout by hand.');
        }

        foreach ($targets as $file) {
            $changes->edit($file, fn (string $code) => (string) preg_replace('#(\s*)</head>#', "\$1\t<!-- ".self::NOTE." -->\$1\t<script>\n\t\t\timport * as Sentry from '@sentry/browser';\n\n\t\t\tSentry.init({ dsn: import.meta.env.PUBLIC_SENTRY_DSN });\n\t\t</script>\$1</head>", $code, 1));
        }
    }

    /** Angular catches errors in its ErrorHandler: Sentry's takes its place. The key is built in (no .env at run time). */
    private static function angular(SentryChanges $changes, string $dsn): void
    {
        $changes->edit('src/main.ts', fn (string $code) => self::afterImports($code, "import * as Sentry from '@sentry/angular';", '// '.self::NOTE."\nSentry.init({ dsn: ".self::js($dsn).' });'));

        $config = self::firstOf($changes, ['src/app/app.config.ts']);
        $changes->edit($config, function (string $code) use ($config): string {
            if (preg_match('/providers:\s*\[/', $code) !== 1) {
                throw new RuntimeException($config.' has no providers: [ ... ]. Add { provide: ErrorHandler, useValue: Sentry.createErrorHandler() } to your providers by hand.');
            }

            $code = (string) preg_replace('/(providers:\s*\[)(\s*)/', '$1$2{ provide: ErrorHandler, useValue: Sentry.createErrorHandler() },$2', $code, 1);

            $imports = preg_match('/import\s*\{[^}]*\bErrorHandler\b[^}]*\}\s*from\s*[\'"]@angular\/core[\'"]/', $code) === 1 ? '' : "import { ErrorHandler } from '@angular/core';\n";

            return self::afterImports($code, $imports."import * as Sentry from '@sentry/angular';", '');
        });
    }

    private static function static(SentryChanges $changes, Website $website, string $dsn): void
    {
        $folder = trim((string) $website->public_dir, '/');
        $file = ($folder === '' || $folder === '.' ? '' : $folder.'/').'index.html';
        $tags = '<!-- '.self::NOTE." -->\n<script src=\"".self::CDN."\" crossorigin=\"anonymous\"></script>\n<script>Sentry.init({ dsn: ".self::js($dsn)." });</script>\n";

        $changes->edit($file, function (string $html) use ($tags, $file): string {
            return match (true) {
                preg_match('#</head>#i', $html) === 1 => (string) preg_replace('#</head>#i', $tags.'</head>', $html, 1),
                preg_match('#<!doctype html>#i', $html) === 1 => (string) preg_replace('#(<!doctype html>)#i', "\$1\n".$tags, $html, 1),
                default => throw new RuntimeException($file.' has no <head>. Add the two script tags from the GlitchTip tab to it by hand.'),
            };
        });
    }

    /** Node servers: Sentry starts first; an Express app also gets Sentry's error handler. */
    private static function node(SentryChanges $changes, Website $website): void
    {
        $package = json_decode((string) $changes->read('package.json'), true);
        $package = is_array($package) ? $package : [];
        $entry = self::nodeEntry($changes, $package, $website);
        $module = ($package['type'] ?? null) === 'module' || preg_match('/\.(mjs|ts|mts)$/', $entry) === 1;

        $changes->edit($entry, function (string $code) use ($module, $entry): string {
            $code = $module
                ? self::afterImports($code, "import * as Sentry from '@sentry/node';", '// '.self::NOTE."\nSentry.init({ dsn: process.env.SENTRY_DSN });")
                : '// '.self::NOTE."\nconst Sentry = require('@sentry/node');\nSentry.init({ dsn: process.env.SENTRY_DSN });\n\n".$code;

            // Express answers errors itself: its error handler hands them to Sentry first.
            if (preg_match('/require\(["\']express["\']\)|from ["\']express["\']/', $code) === 1) {
                if (preg_match('/\bapp\.listen\(/', $code) !== 1) {
                    throw new RuntimeException($entry.' uses Express but has no app.listen(. Add Sentry.setupExpressErrorHandler(app); after your routes by hand.');
                }

                $code = (string) preg_replace('/\bapp\.listen\(/', 'Sentry.setupExpressErrorHandler(app);app.listen(', $code, 1);
            }

            return $code;
        });
    }

    /**
     * The file the server starts from: NestJS's src/main.ts, else "main", else the start script's file.
     *
     * @param  array<string, mixed>  $package
     */
    private static function nodeEntry(SentryChanges $changes, array $package, Website $website): string
    {
        if ($website->type === 'nestjs' && $changes->exists('src/main.ts')) {
            return 'src/main.ts';
        }

        $start = (string) ($website->start_command ?: ($package['scripts']['start'] ?? ''));
        $candidates = array_filter([
            is_string($package['main'] ?? null) ? ltrim((string) $package['main'], './') : null,
            preg_match('/\bnode\s+(?:--[^\s]+\s+)*([^\s]+\.(?:c|m)?js)\b/', $start, $match) === 1 ? ltrim($match[1], './') : null,
            'server.js', 'index.js', 'app.js', 'src/index.js', 'src/server.js',
        ]);

        foreach ($candidates as $file) {
            if ($changes->exists($file)) {
                return $file;
            }
        }

        throw new RuntimeException('Could not find the file the server starts from (package.json "main" or the start script). Add the Sentry lines from the GlitchTip tab to it by hand.');
    }

    /**
     * Spring Boot: the Sentry starter in pom.xml or build.gradle. It reads SENTRY_DSN from the
     * environment (the site's .env) and reports unhandled errors by itself.
     */
    private static function spring(SentryChanges $changes): void
    {
        $artifact = fn (?string $build) => preg_match('/spring-boot[^\n]{0,80}?[\'"<>:]\s*2\.\d/', (string) $build) === 1 ? 'sentry-spring-boot-starter' : 'sentry-spring-boot-starter-jakarta';

        if ($changes->exists('pom.xml')) {
            $changes->edit('pom.xml', function (string $xml) use ($artifact): string {
                if (preg_match('#<dependencies>\s*\n#', $xml) !== 1) {
                    throw new RuntimeException('pom.xml has no <dependencies>. Add io.sentry:'.$artifact($xml).' '.self::JAVA_VERSION.' to it by hand.');
                }

                $dependency = "<dependency>\n            <!-- ".self::NOTE." -->\n            <groupId>io.sentry</groupId>\n            <artifactId>".$artifact($xml)."</artifactId>\n            <version>".self::JAVA_VERSION."</version>\n        </dependency>\n        ";

                return (string) preg_replace('#(<dependencies>\s*\n\s*)#', '$1'.$dependency, $xml, 1);
            }, unless: 'io.sentry');

            return;
        }

        $gradle = $changes->exists('build.gradle.kts') ? 'build.gradle.kts' : 'build.gradle';
        $changes->edit($gradle, function (string $code) use ($artifact, $gradle): string {
            if (preg_match('/^dependencies\s*\{[ \t]*$/m', $code) !== 1) {
                throw new RuntimeException($gradle.' has no dependencies { } block. Add io.sentry:'.$artifact($code).':'.self::JAVA_VERSION.' to it by hand.');
            }

            $line = str_ends_with($gradle, '.kts')
                ? 'implementation("io.sentry:'.$artifact($code).':'.self::JAVA_VERSION.'")'
                : "implementation 'io.sentry:".$artifact($code).':'.self::JAVA_VERSION."'";

            return (string) preg_replace('/^(dependencies\s*\{[ \t]*\n)/m', '$1    // '.self::NOTE."\n    ".$line."\n", $code, 1);
        }, unless: 'io.sentry');
    }

    /**
     * ASP.NET Core: the Sentry.AspNetCore package in the .csproj and UseSentry() on the web host.
     * It reads SENTRY_DSN from the environment.
     *
     * @param  callable(string): list<string>  $list
     */
    private static function aspnet(SentryChanges $changes, callable $list): void
    {
        $project = collect($list('.'))->map(fn (string $file) => (string) preg_replace('#^\./#', '', $file))->first(fn (string $file) => ! str_contains($file, '/') && str_ends_with($file, '.csproj'))
            ?? throw new RuntimeException('No .csproj was found at the top of the repository. Add the Sentry.AspNetCore package by hand.');

        $changes->edit($project, function (string $xml) use ($project): string {
            if (! str_contains($xml, '</Project>')) {
                throw new RuntimeException($project.' has no </Project>. Add the Sentry.AspNetCore package by hand.');
            }

            return str_replace('</Project>', "  <ItemGroup>\n    <!-- ".self::NOTE." -->\n    <PackageReference Include=\"Sentry.AspNetCore\" Version=\"".self::DOTNET_VERSION."\" />\n  </ItemGroup>\n</Project>", $xml);
        });

        $changes->edit('Program.cs', function (string $code): string {
            $comment = '// '.self::NOTE;

            if (preg_match('/^([ \t]*)var\s+(\w+)\s*=\s*WebApplication\.CreateBuilder\(([^)]*)\);[ \t]*$/m', $code, $match) === 1) {
                return str_replace($match[0], $match[0]."\n".$match[1].$match[2].'.WebHost.UseSentry(); '.$comment, $code);
            }

            if (preg_match('/^([ \t]*)var\s+(\w+)\s*=\s*WebApplication\.CreateBuilder\(([^)]*)\)\.Build\(\);[ \t]*$/m', $code, $match) === 1) {
                return str_replace($match[0], $match[1].'var builder = WebApplication.CreateBuilder('.$match[3].");\n".$match[1].'builder.WebHost.UseSentry(); '.$comment."\n".$match[1].'var '.$match[2].' = builder.Build();', $code);
            }

            throw new RuntimeException('Program.cs does not make the app with WebApplication.CreateBuilder(...). Add builder.WebHost.UseSentry(); after it by hand.');
        });
    }

    /**
     * Django, Flask and FastAPI: sentry-sdk with the framework's extra in requirements.txt, and
     * sentry_sdk.init() where the app starts. The SDK reads SENTRY_DSN and finds the framework.
     *
     * @param  callable(string): list<string>  $list
     */
    private static function python(SentryChanges $changes, Website $website, callable $list): void
    {
        $changes->edit('requirements.txt', fn (string $text) => rtrim($text)."\nsentry-sdk[".$website->type."]\n", unless: 'sentry-sdk');

        $file = match ($website->type) {
            'django' => collect($list('.'))->map(fn (string $file) => (string) preg_replace('#^\./#', '', $file))->first(fn (string $file) => str_ends_with($file, '/settings.py') && substr_count($file, '/') === 1)
                ?? throw new RuntimeException('No settings.py was found next to manage.py. Add the sentry_sdk.init() lines from the GlitchTip tab to your settings by hand.'),
            'flask' => self::firstOf($changes, ['app.py', 'wsgi.py', 'main.py', 'app/__init__.py']),
            default => self::firstOf($changes, ['main.py', 'app/main.py', 'app.py']),
        };

        $init = 'import sentry_sdk  # '.self::NOTE."\n\nsentry_sdk.init(traces_sample_rate=0.2)\n";

        $changes->edit($file, fn (string $code) => $website->type === 'django'
            ? rtrim($code)."\n\n".$init
            : $init."\n".ltrim($code), unless: 'sentry_sdk');
    }

    /** Bun: @sentry/bun in package.json, set up at the top of the file it starts from. */
    private static function bun(SentryChanges $changes, Website $website): void
    {
        $package = json_decode((string) $changes->read('package.json'), true);

        if (! is_array($package)) {
            throw new RuntimeException('package.json is missing or not valid JSON. Add @sentry/bun by hand.');
        }

        $start = (string) ($website->start_command ?: ($package['scripts']['start'] ?? ''));
        $entry = preg_match('/\bbun\s+(?:run\s+)?([^\s]+\.(?:ts|js|mjs|tsx))\b/', $start, $match) === 1
            ? ltrim($match[1], './')
            : self::firstOf($changes, array_filter([is_string($package['module'] ?? null) ? $package['module'] : null, is_string($package['main'] ?? null) ? $package['main'] : null, 'index.ts', 'src/index.ts', 'index.js']));

        $changes->edit('package.json', function (string $json) use ($package): string {
            $package['dependencies'] = ['@sentry/bun' => self::BUN_VERSION] + ($package['dependencies'] ?? []);

            return json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }, unless: '@sentry/bun');

        $changes->edit($entry, fn (string $code) => self::afterImports($code, "import * as Sentry from '@sentry/bun';", '// '.self::NOTE."\nSentry.init({ dsn: process.env.SENTRY_DSN, tracesSampleRate: 0.2 });"));
    }

    /** @param  list<string>  $files */
    private static function firstOf(SentryChanges $changes, array $files): string
    {
        foreach ($files as $file) {
            if ($changes->exists($file)) {
                return $file;
            }
        }

        throw new RuntimeException('Could not find '.implode(' or ', $files).'. Follow the steps in the GlitchTip tab by hand.');
    }

    /**
     * The import lines at the top, the setup after the last single-line import (or at the top).
     */
    private static function afterImports(string $code, string $imports, string $setup): string
    {
        if (preg_match_all('/^import\s[^\n]*[\'"];?[ \t]*$/m', $code, $matches, PREG_OFFSET_CAPTURE) > 0) {
            $last = end($matches[0]);
            $at = $last[1] + strlen($last[0]);

            return $imports."\n".substr($code, 0, $at).($setup === '' ? '' : "\n\n".$setup).substr($code, $at);
        }

        return $imports."\n\n".($setup === '' ? '' : $setup."\n\n").ltrim($code);
    }

    private static function js(string $value): string
    {
        return "'".str_replace(['\\', "'"], ['\\\\', "\\'"], $value)."'";
    }
}
