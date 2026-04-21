<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERP CONCEPT: Purchase Order Flow
 *
 * A Purchase Order (PO) is sent to a SUPPLIER to request goods.
 * This is the INBOUND side of inventory (vs. Sales which is OUTBOUND).
 *
 * CRITICAL DIFFERENCE from Sales Orders:
 * - Sales Order → deducts inventory immediately on confirmation
 * - Purchase Order → adds inventory only when goods are RECEIVED
 *
 * WHY? Because you might order 100 items, but the supplier might ship them
 * in batches, or some might be damaged. You only add stock when you
 * physically have it in the warehouse.
 *
 * STATUS MACHINE:
 *   pending   → PO drafted but not sent
 *   ordered   → PO sent to supplier, waiting for delivery
 *   received  → Goods arrived, inventory updated
 *   cancelled → PO cancelled, no inventory change needed
 *
 * received_at: Timestamp of when the goods physically arrived.
 *   This is important for auditing and tracking supplier lead times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->onDelete('restrict');
            $table->enum('status', ['pending', 'ordered', 'received', 'cancelled'])
                ->default('pending');
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('received_at')->nullable(); // When goods arrived
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')
                ->constrained('purchase_orders')
                ->onDelete('cascade');
            $table->foreignId('product_id')
                ->constrained('products')
                ->onDelete('restrict');
            $table->integer('quantity');
            $table->decimal('unit_cost', 12, 2);    // Cost AT TIME OF PURCHASE
            $table->decimal('subtotal', 14, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
