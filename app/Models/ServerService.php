<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerService extends Model
{
    protected $fillable = [
        'key',
        'name',
        'kind',
        'version',
        'config',
        'unit',
        'domain',
        'settings',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'settings' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'key';
    }
}
