<?php

namespace App\Services;

use App\Enums\InventoryType;
use App\Models\Catalogue;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CatalogueService
{
    public function __construct(
        private readonly CostBasis $costBasis,
    ) {}

    /**
     * Validation rules for creating a catalogue item.
     *
     * `is_gas` and `is_returnable` are absent deliberately — they are properties
     * of the *kind* of product and come from the enum. Accepting them from a
     * client would allow a cylinder recorded as non-returnable, silently
     * breaking empty-shell tracking.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Optional: displayName() falls back to brand + type + weight.
            'name'     => ['nullable', 'string', 'max:255'],
            'type'     => ['required', Rule::enum(InventoryType::class)],
            'brand_id' => ['required', 'integer', 'exists:brands,id'],
            'weight'   => ['required', 'numeric', 'min:0.01', 'max:9999.99'],
        ];
    }

    public function messages(): array
    {
        return [
            'brand_id.exists' => 'The selected brand no longer exists.',
        ];
    }

    /**
     * Create a catalogue item from validated input.
     *
     * @throws ValidationException if the item already exists.
     */
    public function create(array $data): Catalogue
    {
        $type = $data['type'] instanceof InventoryType
            ? $data['type']
            : InventoryType::from((string) $data['type']);

        $name = trim((string) ($data['name'] ?? ''));

        $attributes = [
            // Store null rather than an empty string, so displayName() falls
            // back to the generated label.
            'name'     => $name !== '' ? $name : null,
            'type'     => $type,
            'brand_id' => $data['brand_id'] ?: null,
            'weight'   => (float) $data['weight'],
            // Derived from the type, never taken from the request.
            'is_gas'        => $type->isGas(),
            'is_returnable' => $type->isReturnable(),
        ];

        $this->assertNotDuplicate($attributes);

        return Catalogue::create($attributes);
    }

    /**
     * Catalogue listing with derived stock levels.
     *
     * withStock() adds the sums as subqueries, so this stays one query rather
     * than one per row.
     */
    public function listWithStock(): Collection
    {
        $items = Catalogue::query()
            ->with('brand')
            ->withStock()
            ->orderBy('type')
            ->orderBy('weight')
            ->get();

        // Averaged for the whole catalogue in three queries rather than three
        // per item.
        $averages = $this->costBasis->forItems($items);

        return $items
            ->map(fn (Catalogue $item) => [
                'id'            => $item->id,
                'name'          => $item->name,
                'type'          => $item->type->value,
                'type_label'    => $item->type->label(),
                'brand_name'    => $item->brand?->name,
                'weight'        => (float) $item->weight,
                'is_gas'        => $item->is_gas,
                'is_returnable' => $item->is_returnable,
                'display_name'  => $item->displayName(),
                'filled_stock'  => $item->filledStock(),
                'empty_stock'   => $item->emptyStock(),
                // Negative stock is allowed — the business sells first and
                // reconciles later — so it is surfaced rather than prevented.
                'has_negative_stock' => $item->hasNegativeStock(),
                // The weighted average across every purchase of this product,
                // which is what a sale of it is costed at. Gas and shell are
                // averaged apart because they are sold apart: a swap consumes
                // gas without consuming a cylinder.
                'average_gas_cost' => $item->is_gas
                    ? $averages[$item->id]['gas']
                    : $averages[$item->id]['plain'],
                'average_shell_cost' => $item->is_gas ? $averages[$item->id]['shell'] : 0.0,
            ]);
    }

    public function recentItems(int $limit = 10): Collection
    {
        return Catalogue::query()
            ->with('brand')
            ->latest()
            ->limit($limit)
            ->get()
            ->map(fn (Catalogue $item) => [
                'id'           => $item->id,
                'display_name' => $item->displayName(),
            ]);
    }

    /** Product types for the picker, mirroring the enum. */
    public function typeOptions(): array
    {
        return array_map(
            fn (InventoryType $type) => [
                'value'         => $type->value,
                'label'         => $type->label(),
                'is_gas'        => $type->isGas(),
                'is_returnable' => $type->isReturnable(),
            ],
            InventoryType::cases(),
        );
    }

    /**
     * There is no unique constraint on (type, brand_id, weight) by design, but a
     * duplicate would split one product's stock across two records — so reject
     * it here rather than creating it silently.
     *
     * @throws ValidationException
     */
    private function assertNotDuplicate(array $attributes): void
    {
        $exists = Catalogue::query()
            ->where('type', $attributes['type'])
            ->where('brand_id', $attributes['brand_id'])
            ->where('weight', $attributes['weight'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'weight' => 'This item already exists in the catalogue. Adding it again would split its stock across two records.',
            ]);
        }
    }
}
