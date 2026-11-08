<?php

namespace Tests\Feature;

use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReportToCloudTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_linked_panel_sends_only_its_version_and_an_unlinked_one_sends_nothing(): void
    {
        Http::fake(['app.laravelify.cloud/*' => Http::response(['version' => 'x'])]);

        $this->artisan('laravelify:cloud-report')->assertSuccessful();
        Http::assertNothingSent();

        Setting::putValue('cloud_api', 'https://app.laravelify.cloud/api/panel');
        Setting::putValue('cloud_token', 'panel-token');
        $this->artisan('laravelify:cloud-report')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://app.laravelify.cloud/api/panel/version'
            && $request->hasHeader('Authorization', 'Bearer panel-token')
            && $request->data() === ['version' => trim((string) file_get_contents(base_path('VERSION')))]);
    }
}
