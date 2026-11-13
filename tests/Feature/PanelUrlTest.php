<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\ServerAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PanelUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_panel_on_an_ip_follows_the_server_address_and_a_domain_is_kept(): void
    {
        $this->assertSame('http://95.86.158.97:8000', ServerAddress::panelUrl('http://95.86.158.97:8000'), 'nothing set: unchanged');

        Setting::putValue(ServerAddress::SETTING, '172.29.197.244');

        $this->assertSame('http://172.29.197.244:8000', ServerAddress::panelUrl('http://95.86.158.97:8000'));
        $this->assertSame('https://panel.example.com', ServerAddress::panelUrl('https://panel.example.com'));
    }
}
