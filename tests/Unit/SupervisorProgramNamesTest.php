<?php

namespace Tests\Unit;

use App\Services\ConfigRenderer;
use Tests\TestCase;

class SupervisorProgramNamesTest extends TestCase
{
    public function test_programs_without_the_site_name_get_it(): void
    {
        $config = <<<'INI'
[program:shop-queue]
command=php artisan queue:work

[program:balances-listen]
command=php artisan balances:listen
stdout_logfile=/var/log/laravelify/sites/shop/balances-listen.log

[program:wallets listen!]
command=php artisan wallets:listen
INI;

        $renamed = ConfigRenderer::prefixPrograms($config, 'shop');

        $this->assertStringContainsString("[program:shop-queue]\ncommand=php artisan queue:work", $renamed);
        $this->assertStringContainsString("[program:shop-balances-listen]\ncommand=php artisan balances:listen", $renamed);
        $this->assertStringContainsString('[program:shop-wallets-listen]', $renamed);
        $this->assertStringContainsString('stdout_logfile=/var/log/laravelify/sites/shop/balances-listen.log', $renamed, 'Only section names change.');
    }

    public function test_a_group_and_its_programs_list_follow_the_new_names(): void
    {
        $config = "[program:listen]\ncommand=a\n\n[program:shop-queue]\ncommand=b\n\n[group:workers]\nprograms=listen, shop-queue\npriority=10\n";

        $renamed = ConfigRenderer::prefixPrograms($config, 'shop');

        $this->assertStringContainsString('[program:shop-listen]', $renamed);
        $this->assertStringContainsString("[group:shop-workers]\nprograms=shop-listen,shop-queue\npriority=10", $renamed);
    }

    public function test_a_new_name_never_takes_an_existing_one(): void
    {
        $renamed = ConfigRenderer::prefixPrograms("[program:shop-listen]\ncommand=a\n\n[program:listen]\ncommand=b\n", 'shop');

        $this->assertStringContainsString("[program:shop-listen]\ncommand=a", $renamed);
        $this->assertStringContainsString("[program:shop-listen-2]\ncommand=b", $renamed);
    }

    public function test_a_config_that_already_fits_is_returned_as_it_is(): void
    {
        $config = "[program:shop-queue]\r\ncommand=a\r\n\r\n[program:shop]\r\ncommand=b\r\n";

        $this->assertSame($config, ConfigRenderer::prefixPrograms($config, 'shop'));
    }

    public function test_securing_a_config_also_renames_its_programs(): void
    {
        $secured = ConfigRenderer::secureSupervisor("[program:apply-coupons-balances-listen]\ncommand=php artisan balances:listen\n", 'apply-coupons-backend-laravel', 'www-data');

        $this->assertStringContainsString('[program:apply-coupons-backend-laravel-apply-coupons-balances-listen]', $secured);
        $this->assertStringContainsString('user=www-data', $secured);
    }
}
