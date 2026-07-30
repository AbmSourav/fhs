<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money received against an order. All payment is offline — the application
 * never processes payments.
 *
 * There is deliberately no customer_id: the customer is reachable through the
 * order, and storing it again would allow a payment to name a different
 * customer than its own order.
 */
class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'amount',
        'method',
        'received_by',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'method' => PaymentMethod::class,
            'paid_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** Staff member who took the money. */
    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    /** The paying customer, via the order. */
    public function customer(): ?Customer
    {
        return $this->order?->customer;
    }
}
