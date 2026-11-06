<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Website;
use App\Services\DatabaseProvisioner;
use App\Services\HostWriter;
use App\Services\WebsiteCreator;
use App\Support\DatabaseEngines;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class DatabaseEnginesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_engines_follow_what_the_installer_set_up(): void
    {
        config(['laravelify.databases' => ['mysql', 'sqlite']]);
        $this->assertSame(['mysql', 'sqlite'], DatabaseEngines::available());
        $this->assertSame('MySQL', DatabaseEngines::label('mysql'));

        config(['laravelify.databases' => ['mariadb', 'postgresql', 'mongodb', 'sqlite']]);
        $this->assertSame(['mysql', 'postgresql', 'mongodb', 'sqlite'], DatabaseEngines::available());
        $this->assertSame('MariaDB', DatabaseEngines::label('mysql'), 'MariaDB is the mysql engine.');

        config(['laravelify.databases' => ['postgresql', 'sqlite']]);
        $this->assertSame('postgresql', DatabaseEngines::default(), 'Without MySQL, the first engine the server has.');
        $this->assertFalse(DatabaseEngines::has('mysql'));

        config(['laravelify.databases' => []]);
        $this->assertSame(['sqlite'], DatabaseEngines::available(), 'SQLite needs no server.');
    }

    public function test_each_engine_writes_the_env_its_framework_reads(): void
    {
        $provisioner = app(DatabaseProvisioner::class);
        $site = fn (string $type, string $engine) => new Website([
            'type' => $type, 'slug' => 'shop', 'deploy_path' => '/var/www/shop', 'db_engine' => $engine,
            'db_name' => 'lv_shop', 'db_username' => 'lv_shop_u', 'db_password' => 'p@ss',
        ]);

        $laravelPostgres = $provisioner->envValues($site('laravel', 'postgresql'));
        $this->assertSame('pgsql', $laravelPostgres['DB_CONNECTION']);
        $this->assertSame('5432', $laravelPostgres['DB_PORT']);

        $this->assertSame('postgresql://lv_shop_u:p%40ss@127.0.0.1:5432/lv_shop', $provisioner->envValues($site('nextjs', 'postgresql'))['DATABASE_URL']);
        $this->assertSame('mysql://lv_shop_u:p%40ss@127.0.0.1:3306/lv_shop', $provisioner->envValues($site('express', 'mysql'))['DATABASE_URL']);

        $mongo = $provisioner->envValues($site('laravel', 'mongodb'));
        $this->assertSame('mongodb', $mongo['DB_CONNECTION']);
        $this->assertSame('mongodb://lv_shop_u:p%40ss@127.0.0.1:27017/lv_shop?authSource=lv_shop', $mongo['MONGODB_URI']);

        $sqlite = $provisioner->envValues($site('laravel', 'sqlite'));
        $this->assertSame('sqlite', $sqlite['DB_CONNECTION']);
        $this->assertSame('/var/www/shop/.data/database.sqlite', $sqlite['DB_DATABASE']);
        $this->assertNull($sqlite['DB_PASSWORD'], 'A login from another engine is removed.');
        $this->assertSame('file:/var/www/shop/.data/database.sqlite', $provisioner->envValues($site('nuxt', 'sqlite'))['DATABASE_URL']);
    }

    public function test_a_new_website_keeps_the_engine_it_picked_and_refuses_one_the_server_lacks(): void
    {
        $this->app->instance(HostWriter::class, Mockery::mock(HostWriter::class)->shouldIgnoreMissing(''));
        config(['laravelify.databases' => ['postgresql', 'sqlite']]);
        $input = ['name' => 'Shop', 'type' => 'laravel', 'repository' => 'https://github.com/acme/shop.git', 'provision_database' => true];

        $website = app(WebsiteCreator::class)->create(Group::factory()->create(), $input + ['db_engine' => 'postgresql'])['website'];
        $this->assertSame('postgresql', $website->db_engine);
        $this->assertStringContainsString('DB_CONNECTION=pgsql', (string) $website->env_content);

        $this->expectException(ValidationException::class);
        app(WebsiteCreator::class)->create(Group::factory()->create(), $input + ['name' => 'Blog', 'db_engine' => 'mongodb']);
    }
}
