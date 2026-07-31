<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A record that is corrected by appending a replacement, never by rewriting.
 *
 * Both purchase tables work this way: a purchase is the evidence behind a stock
 * level and a cost basis, so editing one in place would let a typo fixed today
 * silently change last month's figures.
 *
 * `canonical_id` is the id of the first row in the chain, shared by every later
 * version. It is null on that first row — a row cannot reference itself before
 * it has an id — so the chain key is `COALESCE(canonical_id, id)`.
 *
 * @property int|null $canonical_id
 * @property int $id
 * @property Carbon $created_at
 * @property static|null $canonical
 *
 * @mixin Model
 */
trait HasEditHistory
{
    /** The chain this row belongs to, whichever version it is. */
    public function canonicalId(): int
    {
        return $this->canonical_id ?? $this->id;
    }

    /** Was this row written as a correction of an earlier one? */
    public function isEdit(): bool
    {
        return $this->canonical_id !== null;
    }

    /** The original, or null when this row is itself the original. */
    public function canonical(): BelongsTo
    {
        return $this->belongsTo(static::class, 'canonical_id');
    }

    /** Later corrections of this row. Only ever populated on the original. */
    public function revisions(): HasMany
    {
        return $this->hasMany(static::class, 'canonical_id');
    }

    /**
     * How long after the original was recorded edits remain possible.
     *
     * A purchase feeds stock levels and cost averages that later work builds
     * on, so corrections are for catching entry mistakes while they are fresh,
     * not for rewriting settled history.
     */
    public const EDIT_WINDOW_HOURS = 48;

    /** How many corrections one purchase may accumulate. */
    public const MAX_EDITS = 2;

    /**
     * Corrections made so far. Counted across the whole chain, so it does not
     * reset when editing an edit.
     */
    public function editCount(): int
    {
        return $this->newQuery()
            ->where('canonical_id', $this->canonicalId())
            ->count();
    }

    /**
     * When the edit window closes.
     *
     * Measured from the original's creation, not this version's — otherwise
     * each edit would extend the deadline indefinitely.
     */
    public function editableUntil(): Carbon
    {
        // Falls back to this row when the original is missing, which can only
        // happen if it was hard-deleted — better a tight window than a crash.
        $original = $this->isEdit() ? ($this->canonical ?? $this) : $this;

        return $original->created_at->addHours(static::EDIT_WINDOW_HOURS);
    }

    public function editWindowHasClosed(): bool
    {
        return $this->editableUntil()->isPast();
    }

    public function hasReachedEditLimit(): bool
    {
        return $this->editCount() >= static::MAX_EDITS;
    }

    /** Can this purchase still be corrected? */
    public function isEditable(): bool
    {
        return ! $this->editWindowHasClosed() && ! $this->hasReachedEditLimit();
    }

    /**
     * Why this purchase cannot be edited, or null when it can be.
     *
     * Returned rather than thrown so the same wording serves both the API
     * rejection and the disabled state in the UI.
     */
    public function editBlockedReason(): ?string
    {
        if ($this->editWindowHasClosed()) {
            return sprintf(
                'Purchases can only be corrected within %d hours of being recorded.',
                static::EDIT_WINDOW_HOURS,
            );
        }

        if ($this->hasReachedEditLimit()) {
            return sprintf(
                'This purchase has already been corrected %d times, which is the limit.',
                static::MAX_EDITS,
            );
        }

        return null;
    }

    /**
     * Every version of this purchase, oldest first.
     *
     * Keyed off the original, so it returns the same chain whichever version it
     * is called on.
     */
    public function history(): Collection
    {
        $originalId = $this->canonicalId();

        return $this->newQuery()
            // Grouped: without the closure the orWhere would escape any
            // constraint added by a caller.
            ->where(function (Builder $query) use ($originalId) {
                $query->where('id', $originalId)
                    ->orWhere('canonical_id', $originalId);
            })
            ->orderBy('id')
            ->get();
    }

    /**
     * Only the newest version of each purchase.
     *
     * A row is current when no later row belongs to the same chain. Comparing
     * on the chain key rather than on id alone covers both cases: an original
     * that has since been edited, and an edit that has itself been edited.
     */
    public function scopeCurrent(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query->whereNotExists(function ($sub) use ($table) {
            $sub->selectRaw('1')
                ->from($table, 'newer')
                ->whereColumn('newer.id', '>', "{$table}.id")
                ->whereRaw(
                    "COALESCE(newer.canonical_id, newer.id) = COALESCE({$table}.canonical_id, {$table}.id)"
                );
        });
    }
}
