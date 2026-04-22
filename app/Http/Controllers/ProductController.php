<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\Inventory;
use App\Services\OdooSalesService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class ProductController extends Controller
{
    public function __construct(
        private readonly OdooSalesService $odooSalesService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $products = Product::with('inventory')
            ->where('is_active', true)
            ->when($request->search, function ($query, $search) {
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
     * ?push_to_odoo=true to sync to Odoo
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

        unset($validated['reorder_point'], $validated['initial_stock']);

        $product = Product::create($validated);

        Inventory::create([
            'product_id'    => $product->id,
            'quantity'      => $initialStock,
            'reorder_point' => $reorderPoint,
            'last_updated'  => now(),
        ]);

        $pushToOdoo = $request->boolean('push_to_odoo', false);


        if ($pushToOdoo) {
            try {
                $odooId = $this->odooSalesService->syncProductToOdoo($product);
                $product->refresh(); // Refresh to get the updated odoo_id
                Log::info("Product synced to Odoo", [
                    'product_id' => $product->id,
                    'odoo_id' => $odooId,
                ]);
            } catch (\Exception $e) {
                Log::error("Failed to sync product to Odoo", [
                    'product_id' => $product->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Product created with inventory record.',
            'odoo_synced' => $pushToOdoo && !empty($product->odoo_id),
            'data'    => $product->load('inventory'),
        ], 201);
    }

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
     * ?push_to_odoo=true to sync changes to Odoo
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

        $pushToOdoo = $request->boolean('push_to_odoo', false);

        if ($pushToOdoo) {
            try {
                $this->odooSalesService->syncProductToOdoo($product);
                Log::info("Product update synced to Odoo", [
                    'product_id' => $product->id,
                ]);
            } catch (\Exception $e) {
                Log::error("Failed to sync product update to Odoo", [
                    'product_id' => $product->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Product updated.',
            'odoo_synced' => $pushToOdoo && !empty($product->odoo_id),
            'data'    => $product->load('inventory'),
        ]);
    }

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
                'odoo_synced'   => !empty($product->odoo_id),
            ],
        ]);
    }

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