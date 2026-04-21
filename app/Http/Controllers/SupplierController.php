<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * SupplierController
 *
 * Suppliers are to Purchase Orders what Customers are to Sales Orders.
 * Simple CRUD — the complexity lives in the PurchaseOrderController.
 */
class SupplierController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => Supplier::latest()->paginate(20),
        ]);
    }

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

        return response()->json([
            'success' => true,
            'message' => 'Supplier created.',
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

        return response()->json([
            'success' => true,
            'message' => 'Supplier updated.',
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
