<?php

namespace Tests\Feature;

use App\Livewire\Groups\Index as GroupsIndex;
use App\Livewire\Groups\Show as GroupShow;
use App\Livewire\Websites\Files;
use App\Livewire\Websites\Show as WebsiteShow;
use App\Models\Group;
use App\Models\TrashItem;
use App\Models\User;
use App\Models\Website;
use App\Services\CommandResult;
use App\Services\CommandRunner;
use App\Services\GithubWebhook;
use App\Services\TwoFactor;
use App\Services\WebsiteSwitch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery\MockInterface;
use Tests\TestCase;

class GroupsAndWebsitesBulkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'two_factor_secret' => app(TwoFactor::class)->generateSecret(),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->actingAs($this->user);
    }

    /**
     * Record the host-apply.sh actions the panel asks for.
     *
     * @return \ArrayObject<int, list<string>>
     */
    private function recordHostActions(): \ArrayObject
    {
        $calls = new \ArrayObject;
        config(['laravelify.host_commands' => '1']);
        $this->mock(CommandRunner::class, function (MockInterface $mock) use ($calls) {
            $mock->shouldReceive('supportsHostCommands')->andReturnTrue();
            $mock->shouldReceive('run')->andReturnUsing(function (array $command) use ($calls) {
                $calls[] = array_slice($command, -3);

                return new CommandResult(true, false, $command, 'ok', 0);
            });
        });

        return $calls;
    }

    public function test_a_turned_off_website_shows_a_page_stops_and_refuses_deploys(): void
    {
        $calls = $this->recordHostActions();
        Queue::fake();
        $website = Website::factory()->create([
            'slug' => 'shop',
            'nginx_config' => "server {\n    listen 80;\n    listen [::]:80;\n    server_name shop.example.com;\n    root /var/www/shop/public;\n}\n",
        ]);

        Livewire::test(WebsiteShow::class, ['website' => $website])
            ->call('setEnabled', false)
            ->assertSee('Turned off');

        $this->assertFalse($website->fresh()->enabled);
        $this->assertContains(['site-disable', 'shop'], array_map(fn ($call) => array_slice($call, -2), $calls->getArrayCopy()));
        $page = File::get(storage_path('app/rendered/sites/shop/nginx.conf'));
        $this->assertStringContainsString('listen [::]:80;', $page);
        $this->assertStringContainsString('server_name shop.example.com;', $page);
        $this->assertStringContainsString('return 503', $page);
        $this->assertStringNotContainsString('/var/www/shop/public', $page);

        $this->assertNull($website->fresh()->queueDeployment('manual'), 'A turned-off site is not deployed.');
        $result = app(GithubWebhook::class)->handle($website->fresh(), ['ref' => 'refs/heads/'.$website->branch, 'after' => str_repeat('a', 40)], 'push');
        $this->assertSame('website is turned off', $result['reason'] ?? null);

        Livewire::test(WebsiteShow::class, ['website' => $website->fresh()])->call('setEnabled', true);
        $this->assertTrue($website->fresh()->enabled);
        $this->assertContains(['site', 'shop', 'laravel'], $calls->getArrayCopy());
    }

    public function test_a_group_turns_all_its_websites_off_and_on(): void
    {
        $this->recordHostActions();
        $group = Group::factory()->create();
        $sites = Website::factory()->count(2)->create(['group_id' => $group->id]);

        Livewire::test(GroupShow::class, ['group' => $group])
            ->call('setGroupEnabled', false)
            ->assertSee('This group is turned off');

        $this->assertFalse($group->fresh()->enabled);
        $this->assertSame([false, false], $sites->map(fn ($site) => $site->fresh()->enabled)->all());

        Livewire::test(GroupsIndex::class)
            ->assertSee('Turned off')
            ->call('setEnabled', [$group->id], true)
            ->assertDispatched('bulk-done');

        $this->assertTrue($group->fresh()->enabled);
        $this->assertSame([true, true], $sites->map(fn ($site) => $site->fresh()->enabled)->all());
    }

    public function test_websites_are_switched_and_deleted_in_bulk(): void
    {
        $this->recordHostActions();
        $group = Group::factory()->create();
        [$one, $two, $three] = Website::factory()->count(3)->create(['group_id' => $group->id])->all();
        $elsewhere = Website::factory()->create();

        $component = Livewire::test(GroupShow::class, ['group' => $group])
            ->call('setWebsitesEnabled', [$one->id, $two->id, $elsewhere->id], false);

        $this->assertFalse($one->fresh()->enabled);
        $this->assertFalse($two->fresh()->enabled);
        $this->assertTrue($elsewhere->fresh()->enabled, 'Only websites of this group are touched.');

        $component->set('confirmName', 'nope')->call('deleteWebsites', [$one->id, $two->id])->assertHasErrors('confirmName');
        $this->assertModelExists($one);

        $component->set('confirmName', 'delete')->call('deleteWebsites', [$one->id, $two->id])->assertHasNoErrors();
        $this->assertModelMissing($one);
        $this->assertModelMissing($two);
        $this->assertModelExists($three);
    }

    public function test_only_empty_groups_are_deleted_in_bulk(): void
    {
        $empty = Group::factory()->create(['name' => 'Empty']);
        $full = Group::factory()->create(['name' => 'Full']);
        Website::factory()->create(['group_id' => $full->id]);

        Livewire::test(GroupsIndex::class)
            ->set('confirmName', 'delete')
            ->call('deleteMany', [$empty->id, $full->id])
            ->assertDispatched('toast', type: 'warning');

        $this->assertModelMissing($empty);
        $this->assertModelExists($full);
    }

    public function test_trash_items_are_restored_and_deleted_for_good_in_bulk(): void
    {
        $root = str_replace('\\', '/', sys_get_temp_dir()).'/laravelify-bulk-trash-'.uniqid();
        File::ensureDirectoryExists($root.'/docs');
        file_put_contents($root.'/a.txt', 'a');
        file_put_contents($root.'/b.txt', 'b');
        $website = Website::factory()->create(['deploy_path' => $root]);

        try {
            $component = Livewire::test(Files::class, ['website' => $website])->call('deleteEntries', ['a.txt', 'b.txt', 'docs']);
            $this->assertSame(3, TrashItem::query()->count());

            $component->call('restoreTrashPaths', ['a.txt', 'docs'])->assertDispatched('bulk-done');
            $this->assertFileExists($root.'/a.txt');
            $this->assertDirectoryExists($root.'/docs');

            $component->call('purgeTrashPaths', ['b.txt'], '000000')->assertHasErrors('code');
            $this->assertSame(1, TrashItem::query()->count());

            $component->call('purgeTrashPaths', ['b.txt'], app(TwoFactor::class)->currentCode($this->user))->assertHasNoErrors();
            $this->assertSame(0, TrashItem::query()->count());
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_turned_off_page_keeps_every_address_of_the_site(): void
    {
        $website = Website::factory()->make([
            'slug' => 'api',
            'domain' => null,
            'nginx_config' => "server {\n    listen 8083;\n    server_name _;\n}\n",
        ]);

        $config = app(WebsiteSwitch::class)->disabledNginx($website);

        $this->assertStringContainsString('listen 8083;', $config);
        $this->assertStringContainsString('server_name _;', $config);
    }
}
