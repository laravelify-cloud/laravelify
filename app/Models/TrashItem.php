<?php

namespace App\Models;

use Database\Factories\TrashItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Number;

/**
 * A file or folder deleted in the file manager. It is kept in storage/app/trash/{id}/{name}
 * until it is restored or deleted for good. Deleting the same path again adds another item,
 * so every deleted version can be brought back.
 */
class TrashItem extends Model
{
    /** @use HasFactory<TrashItemFactory> */
    use HasFactory;

    protected $fillable = [
        'scope',
        'original_path',
        'name',
        'is_dir',
        'size_bytes',
        'user_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_dir' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The folder this item is kept in.
     */
    public function folder(): string
    {
        return storage_path('app/trash/'.$this->id);
    }

    /**
     * The kept file or folder itself.
     */
    public function storedPath(): string
    {
        return $this->folder().DIRECTORY_SEPARATOR.$this->name;
    }

    public function sizeLabel(): string
    {
        return $this->size_bytes === null ? '—' : Number::fileSize($this->size_bytes, precision: 1);
    }

    /**
     * The folder the item was deleted from, relative to the root ("" for the root itself).
     */
    public function parentPath(): string
    {
        $parent = dirname($this->original_path);

        return $parent === '.' ? '' : $parent;
    }
}
