<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\OdooSalesService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;

class CustomerController extends Controller
{
    public function __construct(
        private readonly OdooSalesService $odooSalesService
    ) {}

    public function index(): JsonResponse
    {
        $customers = Customer::latest()->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $customers,
        ]);
    }

    /**
     * POST /api/customers
     * ?push_to_odoo=true to sync to Odoo
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'    => 'required|string|max:255',
            'email'   => 'nullable|email|unique:customers,email',
            'phone'   => 'nullable|string|max:20',
            'address' => 'nullable|string',
        ]);

        $customer = Customer::create($validated);

        $pushToOdoo = $request->boolean('push_to_odoo', false);

        if ($pushToOdoo) {
            try {
                $this->odooSalesService->syncCustomerToOdoo($customer);
                Log::info("Customer synced to Odoo", [
                    'customer_id' => $customer->id,
                    'odoo_id' => $customer->odoo_id,
                ]);
            } catch (\Exception $e) {
                Log::error("Failed to sync customer to Odoo", [
                    'customer_id' => $customer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Customer created successfully.',
            'odoo_synced' => $pushToOdoo && !empty($customer->odoo_id),
            'data'    => $customer,
        ], 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $customer->load(['salesOrders' => function ($query) {
            $query->latest()->limit(10);
        }]);

        return response()->json([
            'success' => true,
            'data'    => $customer,
        ]);
    }

    /**
     * PUT /api/customers/{id}
     * ?push_to_odoo=true to sync changes to Odoo
     */
    public function update(Request $request, Customer $customer): JsonResponse
    {
        $validated = $request->validate([
            'name'    => 'sometimes|required|string|max:255',
            'email'   => 'nullable|email|unique:customers,email,' . $customer->id,
            'phone'   => 'nullable|string|max:20',
            'address' => 'nullable|string',
        ]);

        $customer->update($validated);

        $pushToOdoo = $request->boolean('push_to_odoo', false);

        if ($pushToOdoo) {
            try {
                $this->odooSalesService->syncCustomerToOdoo($customer);
                Log::info("Customer update synced to Odoo", [
                    'customer_id' => $customer->id,
                ]);
            } catch (\Exception $e) {
                Log::error("Failed to sync customer update to Odoo", [
                    'customer_id' => $customer->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Customer updated successfully.',
            'odoo_synced' => $pushToOdoo && !empty($customer->odoo_id),
            'data'    => $customer,
        ]);
    }

    public function destroy(Customer $customer): JsonResponse
    {
        if ($customer->salesOrders()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete customer with existing sales orders.',
            ], 422);
        }

        $customer->delete();

        return response()->json([
            'success' => true,
            'message' => 'Customer deleted.',
        ]);
    }
}