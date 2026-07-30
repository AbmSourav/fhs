<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One sale.
 *
 * Holds facts about the transaction — who, when, how much, fulfilment state.
 * Anything about a specific product belongs on OrderItem, because one sale can
 * mix a cylinder swap with a plain rice-bag sale.
 */
class Order extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'customer_id',
        'user_id',
        'total_amount',
        'status',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'status'       => OrderStatus::class,
            'occurred_at'  => 'datetime',
        ];
    }

    protected $attributes = [
        'status' => 'complete',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** Staff member who recorded the sale. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /** Orders that actually happened — excludes failed. */
    public function scopeHappened(Builder $query): Builder
    {
        return $query->where('status', '!=', OrderStatus::Failed->value);
    }

    /**
     * Recalculate total_amount from the line items.
     *
     * total_amount is a deliberate denormalization of SUM(line_total), so this
     * must run in the same transaction as any change to the items. Keeping it in
     * one method means there is a single place that can get it wrong.
     */
    public function recalculateTotal(): void
    {
        $this->update([
            'total_amount' => $this->items()->sum('line_total'),
        ]);
    }

    /** Sum actually received. Payment state is never stored. */
    public function paidAmount(): float
    {
        return (float) $this->payments()->sum('amount');
    }

    public function dueAmount(): float
    {
        return round((float) $this->total_amount - $this->paidAmount(), 2);
    }

    /**
     * Payment state, derived. Independent of `status`, which is fulfilment:
     * an order can be complete but unpaid, or failed after a deposit.
     */
    public function paymentState(): string
    {
        $paid = $this->paidAmount();

        return match (true) {
            $paid >= (float) $this->total_amount => 'paid',
            $paid > 0                            => 'partial',
            default                              => 'due',
        };
    }

    public function isFullyPaid(): bool
    {
        return $this->paymentState() === 'paid';
    }
}
