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
        'supplier',
        'new_stock',
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
            'new_stock'       => 'boolean',
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

    /** Purchases that acquired shells — excludes gas refills. */
    public function scopeNewStock(Builder $query): Builder
    {
        return $query->where('new_stock', true);
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

    /** Shells acquired by this purchase. A refill acquires none. */
    public function shellsAcquired(): int
    {
        return $this->new_stock
            ? $this->filled_quantity + $this->empty_quantity
            : 0;
    }
}
