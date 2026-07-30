<?php

namespace App\Models;

use App\Enums\TransactionType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One product within a sale.
 *
 * `transaction_type` lives here rather than on the order because a single sale
 * can mix a cylinder swap with a plain rice-bag sale — there is no one answer
 * per order. It decides both the stock movement and the cost basis.
 *
 * `unit_price` and `unit_cost` are frozen at sale time. Joining to current
 * values instead would let a later price change silently rewrite the value of
 * every past order.
 */
class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'catalogue_id',
        'transaction_type',
        'quantity',
        'unit_price',
        'unit_cost',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'transaction_type' => TransactionType::class,
            'quantity' => 'integer',
            'unit_price' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(Catalogue::class, 'catalogue_id');
    }

    /** Margin on this line, using the cost frozen at sale time. */
    public function margin(): float
    {
        return round(
            (float) $this->line_total - ((float) $this->unit_cost * $this->quantity),
            2,
        );
    }

    /**
     * Stock effect of this line, as ['filled' => int, 'empty' => int].
     *
     * A swap returns a shell (+1 empty) while consuming gas (−1 filled), which
     * is the whole reason filled and empty are tracked separately.
     */
    public function stockChange(): array
    {
        $perUnit = $this->transaction_type->stockChangePerUnit();

        return [
            'filled' => $perUnit['filled'] * $this->quantity,
            'empty' => $perUnit['empty'] * $this->quantity,
        ];
    }
}
