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

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
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
