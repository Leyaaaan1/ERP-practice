<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Inventory;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * ProductController
 *
 * ERP CONCEPT: Products are the items you trade.
 * Key ERP features here:
 *   - Barcode lookup: Simulates a barcode scanner at POS or receiving dock
 *   - SKU lookup: Internal product code search
 *   - Stock level included in all product responses
 *
 * BARCODE LOOKUP (ERP Use Case):
 * In a warehouse or retail store, staff use handheld scanners.
 * They scan a barcode → the ERP instantly shows product details + stock level.
 * This endpoint simulates that exact flow.
 */
class ProductController extends Controller
{
    /**
     * GET /api/products
     * List all active products with current stock levels.
     */
    public function index(Request $request): JsonResponse
    {
        $products = Product::with('inventory')
            ->where('is_active', true)
            ->when($request->search, function ($query, $search) {
                // Allow searching by name or SKU
                $query->where('name', 'like', "%{$search}%")
                      ->orWhere('sku', 'like', "%{$search}%");
            })
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $products,
        ]);
    }

    /**
     * POST /api/products
     * Create a new product and initialise its inventory record.
     *
     * ERP CONCEPT: When you register a new product, you immediately create
     * its inventory record too (with zero stock). This ensures every product
     * is always trackable in the inventory system.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:255',
            'sku'           => 'required|string|unique:products,sku|max:100',
            'barcode'       => 'nullable|string|unique:products,barcode|max:100',
            'description'   => 'nullable|string',
            'price'         => 'required|numeric|min:0',
            'cost'          => 'nullable|numeric|min:0',
            'unit'          => 'nullable|string|max:20',
            'reorder_point' => 'nullable|integer|min:0',
            'initial_stock' => 'nullable|integer|min:0',
        ]);

        $reorderPoint  = $validated['reorder_point'] ?? 10;
        $initialStock  = $validated['initial_stock'] ?? 0;

        // Remove non-product fields before creating
        unset($validated['reorder_point'], $validated['initial_stock']);

        $product = Product::create($validated);

        // Always create a corresponding inventory record immediately
        Inventory::create([
            'product_id'    => $product->id,
            'quantity'      => $initialStock,
            'reorder_point' => $reorderPoint,
            'last_updated'  => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Product created with inventory record.',
            'data'    => $product->load('inventory'),
        ], 201);
    }

    /**
     * GET /api/products/{id}
     * Get a single product with full inventory details.
     */
    public function show(Product $product): JsonResponse
    {
        $product->load('inventory');

        return response()->json([
            'success' => true,
            'data'    => $product,
        ]);
    }

    /**
     * PUT /api/products/{id}
     * Update product details.
     */
    public function update(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'name'        => 'sometimes|required|string|max:255',
            'sku'         => 'sometimes|required|string|unique:products,sku,' . $product->id . '|max:100',
            'barcode'     => 'nullable|string|unique:products,barcode,' . $product->id . '|max:100',
            'description' => 'nullable|string',
            'price'       => 'sometimes|required|numeric|min:0',
            'cost'        => 'nullable|numeric|min:0',
            'unit'        => 'nullable|string|max:20',
            'is_active'   => 'sometimes|boolean',
        ]);

        $product->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Product updated.',
            'data'    => $product->load('inventory'),
        ]);
    }

    /**
     * GET /api/products/barcode/{barcode}
     *
     * ERP CONCEPT: BARCODE LOOKUP
     * This simulates what happens when a warehouse worker or cashier scans
     * a product barcode. The scanner sends the barcode string to the ERP,
     * and the ERP responds instantly with:
     *   - Product name, SKU, price
     *   - Current stock level
     *   - Whether it needs reordering
     *
     * Real-world use cases:
     *   - POS terminal: cashier scans item to add to sale
     *   - Receiving dock: worker scans incoming goods to match PO
     *   - Inventory count: warehouse staff scans to verify stock
     */
    public function lookupByBarcode(string $barcode): JsonResponse
    {
        $product = Product::with('inventory')
            ->where('barcode', $barcode)
            ->where('is_active', true)
            ->first();

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => "No product found with barcode: {$barcode}",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'id'            => $product->id,
                'name'          => $product->name,
                'sku'           => $product->sku,
                'barcode'       => $product->barcode,
                'price'         => $product->price,
                'cost'          => $product->cost,
                'unit'          => $product->unit,
                'current_stock' => $product->currentStock(),
                'reorder_point' => $product->inventory?->reorder_point,
                'is_low_stock'  => $product->inventory?->isLowStock(),
            ],
        ]);
    }

    /**
     * GET /api/products/sku/{sku}
     * Look up a product by its internal SKU code.
     */
    public function lookupBySku(string $sku): JsonResponse
    {
        $product = Product::with('inventory')
            ->where('sku', $sku)
            ->where('is_active', true)
            ->first();

        if (!$product) {
            return response()->json([
                'success' => false,
                'message' => "No product found with SKU: {$sku}",
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $product,
        ]);
    }
}
