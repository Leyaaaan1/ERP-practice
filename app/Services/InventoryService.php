<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Inventory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * InventoryService
 *
 * ERP CONCEPT: This service is the GATEKEEPER for all inventory changes.
 * No other part of the application should directly update the inventory
 * quantity — everything goes through this service.
 *
 * Why centralize this?
 * 1. Easier to audit (log all changes)
 * 2. Consistent validation (never go below zero)
 * 3. One place to add future features (reservations, multi-warehouse, etc.)
 *
 * DATABASE TRANSACTIONS:
 * All inventory changes use DB::transaction() to ensure atomicity.
 * If you're updating 10 products and the 7th fails, all 10 are rolled back.
 * This prevents "partial updates" that would corrupt your stock levels.
 */
class InventoryService
{
    /**
     * Deduct stock from inventory (used in Sales Flow).
     *
     * This is called when a Sales Order is confirmed.
     * Uses a database LOCK (lockForUpdate) to prevent race conditions
     * where two simultaneous orders try to sell the last item.
     *
     * @param int $productId
     * @param int $quantity  Amount to deduct (must be positive)
     * @throws \Exception if insufficient stock
     */
    public function deductStock(int $productId, int $quantity): Inventory
    {
        return DB::transaction(function () use ($productId, $quantity) {
            // lockForUpdate() prevents other queries from reading this row
            // until our transaction completes. This prevents overselling.
            $inventory = Inventory::where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if (!$inventory) {
                throw new \Exception("No inventory record found for product ID {$productId}");
            }

            if ($inventory->quantity < $quantity) {
                $product = Product::find($productId);
                throw new \Exception(
                    "Insufficient stock for '{$product->name}'. " .
                    "Requested: {$quantity}, Available: {$inventory->quantity}"
                );
            }

            $inventory->quantity -= $quantity;
            $inventory->last_updated = now();
            $inventory->save();

            Log::info("Inventory deducted", [
                'product_id' => $productId,
                'deducted' => $quantity,
                'remaining' => $inventory->quantity,
            ]);

            return $inventory;
        });
    }

    /**
     * Add stock to inventory (used in Purchase Flow).
     *
     * This is called when a Purchase Order is received.
     * Unlike deducting, adding stock never fails due to quantity issues.
     *
     * @param int $productId
     * @param int $quantity  Amount to add (must be positive)
     */
    public function addStock(int $productId, int $quantity): Inventory
    {
        return DB::transaction(function () use ($productId, $quantity) {
            $inventory = Inventory::where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if (!$inventory) {
                // Auto-create inventory record if it doesn't exist
                // This handles the edge case of products added without seeding
                $inventory = Inventory::create([
                    'product_id' => $productId,
                    'quantity' => 0,
                    'reorder_point' => 10,
                ]);
            }

            $inventory->quantity += $quantity;
            $inventory->last_updated = now();
            $inventory->save();

            Log::info("Inventory added", [
                'product_id' => $productId,
                'added' => $quantity,
                'new_total' => $inventory->quantity,
            ]);

            return $inventory;
        });
    }

    /**
     * Manual stock adjustment (corrections, write-offs, cycle counts).
     *
     * ERP CONCEPT: In real warehouses, you periodically do a "cycle count"
     * where you physically count items and correct the system. This is also
     * used for write-offs (damaged goods, theft, expiry).
     *
     * quantity_change can be POSITIVE (found extra stock) or NEGATIVE (write-off).
     *
     * @param int $productId
     * @param int $quantityChange  Positive to add, negative to remove
     * @param string $reason       Required audit trail explanation
     * @throws \Exception if adjustment would make stock negative
     */
    public function adjustStock(int $productId, int $quantityChange, string $reason): Inventory
    {
        return DB::transaction(function () use ($productId, $quantityChange, $reason) {
            $inventory = Inventory::where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if (!$inventory) {
                throw new \Exception("No inventory record found for product ID {$productId}");
            }

            $newQuantity = $inventory->quantity + $quantityChange;

            if ($newQuantity < 0) {
                throw new \Exception(
                    "Adjustment would result in negative stock. " .
                    "Current: {$inventory->quantity}, Change: {$quantityChange}"
                );
            }

            $oldQuantity = $inventory->quantity;
            $inventory->quantity = $newQuantity;
            $inventory->last_updated = now();
            $inventory->save();

            // In a production ERP, you'd write this to an inventory_log table
            Log::info("Manual inventory adjustment", [
                'product_id' => $productId,
                'old_quantity' => $oldQuantity,
                'change' => $quantityChange,
                'new_quantity' => $newQuantity,
                'reason' => $reason,
            ]);

            return $inventory;
        });
    }

    /**
     * Restore stock (used when a confirmed Sales Order is cancelled).
     *
     * ERP CONCEPT: When a confirmed order is cancelled, the reserved
     * stock must be returned to available inventory. This is the "undo"
     * of deductStock().
     */
    public function restoreStock(int $productId, int $quantity): Inventory
    {
        return $this->addStock($productId, $quantity);
    }

    /**
     * Get all products below their reorder point.
     * Used to generate purchase order suggestions.
     */
    public function getLowStockProducts(): \Illuminate\Database\Eloquent\Collection
    {
        return Inventory::with('product')
            ->whereColumn('quantity', '<=', 'reorder_point')
            ->get();
    }
}
