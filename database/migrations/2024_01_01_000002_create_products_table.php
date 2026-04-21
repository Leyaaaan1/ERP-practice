<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERP CONCEPT: Products are the core of any ERP system.
 * Every product has a SKU (Stock Keeping Unit) — a unique internal code
 * used for tracking. The barcode is what scanners read in a warehouse or
 * point-of-sale scenario.
 *
 * KEY FIELDS:
 * - sku:       Your internal code (e.g., "MOUSE-WL-001")
 * - barcode:   External code (EAN-13, UPC, etc.) for scanners
 * - price:     Default selling price
 * - unit:      How it's measured (piece, kg, box, liter, etc.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sku')->unique();       // Internal tracking code
            $table->string('barcode')->unique()->nullable();  // Scanner-readable code
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2);       // Default selling price
            $table->decimal('cost', 12, 2)->default(0); // Default purchase cost
            $table->string('unit', 20)->default('piece'); // piece, kg, box, liter
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
