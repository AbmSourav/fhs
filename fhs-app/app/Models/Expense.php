<?php

namespace App\Models;

use App\Enums\ExpenseCategory;
use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Non-stock business spending — a padlock, van fuel, wages, rent.
 *
 * Separate from purchases because a lock never becomes something you sell.
 * Expenses never touch inventory_movements, so they cannot corrupt stock, and
 * they are what turns gross profit into net profit.
 */
class Expense extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'category',
        'description',
        'amount',
        'paid_to',
        'payment_method',
        'receipt_ref',
        'spent_at',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'category'       => ExpenseCategory::class,
            'payment_method' => PaymentMethod::class,
            'amount'         => 'decimal:2',
            'spent_at'       => 'datetime',
        ];
    }

    /**
     * How long after recording an expense may still be corrected.
     *
     * Expenses feed reported profit, so the window is for fixing a typo while
     * the receipt is still in hand, not for revising settled figures. Deleting
     * stays open indefinitely: a soft delete leaves the row and its audit
     * trail, whereas an edit silently rewrites what was reported.
     */
    public const EDIT_WINDOW_HOURS = 1;

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    /** Can this expense still be corrected? */
    public function isEditable(): bool
    {
        return $this->editBlockedReason() === null;
    }

    /**
     * Why this expense cannot be corrected, or null when it can be.
     *
     * Returned rather than thrown so one wording serves both the server
     * rejection and the disabled state in the UI.
     */
    public function editBlockedReason(): ?string
    {
        if ($this->created_at->addHours(static::EDIT_WINDOW_HOURS)->isFuture()) {
            return null;
        }

        return sprintf(
            'An expense can only be corrected within %d hour of being recorded. Delete it instead.',
            static::EDIT_WINDOW_HOURS,
        );
    }

    public function scopeSpentBetween(Builder $query, mixed $from, mixed $to): Builder
    {
        return $query->whereBetween('spent_at', [$from, $to]);
    }

    public function scopeOfCategory(Builder $query, ExpenseCategory $category): Builder
    {
        return $query->where('category', $category->value);
    }
}
