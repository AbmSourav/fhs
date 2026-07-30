<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money spent running the business that is not stock — a padlock, van fuel,
 * utility bills, wages, rent.
 *
 * Kept separate from purchases because a lock never becomes something you sell.
 * Recording it as a purchase would inflate stock counts with unsellable items.
 * Expenses never touch inventory_movements, so they cannot corrupt stock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            // Plain string backed by the ExpenseCategory enum, so adding a
            // category needs no migration.
            $table->string('category')->index();
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->string('paid_to')->nullable();
            $table->string('payment_method');
            $table->string('receipt_ref')->nullable();
            $table->timestamp('spent_at')->index();
            $table->foreignId('recorded_by')->constrained('users');
            $table->timestamps();
            // Expenses affect reported profit; keep an audit trail.
            $table->softDeletes();

            $table->index(['category', 'spent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
