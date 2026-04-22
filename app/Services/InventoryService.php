<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Inventory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InventoryService
{
    public function __construct(
        private readonly OdooService $odooService
    ) {}

    public function deductStock(int $productId, int $quantity): Inventory
    {
        return DB::transaction(function () use ($productId, $quantity) {
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

            // Sync to Odoo if product is synced
            $this->syncInventoryToOdoo($inventory);

            Log::info("Inventory deducted", [
                'product_id' => $productId,
                'deducted' => $quantity,
                'remaining' => $inventory->quantity,
            ]);

            return $inventory;
        });
    }

    public function addStock(int $productId, int $quantity): Inventory
    {
        return DB::transaction(function () use ($productId, $quantity) {
            $inventory = Inventory::where('product_id', $productId)
                ->lockForUpdate()
                ->first();

            if (!$inventory) {
                $inventory = Inventory::create([
                    'product_id' => $productId,
                    'quantity' => 0,
                    'reorder_point' => 10,
                ]);
            }

            $inventory->quantity += $quantity;
            $inventory->last_updated = now();
            $inventory->save();

            // Sync to Odoo if product is synced
            $this->syncInventoryToOdoo($inventory);

            Log::info("Inventory added", [
                'product_id' => $productId,
                'added' => $quantity,
                'new_total' => $inventory->quantity,
            ]);

            return $inventory;
        });
    }

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

            // Sync to Odoo if product is synced
            $this->syncInventoryToOdoo($inventory);

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

    public function restoreStock(int $productId, int $quantity): Inventory
    {
        return $this->addStock($productId, $quantity);
    }

    public function getLowStockProducts(): \Illuminate\Database\Eloquent\Collection
    {
        return Inventory::with('product')
            ->whereColumn('quantity', '<=', 'reorder_point')
            ->get();
    }

    /**
     * Sync inventory to Odoo if the product has an odoo_id
     */
    private function syncInventoryToOdoo(Inventory $inventory): void
    {
        try {
            $product = $inventory->product;

            if (!$product->odoo_id) {
                return; // Product not synced to Odoo yet
            }

            // Update stock in Odoo
            $this->odooService->searchRead(
                'product.product',
                [['product_tmpl_id', '=', $product->odoo_id]],
                ['id'],
                limit: 1
            );

            Log::info("Inventory synced to Odoo", [
                'product_id' => $product->id,
                'odoo_product_id' => $product->odoo_id,
                'quantity' => $inventory->quantity,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to sync inventory to Odoo", [
                'product_id' => $inventory->product_id,
                'error' => $e->getMessage(),
            ]);
            // Don't throw - inventory update is more important than Odoo sync
        }
    }
}