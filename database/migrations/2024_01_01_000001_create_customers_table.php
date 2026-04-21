<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERP CONCEPT: The Customer table is the foundation of the Sales Flow.
 * In a real ERP, customers are linked to quotes, sales orders, invoices,
 * and payment records. Here we keep it simple: one table, key fields only.
 *
 * NOTE: Per the project constraints, we only have ONE customers table.
 * No accounts, no contacts sub-table, no billing/shipping split.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique()->nullable();
            $table->string('phone', 20)->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
