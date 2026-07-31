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
        'returned_catalogue_id',
        'transaction_type',
        'quantity',
        'unit_price',
        'cylinder_price',
        'unit_cost',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'transaction_type' => TransactionType::class,
            'quantity'         => 'integer',
            'unit_price'       => 'decimal:2',
            'cylinder_price'   => 'decimal:2',
            'unit_cost'        => 'decimal:2',
            'line_total'       => 'decimal:2',
        ];
    }

    /**
     * Hooked on `saving` so it covers every write path — create(), save(),
     * mass-assignment — rather than relying on each call site to remember.
     */
    protected static function booted(): void
    {
        static::saving(function (self $item): void {
            $item->assertReturnedShellIsMeaningful();
        });
    }

    /**
     * Only a transaction that takes a shell back can name a returned product.
     *
     * @throws \LogicException if set on a transaction that returns nothing.
     */
    public function assertReturnedShellIsMeaningful(): void
    {
        if ($this->returned_catalogue_id === null) {
            return;
        }

        if ($this->transaction_type->stockChangePerUnit()['empty'] <= 0) {
            throw new \LogicException(sprintf(
                'returned_catalogue_id is set on a %s line, which returns no shell.',
                $this->transaction_type->value,
            ));
        }
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(Catalogue::class, 'catalogue_id');
    }

    /**
     * The product whose shell came back, when it differs from what was sold.
     *
     * Null on the ordinary swap, where the returned shell is the same product.
     */
    public function returnedCatalogueItem(): BelongsTo
    {
        return $this->belongsTo(Catalogue::class, 'returned_catalogue_id');
    }

    /**
     * Was the shell priced separately from the gas?
     *
     * Only an outright cylinder purchase charges for both at once; a swap sells
     * gas alone and a bare shell sale has no gas to price.
     */
    public function hasPriceSplit(): bool
    {
        return $this->cylinder_price !== null && $this->transaction_type->includesShell();
    }

    /**
     * What the gas was charged at, per unit.
     *
     * unit_price is the two entered prices added together, so the gas share is
     * whatever is left once the shell is taken out.
     */
    public function gasPrice(): float
    {
        return round((float) $this->unit_price - (float) $this->cylinder_price, 2);
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
     * Stock effect of this line, keyed by catalogue id.
     *
     * A swap returns a shell (+1 empty) while consuming gas (−1 filled), which
     * is the whole reason filled and empty are tracked separately.
     *
     * Usually one entry. A customer who swaps in another brand's empty produces
     * two, because the gas and the shell belong to different products — one
     * combined entry would invent a shell of the brand sold and lose the one
     * actually handed over.
     *
     * @return array<int, array{filled: int, empty: int}>
     */
    public function stockChange(): array
    {
        $perUnit = $this->transaction_type->stockChangePerUnit();

        $filled = $perUnit['filled'] * $this->quantity;
        $empty = $perUnit['empty'] * $this->quantity;

        // Same product on both sides: one entry, both changes together.
        if ($this->returned_catalogue_id === null || $this->returned_catalogue_id === $this->catalogue_id) {
            return [
                $this->catalogue_id => ['filled' => $filled, 'empty' => $empty],
            ];
        }

        return [
            // What was sold: the gas leaves, no shell comes back to it.
            $this->catalogue_id => ['filled' => $filled, 'empty' => 0],
            // What came back: a shell of a different product arrives.
            $this->returned_catalogue_id => ['filled' => 0, 'empty' => $empty],
        ];
    }
}
