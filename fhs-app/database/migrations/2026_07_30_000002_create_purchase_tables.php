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

            // Purchases are immutable: an edit appends a replacement row rather
            // than rewriting this one, so a correction today cannot silently
            // change last month's stock or margin. canonical_id is the first row
            // in the chain — null on that first row, since it cannot reference
            // itself before it has an id.
            $table->foreignId('canonical_id')->nullable()->constrained('gas_inventory_purchases');
            $table->foreignId('catalogue_id')->constrained('catalogue')->comment('what came back filled');

            // Whose empties were sent, and so whether this is a swap at all:
            //
            //   null                    new purchase, nothing sent back
            //   equal to catalogue_id   same-brand swap
            //   different               cross-brand swap
            //
            // A swap must not increase the shell count — it returns gas in
            // shells already owned. Otherwise ten swaps of 5 would look like 50
            // cylinders that were never bought.
            $table->foreignId('swap_catalogue_id')->nullable()->constrained('catalogue');

            $table->string('supplier')->nullable();

            $table->integer('filled_quantity')->default(0);
            $table->integer('empty_quantity')->default(0);

            // Shell and gas are costed separately because they are sold
            // separately: a swap sells gas only, an outright purchase sells
            // both, an empty-cylinder sale sells the shell alone. One blended
            // cost would overstate the cost of a swap by the whole shell price.
            $table->decimal('shell_unit_cost', 14, 2)->default(0);
            $table->decimal('gas_unit_cost', 14, 2)->default(0);

            // Anything else spent belongs in `expenses`, which is the single
            // record of spending. Transport stays here because it is part of
            // what this consignment cost to land.
            $table->decimal('transport_cost', 14, 2)->default(0)->comment('whole consignment');

            $table->string('invoice_ref')->nullable();
            $table->timestamp('purchased_at')->index();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();

            $table->index(['catalogue_id', 'purchased_at']);
            // Shell-cost averages filter on this: purchases that acquired
            // shells are those with no swap_catalogue_id.
            $table->index(['catalogue_id', 'swap_catalogue_id']);
        });

        // Plain goods: one quantity, one cost, no shells, no refills.
        Schema::create('inventory_purchases', function (Blueprint $table) {
            $table->id();
            // See gas_inventory_purchases above: edits append, never rewrite.
            $table->foreignId('canonical_id')->nullable()->constrained('inventory_purchases');
            $table->foreignId('catalogue_id')->constrained('catalogue');
            $table->string('supplier')->nullable();
            $table->integer('quantity');
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('transport_cost', 14, 2)->default(0);
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
