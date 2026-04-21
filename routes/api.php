<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\SalesOrderController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\SupplierController;

/*
|--------------------------------------------------------------------------
| ERP API Routes
|--------------------------------------------------------------------------
|
| All routes are prefixed with /api (set in bootstrap/app.php or RouteServiceProvider).
|
| ERP MODULE OVERVIEW:
|
|   /customers       ← Who buys from us
|   /suppliers       ← Who sells to us
|   /products        ← What we trade (+ barcode/SKU lookup)
|   /inventory       ← How much we have (+ low-stock alerts)
|   /sales-orders    ← Outbound: sell to customer → stock decreases
|   /purchase-orders ← Inbound: buy from supplier → stock increases on receive
|
*/

// ─── Customer Management ────────────────────────────────────────────────────
Route::apiResource('customers', CustomerController::class);

// ─── Supplier Management ────────────────────────────────────────────────────
Route::apiResource('suppliers', SupplierController::class);

// ─── Product Management ─────────────────────────────────────────────────────
// NOTE: Specific lookup routes must come BEFORE the resource routes,
// otherwise {product} will try to match 'barcode' or 'sku' as an ID.
Route::get('products/barcode/{barcode}', [ProductController::class, 'lookupByBarcode'])
    ->name('products.barcode');
Route::get('products/sku/{sku}', [ProductController::class, 'lookupBySku'])
    ->name('products.sku');

Route::apiResource('products', ProductController::class);

// ─── Inventory Management ───────────────────────────────────────────────────
Route::prefix('inventory')->name('inventory.')->group(function () {
    // View all stock levels
    Route::get('/', [InventoryController::class, 'index'])->name('index');

    // Low stock alert — products below reorder point
    // ERP USE: Generate purchase order suggestions
    Route::get('low-stock', [InventoryController::class, 'lowStock'])->name('low-stock');

    // Manual stock adjustment (write-offs, corrections, cycle counts)
    Route::post('{product_id}/adjust', [InventoryController::class, 'adjust'])->name('adjust');
});

// ─── Sales Orders (Outbound Flow) ───────────────────────────────────────────
Route::prefix('sales-orders')->name('sales-orders.')->group(function () {
    Route::get('/', [SalesOrderController::class, 'index'])->name('index');

    // KEY ACTION: Creates order + deducts inventory atomically
    Route::post('/', [SalesOrderController::class, 'store'])->name('store');

    Route::get('{salesOrder}', [SalesOrderController::class, 'show'])->name('show');

    // KEY ACTION: Cancels order + restores inventory
    Route::post('{salesOrder}/cancel', [SalesOrderController::class, 'cancel'])->name('cancel');
});

// ─── Purchase Orders (Inbound Flow) ─────────────────────────────────────────
Route::prefix('purchase-orders')->name('purchase-orders.')->group(function () {
    Route::get('/', [PurchaseOrderController::class, 'index'])->name('index');

    // Creates PO (NO inventory change yet)
    Route::post('/', [PurchaseOrderController::class, 'store'])->name('store');

    Route::get('{purchaseOrder}', [PurchaseOrderController::class, 'show'])->name('show');

    // KEY ACTION: Marks as received + adds to inventory
    Route::post('{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->name('receive');
});

// ─── Health Check ────────────────────────────────────────────────────────────
Route::get('health', fn() => response()->json([
    'status'  => 'ok',
    'app'     => 'ERP Learning Project',
    'version' => '1.0.0',
    'time'    => now()->toIso8601String(),
]));
