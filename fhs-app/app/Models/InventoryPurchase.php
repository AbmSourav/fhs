<?php

namespace App\Models;

use App\Models\Concerns\HasEditHistory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A purchase of plain goods — rice and the like.
 *
 * One quantity, one cost. No shell/gas split and no refills; those belong to
 * GasInventoryPurchase.
 */
class InventoryPurchase extends Model
{
    use HasEditHistory, HasFactory;

    protected $fillable = [
        'canonical_id',
        'catalogue_id',
        'supplier',
        'quantity',
        'unit_cost',
        'transport_cost',
        'other_cost',
        'invoice_ref',
        'purchased_at',
        'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity'       => 'integer',
            'unit_cost'      => 'decimal:2',
            'transport_cost' => 'decimal:2',
            'other_cost'     => 'decimal:2',
            'purchased_at'   => 'datetime',
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

    /** Derived rather than stored, to avoid a second source of truth. */
    public function totalCost(): float
    {
        return (float) $this->unit_cost * $this->quantity
            + (float) $this->transport_cost
            + (float) $this->other_cost;
    }
}
