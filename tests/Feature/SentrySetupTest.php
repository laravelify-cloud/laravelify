<?php

namespace Tests\Feature;

use App\Models\Website;
use App\Support\Examples;
use App\Support\SentrySetup;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SentrySetupTest extends TestCase
{
    private const DSN = 'https://abc123@panel.test/glitchtip/1';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = storage_path('framework/testing/sentry-setup');
        File::deleteDirectory($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    /**
     * Run the setup on a copy of a built-in example, twice (the second run changes nothing).
     *
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    private function apply(string $type, array $attributes = []): array
    {
        if (! is_dir($this->root)) {
            File::copyDirectory(Examples::path($type), $this->root);
        }

        $website = new Website(['type' => $type, ...$attributes]);
        $run = fn () => SentrySetup::apply(
            $website,
            self::DSN,
            fn (string $file) => is_file($this->root.'/'.$file) ? (string) file_get_contents($this->root.'/'.$file) : null,
            function (string $file, string $contents): void {
                File::ensureDirectoryExists(dirname($this->root.'/'.$file));
                file_put_contents($this->root.'/'.$file, $contents);
            },
            fn (string $path) => file_exists($this->root.'/'.$path),
            fn (string $folder) => array_map(fn ($file) => $folder.'/'.str_replace('\\', '/', $file->getRelativePathname()), File::allFiles($this->root.'/'.$folder)),
        );

        $written = $run();
        $this->assertSame([], $run(), 'A second run changes nothing.');

        return $written;
    }

    private function file(string $file): string
    {
        return (string) file_get_contents($this->root.'/'.$file);
    }

    /**
     * @return array<string, array{string, array<string, mixed>, array<string, list<string>>}>
     */
    public static function examples(): array
    {
        return [
            'laravel' => ['laravel', [], ['bootstrap/app.php' => ['->withExceptions(function (Exceptions $exceptions): void {'."\n".'        \Sentry\Laravel\Integration::handles($exceptions);']]],
            'php' => ['php', ['public_dir' => 'public'], ['public/index.php' => ["require_once __DIR__.'/../vendor/autoload.php';", "\\Sentry\\init(['dsn' => '".self::DSN."']);", 'Plain PHP works']]],
            'nextjs' => ['nextjs', [], ['instrumentation.ts' => ['Sentry.init({ dsn: process.env.SENTRY_DSN });', 'onRequestError'], 'instrumentation-client.ts' => ['process.env.NEXT_PUBLIC_SENTRY_DSN']]],
            'nuxt' => ['nuxt', [], ['nuxt.config.ts' => ["modules: ['@sentry/nuxt/module'],", "runtimeConfig: { public: { sentryDsn: '' } },", "compatibilityDate: '2025-07-15'"], '.npmrc' => ['legacy-peer-deps=true'], 'sentry.client.config.ts' => ['useRuntimeConfig().public.sentryDsn']]],
            'react' => ['react', [], ['src/main.jsx' => ["import * as Sentry from '@sentry/browser';\nimport { StrictMode } from 'react'", "import App from './App.jsx'\n\n// Errors go to GlitchTip (added by Laravelify).\nSentry.init({ dsn: import.meta.env.VITE_SENTRY_DSN });"]]],
            'vue' => ['vue', [], ['src/main.js' => ["import * as Sentry from '@sentry/vue';", "const app = createApp(App)\n\n// Errors go to GlitchTip (added by Laravelify).\nSentry.init({ app, dsn: import.meta.env.VITE_SENTRY_DSN });\n\napp.mount('#app')"]]],
            'svelte' => ['svelte', [], ['src/main.js' => ["import * as Sentry from '@sentry/browser';", 'Sentry.init({ dsn: import.meta.env.VITE_SENTRY_DSN });', 'const app = mount(App']]],
            'sveltekit' => ['sveltekit', [], [
                'src/hooks.client.ts' => ["import { PUBLIC_SENTRY_DSN } from '\$app/env/public';", 'Sentry.init({ dsn: PUBLIC_SENTRY_DSN });', 'export const handleError = ({ error }) => {'],
                'src/env.ts' => ["import { defineEnvVars } from '@sveltejs/kit/env';", 'PUBLIC_SENTRY_DSN: { public: true, schema: (value: string | undefined) => value },'],
            ]],
            'astro' => ['astro', [], ['src/pages/index.astro' => ["import * as Sentry from '@sentry/browser';", 'import.meta.env.PUBLIC_SENTRY_DSN', '</script>'."\n\t</head>"]]],
            'angular' => ['angular', [], [
                'src/main.ts' => ["import * as Sentry from '@sentry/angular';", "Sentry.init({ dsn: '".self::DSN."' });", 'bootstrapApplication(App, appConfig)'],
                'src/app/app.config.ts' => ["import { ErrorHandler } from '@angular/core';", "providers: [\n    { provide: ErrorHandler, useValue: Sentry.createErrorHandler() },\n    provideBrowserGlobalErrorListeners(),"],
            ]],
            'static' => ['static', [], ['index.html' => ['<!doctype html>'."\n".'<!-- Errors go to GlitchTip', SentrySetup::CDN, "Sentry.init({ dsn: '".self::DSN."' });", '<h1>Static HTML works</h1>']]],
            'express' => ['express', [], ['server.js' => ["const Sentry = require('@sentry/node');\nSentry.init({ dsn: process.env.SENTRY_DSN });", 'Sentry.setupExpressErrorHandler(app);app.listen(']]],
            'node' => ['node', [], ['server.js' => ["const Sentry = require('@sentry/node');", 'require("http").createServer']]],
            'nestjs' => ['nestjs', [], ['src/main.ts' => ["import * as Sentry from '@sentry/node';", "import { AppModule } from './app.module.js';\n\n// Errors go to GlitchTip (added by Laravelify).\nSentry.init({ dsn: process.env.SENTRY_DSN });"]]],
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, list<string>>  $expected  file => text it must contain
     */
    #[DataProvider('examples')]
    public function test_each_example_gets_the_sdk_set_up(string $type, array $attributes, array $expected): void
    {
        $written = $this->apply($type, $attributes);

        foreach ($expected as $file => $texts) {
            $this->assertContains($file, $written);

            foreach ($texts as $text) {
                $this->assertStringContainsString($text, $this->file($file), $file);
            }
        }
    }

    public function test_every_kind_of_site_has_a_setup(): void
    {
        foreach (array_keys(self::examples()) as $type) {
            $this->assertTrue(Examples::has($type), $type);
        }

        $this->assertSame(['manager' => 'composer', 'package' => 'sentry/sentry-laravel'], SentrySetup::package('laravel'));
        $this->assertSame(['manager' => 'npm', 'package' => '@sentry/vue'], SentrySetup::package('vue'));
        $this->assertNull(SentrySetup::package('static'));
    }

    public function test_sveltekit_2_reads_the_key_from_its_dynamic_public_env(): void
    {
        File::ensureDirectoryExists($this->root.'/src');
        File::put($this->root.'/package.json', '{"devDependencies": {"@sveltejs/kit": "^2.20.0"}}');

        $this->assertSame(['src/hooks.client.ts'], $this->apply('sveltekit'));
        $this->assertStringContainsString("import { env } from '\$env/dynamic/public';", $this->file('src/hooks.client.ts'));
        $this->assertStringContainsString('Sentry.init({ dsn: env.PUBLIC_SENTRY_DSN });', $this->file('src/hooks.client.ts'));
    }

    public function test_a_key_in_the_code_from_an_earlier_glitchtip_becomes_the_current_one(): void
    {
        $old = 'https://0123456789abcdef0123456789abcdef@panel.test/glitchtip/4';
        File::ensureDirectoryExists($this->root);
        File::put($this->root.'/index.html', "<!doctype html><head><script>Sentry.init({ dsn: '{$old}' });</script></head><h1>Shop</h1>\n");

        $this->assertSame(['index.html'], $this->apply('static'));
        $this->assertStringContainsString("Sentry.init({ dsn: '".self::DSN."' });", $this->file('index.html'));
        $this->assertStringNotContainsString($old, $this->file('index.html'));
        $this->assertSame('src/main.ts', SentrySetup::keyFile(new Website(['type' => 'angular'])));
        $this->assertNull(SentrySetup::keyFile(new Website(['type' => 'nuxt'])), 'Nuxt reads its key from .env.');
    }

    public function test_a_file_it_cannot_change_safely_stops_it_with_what_to_do(): void
    {
        File::ensureDirectoryExists($this->root);
        File::put($this->root.'/nuxt.config.ts', "export default defineNuxtConfig({\n  runtimeConfig: { apiSecret: '' },\n})\n");

        try {
            $this->apply('nuxt');
            $this->fail('The setup should stop.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('already has runtimeConfig', $exception->getMessage());
        }
    }
}
