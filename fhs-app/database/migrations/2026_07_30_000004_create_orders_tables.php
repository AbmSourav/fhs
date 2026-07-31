<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Orders, their line items, and payments.
 *
 * An order holds facts about the transaction; anything about a specific product
 * belongs on the line item, because one sale can mix a cylinder swap with a
 * plain rice-bag sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // Required: a customer row is created on the fly during the sale if
            // they are not already on file.
            $table->foreignId('customer_id')->constrained('customers');
            $table->foreignId('user_id')->constrained('users')->comment('staff who recorded it');

            // Deliberate denormalization of SUM(order_items.line_total).
            // Must be recalculated in the same transaction whenever line items
            // change. Revenue reporting reads order_items, never this column.
            $table->decimal('total_amount', 14, 2)->default(0);

            // Fulfilment state, NOT payment state — the two are independent.
            // Payment state is derived from the payments table.
            $table->string('status')->default('complete')->index();

            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            // Orders affect stock and revenue; never hard-delete.
            $table->softDeletes();

            $table->index(['customer_id', 'occurred_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('catalogue_id')->constrained('catalogue')->comment('what was sold');

            // The shell handed back, when it is not the same product that was
            // sold — a customer may swap in another brand's or size's empty.
            // Null means the returned shell matches catalogue_id, the ordinary
            // case. Set, a swap moves stock for two different catalogue items:
            // gas leaves one, an empty shell arrives on the other.
            $table->foreignId('returned_catalogue_id')->nullable()->constrained('catalogue');

            // Per line, not per order: one sale can mix a swap with a plain sale.
            // Also decides which cost basis applies.
            $table->string('transaction_type');

            $table->integer('quantity');
            // Both frozen at sale time. Joining to current values would let a
            // later price or cost change silently rewrite historical orders.
            $table->decimal('unit_price', 14, 2);

            // How much of unit_price was the shell rather than the gas, when a
            // customer buys a cylinder outright and is charged for both at
            // once. Gas is the remainder. Null on every other kind of sale,
            // where no shell is being sold.
            $table->decimal('cylinder_price', 14, 2)->nullable();
            $table->decimal('unit_cost', 14, 2)->default(0)->comment('weighted average at sale time');
            $table->decimal('line_total', 14, 2);
            $table->timestamps();

            $table->index('catalogue_id');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            // No customer_id: the customer is reachable via the order, and
            // storing it again would allow a payment to contradict its order.
            $table->foreignId('order_id')->constrained('orders')->index();
            $table->decimal('amount', 14, 2);
            $table->string('method');
            $table->foreignId('received_by')->constrained('users');
            // When the money changed hands, as opposed to created_at, which is
            // when it was entered. These differ for a backdated payment.
            $table->timestamp('paid_at')->index();
            $table->timestamps();
        });

        // There is no paid_amount on orders: payment state and customer balance
        // are both derived from the payments table.
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
