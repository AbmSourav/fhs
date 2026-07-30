<?php

namespace App\Models;

use App\Enums\InventoryType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * What the business sells — one row per type + brand + weight.
 *
 * Describes *what a thing is*, never how many there are. Stock is derived by
 * summing inventory_movements; see the withStock() scope.
 */
class Catalogue extends Model
{
    use HasFactory, SoftDeletes;

    /** Laravel would pluralise this to "catalogues". */
    protected $table = 'catalogue';

    protected $fillable = [
        'type',
        'brand_id',
        'weight',
        'is_gas',
        'is_returnable',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'type' => InventoryType::class,
            'weight' => 'decimal:2',
            'is_gas' => 'boolean',
            'is_returnable' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    public function gasPurchases(): HasMany
    {
        return $this->hasMany(GasInventoryPurchase::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(InventoryPurchase::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /**
     * Attach derived stock levels as `filled_stock` and `empty_stock`.
     *
     * Uses subquery sums so this stays one query rather than N+1. Both come back
     * null when an item has no movements yet, hence the accessors below.
     */
    public function scopeWithStock(Builder $query): Builder
    {
        return $query
            ->withSum('movements as filled_stock', 'filled_stock_change')
            ->withSum('movements as empty_stock', 'empty_stock_change');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeGas(Builder $query): Builder
    {
        return $query->where('is_gas', true);
    }

    /** Filled units in stock. Requires withStock(). */
    public function filledStock(): int
    {
        return (int) ($this->filled_stock ?? 0);
    }

    /** Empty shells held. Requires withStock(). */
    public function emptyStock(): int
    {
        return (int) ($this->empty_stock ?? 0);
    }

    /**
     * Negative stock is allowed — the business sells first and reconciles
     * counts later — so reports flag it rather than the database refusing it.
     */
    public function hasNegativeStock(): bool
    {
        return $this->filledStock() < 0 || $this->emptyStock() < 0;
    }

    /**
     * Shells owned but not physically held, because a customer bought a
     * cylinder outright and kept it.
     *
     * Only `new_stock` purchases add shells: a refill returns gas in shells
     * already owned, so counting it would inflate the total.
     */
    public function shellsOutWithCustomers(): int
    {
        if (! $this->is_returnable) {
            return 0;
        }

        $owned = (int) $this->gasPurchases()
            ->where('new_stock', true)
            ->selectRaw('COALESCE(SUM(filled_quantity + empty_quantity), 0) as aggregate')
            ->value('aggregate');

        return $owned - $this->filledStock() - $this->emptyStock();
    }

    public function displayName(): string
    {
        return trim(sprintf(
            '%s %s %skg',
            $this->brand?->name ?? '',
            $this->type->label(),
            rtrim(rtrim((string) $this->weight, '0'), '.'),
        ));
    }
}
