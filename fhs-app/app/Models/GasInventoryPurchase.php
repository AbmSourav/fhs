<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A cylinder purchase — shell and gas costed separately.
 *
 * They are separate because they are sold separately: a swap sells gas only, an
 * outright purchase sells both, an empty-cylinder sale sells the shell alone.
 * One blended cost would overstate the cost of a swap by the whole shell price.
 */
class GasInventoryPurchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'catalogue_id',
        'swap_catalogue_id',
        'supplier',
        'filled_quantity',
        'empty_quantity',
        'shell_unit_cost',
        'gas_unit_cost',
        'transport_cost',
        'other_cost',
        'invoice_ref',
        'purchased_at',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'filled_quantity' => 'integer',
            'empty_quantity'  => 'integer',
            'shell_unit_cost' => 'decimal:2',
            'gas_unit_cost'   => 'decimal:2',
            'transport_cost'  => 'decimal:2',
            'other_cost'      => 'decimal:2',
            'purchased_at'    => 'datetime',
        ];
    }

    public function catalogueItem(): BelongsTo
    {
        return $this->belongsTo(Catalogue::class, 'catalogue_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /** The product whose empties were sent. Null on a new purchase. */
    public function swapCatalogueItem(): BelongsTo
    {
        return $this->belongsTo(Catalogue::class, 'swap_catalogue_id');
    }

    /**
     * Gas returned in shells already owned, rather than cylinders bought.
     *
     * A swap acquires no shells, so it must not count toward shell totals.
     */
    public function isSwap(): bool
    {
        return $this->swap_catalogue_id !== null;
    }

    /**
     * Were the empties sent a different product from what came back?
     *
     * The shells stay owned either way, so the overall total is unaffected —
     * but each brand's individual count moves.
     */
    public function isCrossBrandSwap(): bool
    {
        return $this->isSwap() && $this->swap_catalogue_id !== $this->catalogue_id;
    }

    /** Purchases that acquired shells — excludes swaps. */
    public function scopeNewStock(Builder $query): Builder
    {
        return $query->whereNull('swap_catalogue_id');
    }

    /**
     * Not stored: fully determined by the quantity and cost columns, so a stored
     * copy would be a second source of truth that can drift.
     */
    public function totalCost(): float
    {
        return (float) $this->shell_unit_cost * ($this->filled_quantity + $this->empty_quantity)
            + (float) $this->gas_unit_cost * $this->filled_quantity
            + (float) $this->transport_cost
            + (float) $this->other_cost;
    }

    /** Shells acquired by this purchase. A swap acquires none. */
    public function shellsAcquired(): int
    {
        return $this->isSwap()
            ? 0
            : $this->filled_quantity + $this->empty_quantity;
    }
}
