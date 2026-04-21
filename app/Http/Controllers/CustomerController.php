<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * CustomerController
 *
 * ERP CONCEPT: Standard CRUD for customers.
 * Customers are the starting point of every Sales Order.
 *
 * CONTROLLER RESPONSIBILITY:
 * Controllers should be thin — they only handle HTTP concerns:
 *   1. Parse/validate the incoming request
 *   2. Call the appropriate model or service
 *   3. Return a formatted JSON response
 *
 * Business logic belongs in Services, not here.
 */
class CustomerController extends Controller
{
    /**
     * GET /api/customers
     * List all customers, newest first.
     */
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
     * Create a new customer.
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

        return response()->json([
            'success' => true,
            'message' => 'Customer created successfully.',
            'data'    => $customer,
        ], 201);
    }

    /**
     * GET /api/customers/{id}
     * Get a single customer with their order history.
     */
    public function show(Customer $customer): JsonResponse
    {
        // Eager load recent sales orders for context
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
     * Update customer details.
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

        return response()->json([
            'success' => true,
            'message' => 'Customer updated successfully.',
            'data'    => $customer,
        ]);
    }

    /**
     * DELETE /api/customers/{id}
     * Delete a customer (only if no orders exist).
     */
    public function destroy(Customer $customer): JsonResponse
    {
        // ERP RULE: Never delete a customer who has placed orders.
        // You need their record for historical reporting and auditing.
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
