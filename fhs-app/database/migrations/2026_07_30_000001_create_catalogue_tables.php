<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The catalogue: what the business sells.
 *
 * A catalogue row describes *what a thing is* — not how many there are, nor
 * what a batch cost. One row per type + brand + weight, e.g. "Jamuna 12.5kg
 * LPG cylinder", each with its own stock count.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('catalogue', function (Blueprint $table) {
            $table->id();

            $table->string('name')->nullable();

            // e.g. lpg_cylinder, rice_bag. Backed by the InventoryType enum
            // rather than a lookup table, so adding a type needs no migration.
            $table->string('type')->index();

            // Nullable: an unbranded product is possible.
            $table->foreignId('brand_id')->nullable()->constrained('brands');

            $table->decimal('weight', 6, 2)->comment('kg');

            // Gas products are bought through gas_inventory_purchases, which
            // costs the shell and the gas separately. Everything else uses
            // inventory_purchases with a single unit cost.
            $table->boolean('is_gas')->default(false);

            // true for cylinders — drives whether empty-shell tracking applies.
            // Distinct from is_gas: a returnable non-gas container is possible.
            $table->boolean('is_returnable')->default(false);

            $table->timestamps();
            // Four tables reference this row; never hard-delete.
            $table->softDeletes();

            $table->index(['type', 'brand_id']);
        });

        // There is deliberately no selling_price column: price varies per sale
        // and is typed onto each order line.
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogue');
        Schema::dropIfExists('brands');
    }
};
