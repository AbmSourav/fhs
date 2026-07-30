<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only stock ledger. Current stock is the sum of its changes.
 *
 * Never updated or deleted — mistakes are corrected by appending a reversing
 * row. A mutable count column would drift from reality with no way to find out
 * when or why; summing an immutable log always reconciles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalogue_id')->constrained('catalogue');

            // Exactly one source, or none for a manual adjustment. Purchases
            // live in two tables, hence two nullable keys.
            $table->foreignId('order_id')->nullable()->constrained('orders');
            $table->foreignId('gas_inventory_purchase_id')->nullable()->constrained('gas_inventory_purchases');
            $table->foreignId('inventory_purchase_id')->nullable()->constrained('inventory_purchases');

            $table->string('reason');

            // Signed. Negative means stock left. A swap is -1 filled / +1 empty:
            // gas goes out, the shell comes back.
            $table->integer('filled_stock_change')->default(0);
            $table->integer('empty_stock_change')->default(0);

            $table->string('note')->nullable();
            $table->timestamp('occurred_at')->index();
            $table->timestamps();

            // The stock sum filters by catalogue and often by date range. This
            // is the fastest-growing, most-summed table in the schema.
            $table->index(['catalogue_id', 'occurred_at']);
        });

        // Two rules are enforced in application code rather than here:
        //
        //  - At most one of order_id / gas_inventory_purchase_id /
        //    inventory_purchase_id may be set. See InventoryMovement::assertSingleSource().
        //  - Negative stock is allowed: the business sells first and reconciles
        //    counts later, and seeding backdated orders would otherwise fail.
        //    Reports flag negative stock instead.
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
