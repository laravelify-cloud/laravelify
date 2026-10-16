<?php

namespace App\Livewire\Concerns;

use App\Models\DatabaseChange;
use App\Services\DatabaseBrowser;
use Throwable;

/**
 * The change log of the Database tool: undo one change (only its row or object), undo anyway after
 * a warning that the row changed since, and put back a saved copy of a table.
 */
trait ManagesDatabaseChanges
{
    /** @var array{id: int, message: string}|null A change whose undo stopped because the row changed since. */
    public ?array $undoConflict = null;

    public function undoChange(DatabaseBrowser $browser, int $id, bool $force = false): void
    {
        $change = DatabaseChange::query()->findOrFail($id);
        $this->undoConflict = null;

        try {
            $browser->revert($change, $force);
            $this->toast('Undone: '.$change->summary().'.');
        } catch (Throwable $exception) {
            if (! $force && $change->isRow() && in_array($change->action, ['insert', 'update'], true) && str_contains($exception->getMessage(), 'anyway')) {
                $this->undoConflict = ['id' => $change->id, 'message' => $exception->getMessage()];

                return;
            }

            $this->toast($exception->getMessage(), 'error');
        }
    }

    public function undoAnyway(DatabaseBrowser $browser): void
    {
        if ($this->undoConflict !== null) {
            $this->undoChange($browser, $this->undoConflict['id'], true);
        }
    }

    public function restoreCopy(DatabaseBrowser $browser, int $id): void
    {
        $change = DatabaseChange::query()->findOrFail($id);

        try {
            $browser->restoreSnapshot($change);
            $this->toast('The table '.$change->table.' is back as it was before '.$change->created_at?->toDateTimeString().'.');
        } catch (Throwable $exception) {
            $this->toast($exception->getMessage(), 'error');
        }
    }
}
