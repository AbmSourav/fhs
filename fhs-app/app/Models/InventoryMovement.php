<?php

namespace App\Models;

use App\Enums\MovementReason;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only stock ledger. Current stock is the sum of its changes.
 *
 * Rows are never updated or deleted: a mistake is corrected by appending a
 * reversing row. A mutable count column would drift from reality with no way to
 * find out when or why, whereas summing an immutable log always reconciles.
 */
class InventoryMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'catalogue_id',
        'order_id',
        'gas_inventory_purchase_id',
        'inventory_purchase_id',
        'reason',
        'filled_stock_change',
        'empty_stock_change',
        'note',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'reason'              => MovementReason::class,
            'filled_stock_change' => 'integer',
            'empty_stock_change'  => 'integer',
            'occurred_at'         => 'datetime',
        ];
    }

    /**
     * A movement originates from exactly one place, or none for a manual
     * adjustment. Enforced here rather than as a database check constraint so
     * the failure is a readable exception instead of a raw driver error.
     *
     * Hooked on `saving` so it covers every write path — create(), save(),
     * mass-assignment — rather than relying on each call site to remember.
     */
    protected static function booted(): void
    {
        static::saving(function (self $movement): void {
            $movement->assertSingleSource();
        });
    }

    /**
     * @throws \LogicException if more than one source key is set.
     */
    public function assertSingleSource(): void
    {
        $sources = array_filter([
            'order_id'                  => $this->order_id,
            'gas_inventory_purchase_id' => $this->gas_inventory_purchase_id,
            'inventory_purchase_id'     => $this->inventory_purchase_id,
        ], fn ($id) => $id !== null);

        if (count($sources) > 1) {
            throw new \LogicException(sprintf(
                'An inventory movement may reference at most one source, got %d: %s.',
                count($sources),
                implode(', ', array_keys($sources)),
            ));
        }
    }

    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(Catalogue::class, 'catalogue_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function gasPurchase(): BelongsTo
    {
        return $this->belongsTo(GasInventoryPurchase::class, 'gas_inventory_purchase_id');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(InventoryPurchase::class, 'inventory_purchase_id');
    }

    public function scopeForCatalogueItem(Builder $query, int $catalogueId): Builder
    {
        return $query->where('catalogue_id', $catalogueId);
    }

    public function scopeOccurredBetween(Builder $query, mixed $from, mixed $to): Builder
    {
        return $query->whereBetween('occurred_at', [$from, $to]);
    }

    /**
     * Append a movement that reverses this one.
     *
     * The only correct way to undo stock: the original row stays, so the history
     * still shows what happened and why it was reversed.
     */
    public function reverse(string $note): self
    {
        return static::create([
            'catalogue_id' => $this->catalogue_id,
            // The same source is carried across, so a reversal can still be
            // traced to what caused it. assertSingleSource() still holds:
            // exactly one of these was set on the row being reversed.
            'order_id'                  => $this->order_id,
            'gas_inventory_purchase_id' => $this->gas_inventory_purchase_id,
            'inventory_purchase_id'     => $this->inventory_purchase_id,
            'reason'                    => MovementReason::Reversal,
            'filled_stock_change'       => -$this->filled_stock_change,
            'empty_stock_change'        => -$this->empty_stock_change,
            'note'                      => $note,
            'occurred_at'               => now(),
        ]);
    }
}
