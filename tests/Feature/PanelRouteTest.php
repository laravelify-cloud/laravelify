<?php

namespace Tests\Feature;

use App\Models\CloudflareTunnel;
use App\Models\Setting;
use App\Services\CloudflareApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PanelRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_installer_token_is_saved_and_the_panel_gets_its_route_and_dns(): void
    {
        config(['app.url' => 'https://panel.x.com']);
        CloudflareTunnel::factory()->create([
            'slug' => 'panel-x-com',
            'token' => base64_encode(json_encode(['a' => 'acc123', 't' => 'tun456', 's' => 'secret'])),
        ]);
        $put = [];
        Http::fake(function (Request $request) use (&$put) {
            $url = $request->url();
            $ok = fn (mixed $result) => Http::response(['success' => true, 'errors' => [], 'result' => $result]);

            if (str_contains($url, '/configurations') && $request->method() === 'PUT') {
                $put = $request->data();
            }

            return match (true) {
                str_contains($url, '/cfd_tunnel/tun456/configurations') && $request->method() === 'GET' => $ok(['config' => ['ingress' => [['service' => 'http_status:404']]]]),
                str_contains($url, '/cfd_tunnel/tun456/configurations') => $ok(['config' => []]),
                str_contains($url, '/zones?name=x.com') => $ok([['id' => 'zone1', 'name' => 'x.com']]),
                str_contains($url, '/zones?') => $ok([]),
                str_contains($url, '/dns_records') && $request->method() === 'GET' => $ok([]),
                str_contains($url, '/dns_records') => $ok(['id' => 'new']),
                default => Http::response(['success' => false, 'errors' => [['message' => 'unexpected '.$url]]], 404),
            };
        });
        $file = tempnam(sys_get_temp_dir(), 'cf');
        file_put_contents($file, "abcdefghijklmnopqrstuvwxyz0123456789\n");

        $this->artisan('laravelify:panel-route', ['--token-file' => $file])->assertSuccessful();

        @unlink($file);
        $this->assertSame('abcdefghijklmnopqrstuvwxyz0123456789', Setting::getValue(CloudflareApi::SETTING));
        $this->assertSame(['hostname' => 'panel.x.com', 'service' => 'http://localhost:80'], $put['config']['ingress'][0]);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST' && str_contains($request->url(), '/zones/zone1/dns_records')
            && $request['content'] === 'tun456.cfargotunnel.com');
    }

    public function test_without_an_api_token_it_says_to_add_the_route_by_hand(): void
    {
        Http::fake();

        $this->artisan('laravelify:panel-route')->expectsOutputToContain('add the panel route in Cloudflare yourself')->assertFailed();
        Http::assertNothingSent();
    }
}
