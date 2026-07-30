<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Customer extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'mobile_number',
        'name',
        'address',
        'additional_data',
    ];

    protected function casts(): array
    {
        return [
            'additional_data' => 'array',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Find a customer by mobile number, or create one.
     *
     * Two things this has to get right:
     *
     *  - A null mobile number identifies nobody, so it must never be used as a
     *    lookup key — otherwise every anonymous customer would collide with the
     *    first one.
     *  - Soft-deleted rows are searched and restored. `mobile_number` is unique
     *    including trashed rows, so inserting over a deleted customer would fail
     *    the constraint and take the sale down with it.
     *
     * Call this inside the same transaction as the order.
     */
    public static function findOrCreateForSale(?string $mobileNumber, array $attributes): self
    {
        $mobileNumber = $mobileNumber !== null && trim($mobileNumber) !== ''
            ? trim($mobileNumber)
            : null;

        if ($mobileNumber === null) {
            return static::create($attributes);
        }

        $existing = static::withTrashed()
            ->where('mobile_number', $mobileNumber)
            ->first();

        if ($existing !== null) {
            if ($existing->trashed()) {
                $existing->restore();
            }

            return $existing;
        }

        return static::create([...$attributes, 'mobile_number' => $mobileNumber]);
    }

    /**
     * Outstanding balance across all orders that actually happened.
     *
     * Payments are summed in a subquery rather than joined: joining orders to
     * payments repeats an order's total once per payment, which inflates the
     * balance whenever an order was paid in instalments.
     */
    public function outstandingBalance(): float
    {
        $billed = (float) $this->orders()
            ->where('status', '!=', 'failed')
            ->sum('total_amount');

        $paid = (float) Payment::query()
            ->whereIn('order_id', $this->orders()
                ->where('status', '!=', 'failed')
                ->select('id'))
            ->sum('amount');

        return round($billed - $paid, 2);
    }

    /**
     * Eager-load billed and paid totals as `billed_total` and `paid_total`.
     *
     * Two separate subquery sums, never a join through both — see the note on
     * outstandingBalance() above.
     */
    public function scopeWithOutstandingBalance(Builder $query): Builder
    {
        $notFailed = fn (Builder $q) => $q->where('status', '!=', 'failed');

        return $query
            ->withSum(['orders as billed_total' => $notFailed], 'total_amount')
            ->withSum(
                ['payments as paid_total' => fn (Builder $q) => $q->whereHas('order', $notFailed)],
                'amount',
            );
    }

    /** Payments reached through this customer's orders — payments have no customer_id. */
    public function payments(): HasManyThrough
    {
        return $this->hasManyThrough(Payment::class, Order::class);
    }
}
