<?php

namespace Database\Factories;

use App\Models\DatabaseConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DatabaseConnection>
 */
class DatabaseConnectionFactory extends Factory
{
    public function definition(): array
    {
        $slug = 'erp-'.fake()->unique()->numberBetween(1, 99999);

        return [
            'name' => 'ERP',
            'slug' => $slug,
            'driver' => 'sqlsrv',
            'host' => '192.168.1.20',
            'port' => 1433,
            'database' => 'erp',
            'username' => 'sa',
            'password' => 'secret',
            'encrypt' => false,
            'trust_certificate' => true,
        ];
    }
}
