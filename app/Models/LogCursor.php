<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogCursor extends Model
{
    protected $fillable = [
        'website_id',
        'source',
        'path',
        'inode',
        'offset',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'inode' => 'integer',
            'offset' => 'integer',
        ];
    }
}
