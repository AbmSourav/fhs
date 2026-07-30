<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchases are split into two tables because gas is bought differently.
 *
 * A cylinder is a reusable shell plus the gas inside it, the two have separate
 * costs, and refills are their own workflow. Plain goods have none of that.
 * The two never overlap, so no single invoice spans both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gas_inventory_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalogue_id')->constrained('catalogue');
            $table->string('supplier')->nullable();

            // false for a gas refill. A refill returns gas in shells already
            // owned, so it must not increase the shell count — otherwise ten
            // refills of 5 look like 50 cylinders that were never bought.
            $table->boolean('new_stock')->default(true);

            $table->integer('filled_quantity')->default(0);
            $table->integer('empty_quantity')->default(0);

            // Shell and gas are costed separately because they are sold
            // separately: a swap sells gas only, an outright purchase sells
            // both, an empty-cylinder sale sells the shell alone. One blended
            // cost would overstate the cost of a swap by the whole shell price.
            $table->decimal('shell_unit_cost', 14, 2)->default(0);
            $table->decimal('gas_unit_cost', 14, 2)->default(0);

            $table->decimal('transport_cost', 14, 2)->default(0)->comment('whole consignment');
            $table->decimal('other_cost', 14, 2)->default(0);

            $table->string('invoice_ref')->nullable();
            $table->timestamp('purchased_at')->index();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();

            $table->index(['catalogue_id', 'purchased_at']);
            // Shell-cost averages filter on this.
            $table->index(['catalogue_id', 'new_stock']);
        });

        // Plain goods: one quantity, one cost, no shells, no refills.
        Schema::create('inventory_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalogue_id')->constrained('catalogue');
            $table->string('supplier')->nullable();
            $table->integer('quantity');
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('transport_cost', 14, 2)->default(0);
            $table->decimal('other_cost', 14, 2)->default(0);
            $table->string('invoice_ref')->nullable();
            $table->timestamp('purchased_at')->index();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();

            $table->index(['catalogue_id', 'purchased_at']);
        });

        // total_cost is deliberately not stored: it is fully determined by the
        // quantity and cost columns, so storing it would be a second source of
        // truth that can drift.
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_purchases');
        Schema::dropIfExists('gas_inventory_purchases');
    }
};
