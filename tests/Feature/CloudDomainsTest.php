<?php

namespace Tests\Feature;

use App\Livewire\Websites\Form as WebsiteForm;
use App\Models\Group;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\CloudDomains;
use App\Services\CloudflareRoutes;
use App\Services\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class CloudDomainsTest extends TestCase
{
    use RefreshDatabase;

    private function connect(): void
    {
        putenv('LARAVELIFY_CLOUD_TOKEN=secret-token');
        $this->artisan('laravelify:cloud-connect', ['api' => 'https://app.laravelify.cloud/api/panel', '--prefix' => 'john', '--domain' => 'laravelify.cloud'])->assertSuccessful();
        putenv('LARAVELIFY_CLOUD_TOKEN');
    }

    public function test_the_cloud_token_comes_from_the_environment_and_is_kept_encrypted(): void
    {
        $this->artisan('laravelify:cloud-connect', ['api' => 'https://app.laravelify.cloud/api/panel', '--prefix' => 'john', '--domain' => 'laravelify.cloud'])->assertFailed();
        $this->connect();

        $this->assertSame('secret-token', Setting::getValue('cloud_token'));
        $this->assertTrue(app(CloudDomains::class)->configured());
        $this->assertSame('john-shop.laravelify.cloud', app(CloudDomains::class)->hostFor('Shop'));
    }

    public function test_free_domains_are_asked_from_cloud_and_others_left_alone(): void
    {
        $this->connect();
        Http::fake(['app.laravelify.cloud/*' => Http::response(['host' => 'john-shop.laravelify.cloud'])]);
        $site = Website::factory()->create(['domain' => 'john-shop.laravelify.cloud', 'aliases' => ['shop.example.com']]);

        $report = app(CloudflareRoutes::class)->sync($site, ['john-old.laravelify.cloud']);

        $lines = collect($report['lines'])->keyBy('host');
        $this->assertSame('added through Laravelify Cloud', $lines['john-shop.laravelify.cloud']['dns']);
        $this->assertTrue($lines['john-shop.laravelify.cloud']['ok']);
        $this->assertSame('removed', $lines['john-old.laravelify.cloud']['dns']);
        $this->assertArrayNotHasKey('shop.example.com', $lines->all(), 'Without a tunnel, other domains are not touched.');
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request->url() === 'https://app.laravelify.cloud/api/panel/domains'
            && $request['host'] === 'john-shop.laravelify.cloud' && $request->hasHeader('Authorization', 'Bearer secret-token'));
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && $request['host'] === 'john-old.laravelify.cloud');
    }

    public function test_a_refused_free_domain_is_reported(): void
    {
        $this->connect();
        Http::fake(['app.laravelify.cloud/*' => Http::response(['message' => 'Another client has this name.'], 422)]);
        $site = Website::factory()->create(['domain' => 'john-taken.laravelify.cloud']);

        $problems = CloudflareRoutes::problems(app(CloudflareRoutes::class)->sync($site));

        $this->assertSame(['john-taken.laravelify.cloud (DNS): Another client has this name.'], $problems);
    }

    public function test_the_form_fills_in_a_free_domain(): void
    {
        $this->actingAs(User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]));
        $this->connect();

        Livewire::test(WebsiteForm::class, ['group' => Group::factory()->create()])
            ->assertSee('Free domain')
            ->call('useFreeDomain', 'My Shop')
            ->assertHasErrors('free_domain')
            ->call('useFreeDomain', 'my-shop')
            ->assertHasNoErrors()
            ->assertSet('domain', 'john-my-shop.laravelify.cloud');
    }

    public function test_without_cloud_there_is_no_free_domain(): void
    {
        $this->actingAs(User::factory()->create(['two_factor_secret' => app(TwoFactor::class)->generateSecret(), 'two_factor_confirmed_at' => now()]));

        $this->assertFalse(app(CloudDomains::class)->isFree('john-shop.laravelify.cloud'));
        Livewire::test(WebsiteForm::class, ['group' => Group::factory()->create()])->assertDontSee('Free domain');
    }
}
