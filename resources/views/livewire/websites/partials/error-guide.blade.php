{{-- How to send this website's errors to GlitchTip, for its kind of site. The Sentry SDKs read the key the panel put in .env. --}}
@php
    // Before the website is connected there is no key yet: the steps show where it goes.
    $dsn = ($errorTracking['dsn'] ?? '') ?: 'YOUR_DSN';
    $browserKey = collect($errorTracking['keys'])->first(fn ($key) => preg_match('/^(VITE_|NEXT_PUBLIC_|NUXT_PUBLIC_|PUBLIC_)/', $key)) ?? 'VITE_SENTRY_DSN';
    $steps = match ($errorTracking['guide']) {
        'laravel' => [
            ['Install the Sentry SDK for Laravel', 'composer require sentry/sentry-laravel'],
            ['Report exceptions: in bootstrap/app.php', "->withExceptions(function (Exceptions \$exceptions) {\n    \\Sentry\\Laravel\\Integration::handles(\$exceptions);\n})"],
            ['Optional, errors in the browser too (Vite, Inertia, Livewire pages): npm install @sentry/browser, then in resources/js/app.js', "import * as Sentry from '@sentry/browser';\n\nSentry.init({ dsn: import.meta.env.VITE_SENTRY_DSN });"],
            ['Commit, push (or Deploy), then send a test error from the terminal', 'php artisan sentry:test'],
        ],
        'php' => [
            ['Install the Sentry SDK for PHP', 'composer require sentry/sentry'],
            ['At the top of your entry file (index.php), after the autoloader', "\\Sentry\\init(['dsn' => '{$dsn}']);"],
        ],
        'nextjs' => [
            ['Install the Sentry SDK for Next.js', 'npm install @sentry/nextjs'],
            ['Server errors: instrumentation.ts at the project root', "import * as Sentry from '@sentry/nextjs';\n\nexport function register() {\n  Sentry.init({ dsn: process.env.SENTRY_DSN });\n}\n\nexport const onRequestError = Sentry.captureRequestError;"],
            ['Browser errors: instrumentation-client.ts at the project root', "import * as Sentry from '@sentry/nextjs';\n\nSentry.init({ dsn: process.env.NEXT_PUBLIC_SENTRY_DSN });"],
        ],
        'nuxt' => [
            ['Install the Sentry SDK for Nuxt (the .npmrc line lets npm accept its optional Vite peer, here and when deploying)', "echo legacy-peer-deps=true >> .npmrc\nnpm install @sentry/nuxt"],
            ['In nuxt.config.ts', "modules: ['@sentry/nuxt/module'],\nruntimeConfig: { public: { sentryDsn: '' } },"],
            ['sentry.client.config.ts at the project root', "import * as Sentry from '@sentry/nuxt';\n\nSentry.init({ dsn: useRuntimeConfig().public.sentryDsn });"],
        ],
        'sveltekit' => [
            ['Install the Sentry browser SDK', 'npm install @sentry/browser'],
            ['SvelteKit 3: declare the key in src/env.ts (SvelteKit 2 skips this and imports { env } from \'$env/dynamic/public\' below)', "import { defineEnvVars } from '@sveltejs/kit/env';\n\nexport const variables = defineEnvVars({\n  PUBLIC_SENTRY_DSN: { public: true, schema: (value: string | undefined) => value },\n});"],
            ['src/hooks.client.ts', "import * as Sentry from '@sentry/browser';\nimport { PUBLIC_SENTRY_DSN } from '\$app/env/public';\n\nSentry.init({ dsn: PUBLIC_SENTRY_DSN });\n\nexport const handleError = ({ error }) => {\n  Sentry.captureException(error);\n};"],
        ],
        'vite' => [
            ['Install the Sentry browser SDK', 'npm install @sentry/browser'],
            ['First thing in your entry file (src/main.js / main.ts)', "import * as Sentry from '@sentry/browser';\n\nSentry.init({ dsn: import.meta.env.{$browserKey} });"],
        ],
        'angular' => [
            ['Install the Sentry SDK for Angular', 'npm install @sentry/angular'],
            ['In src/main.ts, before bootstrapping', "import * as Sentry from '@sentry/angular';\n\nSentry.init({ dsn: '{$dsn}' });"],
            ['In the app providers', "{ provide: ErrorHandler, useValue: Sentry.createErrorHandler() }"],
        ],
        'browser' => [
            ['In the <head> of every page, before your own scripts', "<script src=\"https://browser.sentry-cdn.com/11.9.0/bundle.min.js\" crossorigin=\"anonymous\"></script>\n<script>Sentry.init({ dsn: '{$dsn}' });</script>"],
        ],
        'java' => [
            ['Maven (pom.xml), in <dependencies>. Spring Boot 2 uses sentry-spring-boot-starter instead', "<dependency>\n    <groupId>io.sentry</groupId>\n    <artifactId>sentry-spring-boot-starter-jakarta</artifactId>\n    <version>".\App\Support\SentrySetup::JAVA_VERSION."</version>\n</dependency>"],
            ['Or Gradle (build.gradle)', "implementation 'io.sentry:sentry-spring-boot-starter-jakarta:".\App\Support\SentrySetup::JAVA_VERSION."'"],
            ['Nothing else: the starter reads SENTRY_DSN from .env and reports unhandled errors. Commit, push or Deploy.', 'SENTRY_DSN='.$dsn],
        ],
        'dotnet' => [
            ['Install the Sentry SDK for ASP.NET Core', 'dotnet add package Sentry.AspNetCore'],
            ['In Program.cs, right after CreateBuilder', "var builder = WebApplication.CreateBuilder(args);\nbuilder.WebHost.UseSentry();"],
        ],
        'python' => [
            ['Add to requirements.txt (django, flask or fastapi in the brackets)', 'sentry-sdk['.($errorTracking['type'] ?? 'django').']'],
            [($errorTracking['type'] ?? '') === 'django' ? 'At the end of settings.py' : 'At the top of the file that makes the app (app.py or main.py)', "import sentry_sdk\n\nsentry_sdk.init(traces_sample_rate=0.2)"],
            ['It reads SENTRY_DSN from .env and finds the framework by itself.', 'SENTRY_DSN='.$dsn],
        ],
        'go' => [
            ['Add the Sentry SDK for Go', 'go get github.com/getsentry/sentry-go'],
            ['At the start of main()', "if err := sentry.Init(sentry.ClientOptions{Dsn: os.Getenv(\"SENTRY_DSN\")}); err != nil {\n    log.Printf(\"sentry: %v\", err)\n}\ndefer sentry.Flush(2 * time.Second)"],
            ['Report a recovered panic or an error', 'sentry.CaptureException(err)'],
        ],
        'bun' => [
            ['Install the Sentry SDK for Bun', 'bun add @sentry/bun'],
            ['First lines of the file Bun runs (index.ts)', "import * as Sentry from '@sentry/bun';\n\nSentry.init({ dsn: process.env.SENTRY_DSN });"],
        ],
        default => [
            ['Install the Sentry SDK for Node.js', 'npm install @sentry/node'],
            ['First lines of your entry file, before anything else', "const Sentry = require('@sentry/node');\n\nSentry.init({ dsn: process.env.SENTRY_DSN || '{$dsn}' });"],
            ['Express: after your routes', 'Sentry.setupExpressErrorHandler(app);'],
        ],
    };
@endphp

<ol class="guide-steps">
    @foreach ($steps as [$title, $code])
        <li>
            <p>{{ $title }}</p>
            <div class="guide-code">
                <pre><code>{{ $code }}</code></pre>
                <x-copy-button :text="\Illuminate\Support\Js::from($code)" />
            </div>
        </li>
    @endforeach
</ol>
