<?php

namespace Database\Factories;

use App\Models\Group;
use App\Models\Website;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Website>
 */
class WebsiteFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);
        $slug = Str::slug($name).'-'.fake()->unique()->numerify('###');

        return [
            'group_id' => Group::factory(),
            'name' => $name,
            'slug' => $slug,
            'type' => 'laravel',
            'domain' => $slug.'.example.com',
            'repository' => 'https://github.com/example/'.$slug.'.git',
            'branch' => 'main',
            'php_version' => '8.2',
            'node_version' => null,
            'port' => null,
            'reverb_port' => null,
            'enable_reverb' => false,
            'provision_database' => false,
            'deploy_path' => '/var/www/'.$slug,
            'env_content' => "APP_ENV=production\n",
            'nginx_config' => "server { server_name {$slug}.example.com; }\n",
            'process_config' => "[program:{$slug}-queue]\n",
            'webhook_secret' => 'whsec_'.Str::random(24),
        ];
    }

    public function nextjs(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'nextjs',
            'php_version' => null,
            'node_version' => '22',
            'port' => 3000,
        ]);
    }
}
