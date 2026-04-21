<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ERP CONCEPT: Sales Order Flow
 *
 * A Sales Order (SO) is a CONFIRMED intent to sell goods to a customer.
 * This is different from a Quote (not yet confirmed) or an Invoice (billing document).
 *
 * ORDER STATUS MACHINE:
 *   pending    → Order created but not yet processed
 *   confirmed  → Stock reserved, inventory deducted
 *   shipped    → Physical goods have left the warehouse
 *   delivered  → Customer has received the goods
 *   cancelled  → Order cancelled, stock returned to inventory
 *
 * order_number: Human-readable reference (e.g., SO-20250115-001)
 *   In a real ERP, this is used in all communication with the customer.
 *
 * total_amount: Calculated from order items. In this project it's stored
 *   for quick retrieval. In a real ERP, this would tie to an invoice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique();
            $table->foreignId('customer_id')
                ->constrained('customers')
                ->onDelete('restrict'); // Don't delete customer if they have orders
            $table->enum('status', ['pending', 'confirmed', 'shipped', 'delivered', 'cancelled'])
                ->default('pending');
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        /**
         * ERP CONCEPT: Order Line Items
         *
         * Most ERP documents follow a HEADER + LINES pattern:
         * - Header (sales_orders): Who, when, status, total
         * - Lines (sales_order_items): What products, how many, at what price
         *
         * unit_price is stored on the line item because prices can change
         * over time. You need to know what price was in effect AT THE TIME
         * of the sale — not the current product price.
         */
        Schema::create('sales_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sales_order_id')
                ->constrained('sales_orders')
                ->onDelete('cascade'); // Delete items when order is deleted
            $table->foreignId('product_id')
                ->constrained('products')
                ->onDelete('restrict');
            $table->integer('quantity');
            $table->decimal('unit_price', 12, 2);   // Price AT TIME OF SALE (not current)
            $table->decimal('subtotal', 14, 2);      // quantity * unit_price
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_order_items');
        Schema::dropIfExists('sales_orders');
    }
};
