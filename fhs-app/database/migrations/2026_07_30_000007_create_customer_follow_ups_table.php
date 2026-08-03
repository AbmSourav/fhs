<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A log of contact with customers — one row per call, never one per customer.
 *
 * Appending rather than overwriting keeps the history: how many times someone
 * has been chased, by whom, and what came of each attempt. A single
 * `last_called_at` column on `customers` would answer only the last of those,
 * and calling someone twice would erase the record of the first attempt.
 *
 * "When were they last contacted" is therefore derived — MAX(called_at) — the
 * same way stock is the sum of its movements and payment state is the sum of
 * its payments.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // Who made the call, matching recorded_by / received_by elsewhere.
            $table->foreignId('called_by')->constrained('users');

            // Plain string backed by the FollowUpOutcome enum, so adding an
            // outcome needs no migration.
            $table->string('outcome')->index();

            // What was said — 'wants delivery after Eid', 'moved house'. The
            // thing worth reading before calling back.
            $table->text('note')->nullable();

            // When the call happened. Separate from created_at, which is when
            // it was typed in: a call made this morning may be entered tonight.
            $table->timestamp('called_at')->index();

            // A promised callback. Unlike every other column here this is an
            // intention rather than a record, so it drives a to-do list.
            $table->date('call_again_on')->nullable()->index();

            $table->timestamps();

            // The common read: this customer's calls, newest first.
            $table->index(['customer_id', 'called_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_follow_ups');
    }
};
