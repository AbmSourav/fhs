<?php

namespace App\Models;

use App\Enums\FollowUpOutcome;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt at contacting a customer.
 *
 * Append-only in spirit: a second call is a second row, so the record of the
 * first survives. Nothing here changes stock, money, or the customer.
 */
class CustomerFollowUp extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'called_by',
        'outcome',
        'note',
        'called_at',
        'call_again_on',
    ];

    protected function casts(): array
    {
        return [
            'outcome'       => FollowUpOutcome::class,
            'called_at'     => 'datetime',
            'call_again_on' => 'date',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function calledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'called_by');
    }

    /**
     * Calls that settled something, excluding the ones nobody picked up.
     *
     * An unanswered call leaves the customer exactly as they were, so it does
     * not count as having followed them up.
     */
    public function scopeConclusive(Builder $query): Builder
    {
        return $query->where('outcome', '!=', FollowUpOutcome::NoAnswer->value);
    }

    /** Callbacks promised for on or before a given day. */
    public function scopeDueBy(Builder $query, mixed $date): Builder
    {
        return $query->whereNotNull('call_again_on')
            ->whereDate('call_again_on', '<=', $date);
    }
}
