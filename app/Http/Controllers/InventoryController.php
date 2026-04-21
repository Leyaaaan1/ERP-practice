<?php

namespace App\Http\Controllers;

use App\Models\Inventory;
use App\Services\InventoryService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * InventoryController
 *
 * ERP CONCEPT: The inventory module shows you the "current state of the
 * warehouse." It lets you:
 *   - View stock levels for all products
 *   - See which products need reordering (low stock alert)
 *   - Make manual adjustments (write-offs, corrections, cycle count results)
 *
 * In a real ERP, this would also include:
 *   - Multiple warehouse locations
 *   - Inventory reservation (stock reserved for confirmed orders)
 *   - Movement history (audit trail of every change)
 *   - Lot/batch/serial tracking
 */
class InventoryController extends Controller
{
    public function __construct(
        private readonly InventoryService $inventoryService
    ) {}

    /**
     * GET /api/inventory
     * View all stock levels with product details.
     */
    public function index(): JsonResponse
    {
        $inventory = Inventory::with('product')
            ->join('products', 'inventory.product_id', '=', 'products.id')
            ->where('products.is_active', true)
            ->orderBy('products.name')
            ->select('inventory.*')
            ->get();

        // Build a clean, readable response
        $data = $inventory->map(function ($item) {
            return [
                'product_id'    => $item->product_id,
                'product_name'  => $item->product->name,
                'sku'           => $item->product->sku,
                'quantity'      => $item->quantity,
                'reorder_point' => $item->reorder_point,
                'is_low_stock'  => $item->isLowStock(),
                'unit'          => $item->product->unit,
                'last_updated'  => $item->last_updated,
            ];
        });

        return response()->json([
            'success'       => true,
            'total_products' => $data->count(),
            'data'          => $data,
        ]);
    }

    /**
     * GET /api/inventory/low-stock
     *
     * ERP CONCEPT: LOW STOCK ALERT
     * This is one of the most valuable ERP features. It tells you which
     * products have dropped below their reorder point, so you can create
     * purchase orders BEFORE you run out of stock.
     *
     * In a full ERP, this list automatically generates Purchase Order
     * suggestions with recommended quantities.
     */
    public function lowStock(): JsonResponse
    {
        $lowStockItems = $this->inventoryService->getLowStockProducts();

        $data = $lowStockItems->map(function ($item) {
            return [
                'product_id'    => $item->product_id,
                'product_name'  => $item->product->name,
                'sku'           => $item->product->sku,
                'current_stock' => $item->quantity,
                'reorder_point' => $item->reorder_point,
                'shortfall'     => $item->shortfall(),   // How many units below threshold
                'unit'          => $item->product->unit,
            ];
        });

        return response()->json([
            'success'      => true,
            'alert_count'  => $data->count(),
            'message'      => $data->count() > 0
                ? "{$data->count()} product(s) need restocking."
                : "All products are adequately stocked.",
            'data'         => $data,
        ]);
    }

    /**
     * POST /api/inventory/{product_id}/adjust
     *
     * ERP CONCEPT: MANUAL STOCK ADJUSTMENT
     * Used for:
     *   - Cycle count corrections (physical count differs from system count)
     *   - Damaged goods write-off (e.g., -5 for broken items)
     *   - Found stock (e.g., +3 for items found in wrong location)
     *   - Expiry removal
     *
     * quantity_change: positive to add, negative to remove.
     * reason: required for audit trail.
     */
    public function adjust(Request $request, int $productId): JsonResponse
    {
        $validated = $request->validate([
            'quantity_change' => 'required|integer|not_in:0',
            'reason'          => 'required|string|min:5|max:500',
        ]);

        $inventory = $this->inventoryService->adjustStock(
            $productId,
            $validated['quantity_change'],
            $validated['reason']
        );

        $direction = $validated['quantity_change'] > 0 ? 'increased' : 'decreased';
        $absChange = abs($validated['quantity_change']);

        return response()->json([
            'success' => true,
            'message' => "Stock {$direction} by {$absChange}. New quantity: {$inventory->quantity}.",
            'data'    => [
                'product_id'     => $inventory->product_id,
                'new_quantity'   => $inventory->quantity,
                'quantity_change' => $validated['quantity_change'],
                'reason'         => $validated['reason'],
                'adjusted_at'    => $inventory->last_updated,
            ],
        ]);
    }
}
