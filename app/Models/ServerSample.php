<?php

namespace App\Models;

use Database\Factories\ServerSampleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServerSample extends Model
{
    /** @use HasFactory<ServerSampleFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'at',
        'cpu',
        'memory',
        'swap',
        'load1',
        'disk',
        'net_in',
        'net_out',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'at' => 'datetime',
            'cpu' => 'float',
            'memory' => 'float',
            'swap' => 'float',
            'load1' => 'float',
            'disk' => 'float',
            'net_in' => 'integer',
            'net_out' => 'integer',
        ];
    }
}
