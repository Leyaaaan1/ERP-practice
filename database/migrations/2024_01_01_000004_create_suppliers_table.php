<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERP CONCEPT: Suppliers are the mirror of Customers in the purchase flow.
 * - Customers buy FROM you  → Sales Orders → inventory goes DOWN
 * - Suppliers sell TO you   → Purchase Orders → inventory goes UP
 *
 * In a full ERP, suppliers also have payment terms, lead times, and
 * preferred currency. We keep it simple here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->string('contact_person')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
