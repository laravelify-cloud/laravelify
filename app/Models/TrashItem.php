<?php

namespace App\Models;

use App\Services\SiteUser;
use Database\Factories\TrashItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Number;

/**
 * A file or folder deleted in the file manager. It is kept in the trash folder (SiteUser::trashRoot()), as {id}/{name}
 * until it is restored or deleted for good. Deleting the same path again adds another item,
 * so every deleted version can be brought back.
 */
class TrashItem extends Model
{
    /** @use HasFactory<TrashItemFactory> */
    use HasFactory;

    protected $fillable = [
        'scope',
        'site_slug',
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
     * The folder this item is kept in: the trash of the website's own user, or the shared trash.
     */
    public function folder(): string
    {
        $site = app(SiteUser::class);
        $owner = filled($this->site_slug) ? $site->forSlug((string) $this->site_slug) : $site->shared();

        return $owner->trashRoot().DIRECTORY_SEPARATOR.$this->id;
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
