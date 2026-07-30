<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            // Usually the identifier, but not always given — a cash customer at
            // the door may not provide one. Postgres allows multiple NULLs under
            // a unique index, so unidentified customers coexist fine while two
            // customers with the same number are still rejected.
            $table->string('mobile_number')->nullable()->unique();
            $table->string('name');
            $table->text('address')->nullable();
            $table->json('additional_data')->nullable();
            $table->timestamps();
            // Keeps order history intact when a customer is removed.
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
