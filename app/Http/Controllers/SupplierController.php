<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use App\Services\OdooService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class SupplierController extends Controller
{
    public function __construct(
        private readonly OdooService $odooService
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => Supplier::latest()->paginate(20),
        ]);
    }

    /**
     * POST /api/suppliers
     * ?push_to_odoo=true to sync to Odoo
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:255',
            'email'          => 'nullable|email|unique:suppliers,email',
            'phone'          => 'nullable|string|max:20',
            'address'        => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
        ]);

        $supplier = Supplier::create($validated);

        $pushToOdoo = $request->boolean('push_to_odoo', false);

        if ($pushToOdoo) {
            try {
                $odooId = $this->odooService->create('res.partner', [
                    'name' => $supplier->name,
                    'email' => $supplier->email,
                    'phone' => $supplier->phone,
                    'street' => $supplier->address,
                    'supplier_rank' => 1,
                    'is_company' => false,
                ]);
                $supplier->update(['odoo_id' => $odooId]);
                Log::info("Supplier synced to Odoo", [
                    'supplier_id' => $supplier->id,
                    'odoo_id' => $odooId,
                ]);
            } catch (\Exception $e) {
                Log::error("Failed to sync supplier to Odoo", [
                    'supplier_id' => $supplier->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Supplier created.',
            'odoo_synced' => $pushToOdoo && !empty($supplier->odoo_id),
            'data'    => $supplier,
        ], 201);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        $supplier->load(['purchaseOrders' => fn($q) => $q->latest()->limit(10)]);

        return response()->json([
            'success' => true,
            'data'    => $supplier,
        ]);
    }

    /**
     * PUT /api/suppliers/{id}
     * ?push_to_odoo=true to sync changes to Odoo
     */
    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        $validated = $request->validate([
            'name'           => 'sometimes|required|string|max:255',
            'email'          => 'nullable|email|unique:suppliers,email,' . $supplier->id,
            'phone'          => 'nullable|string|max:20',
            'address'        => 'nullable|string',
            'contact_person' => 'nullable|string|max:255',
        ]);

        $supplier->update($validated);

        $pushToOdoo = $request->boolean('push_to_odoo', false);

        if ($pushToOdoo) {
            try {
                if ($supplier->odoo_id) {
                    $this->odooService->write('res.partner', [$supplier->odoo_id], [
                        'name' => $supplier->name,
                        'email' => $supplier->email,
                        'phone' => $supplier->phone,
                        'street' => $supplier->address,
                    ]);
                } else {
                    $odooId = $this->odooService->create('res.partner', [
                        'name' => $supplier->name,
                        'email' => $supplier->email,
                        'phone' => $supplier->phone,
                        'street' => $supplier->address,
                        'supplier_rank' => 1,
                        'is_company' => false,
                    ]);
                    $supplier->update(['odoo_id' => $odooId]);
                }
                Log::info("Supplier update synced to Odoo", [
                    'supplier_id' => $supplier->id,
                ]);
            } catch (\Exception $e) {
                Log::error("Failed to sync supplier update to Odoo", [
                    'supplier_id' => $supplier->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Supplier updated.',
            'odoo_synced' => $pushToOdoo && !empty($supplier->odoo_id),
            'data'    => $supplier,
        ]);
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        if ($supplier->purchaseOrders()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete supplier with existing purchase orders.',
            ], 422);
        }

        $supplier->delete();

        return response()->json(['success' => true, 'message' => 'Supplier deleted.']);
    }
}