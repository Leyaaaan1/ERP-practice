<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERP CONCEPT: Inventory is a SEPARATE table from Products.
 * This is important! The product table stores WHAT the item is (name, SKU, price).
 * The inventory table stores HOW MANY you have (quantity, reorder point).
 *
 * WHY SEPARATE?
 * - Products can exist with zero stock (they're just not available to sell)
 * - One product could have stock in multiple warehouses (easy to extend)
 * - Inventory levels change constantly; product info changes rarely
 *
 * reorder_point: When quantity drops to this level, it's time to reorder.
 *   In a full ERP, this triggers automatic purchase order suggestions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')
                ->unique()  // One inventory record per product (single warehouse)
                ->constrained('products')
                ->onDelete('cascade');
            $table->integer('quantity')->default(0);        // Current stock on hand
            $table->integer('reorder_point')->default(10);  // Alert threshold
            $table->timestamp('last_updated')->nullable();  // When stock last changed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory');
    }
};
