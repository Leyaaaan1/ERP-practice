<?php

namespace App\Http\Controllers;

use App\Models\SalesOrder;
use App\Services\SalesOrderService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * SalesOrderController
 *
 * ERP CONCEPT: This controller handles the Sales Flow.
 * Notice how thin this controller is — the real work happens in
 * SalesOrderService. The controller just:
 *   1. Validates the HTTP request
 *   2. Calls the service
 *   3. Returns the response
 *
 * SALES FLOW REMINDER:
 *   POST /sales-orders     → Creates order + deducts inventory
 *   POST /{id}/cancel      → Cancels order + restores inventory
 */
class SalesOrderController extends Controller
{
    public function __construct(
        private readonly SalesOrderService $salesOrderService
    ) {}

    /**
     * GET /api/sales-orders
     * List all sales orders with customer info.
     */
    public function index(Request $request): JsonResponse
    {
        $orders = SalesOrder::with('customer')
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->customer_id, fn($q, $id) => $q->where('customer_id', $id))
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $orders,
        ]);
    }

    /**
     * POST /api/sales-orders
     *
     * ERP KEY ACTION: Create a Sales Order.
     * This triggers the full sales flow:
     *   1. Validate stock availability for all items
     *   2. Create order + line items
     *   3. Deduct inventory (all inside a DB transaction)
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id'           => 'required|exists:customers,id',
            'notes'                 => 'nullable|string',
            'items'                 => 'required|array|min:1',
            'items.*.product_id'    => 'required|exists:products,id',
            'items.*.quantity'      => 'required|integer|min:1',
            'items.*.unit_price'    => 'required|numeric|min:0',
        ]);

        $order = $this->salesOrderService->createOrder($validated);

        return response()->json([
            'success'            => true,
            'message'            => 'Sales order created and inventory updated.',
            'inventory_updated'  => true,
            'data'               => $order,
        ], 201);
    }

    /**
     * GET /api/sales-orders/{id}
     * Get a single order with all line items and customer.
     */
    public function show(SalesOrder $salesOrder): JsonResponse
    {
        $salesOrder->load(['customer', 'items.product']);

        return response()->json([
            'success' => true,
            'data'    => $salesOrder,
        ]);
    }

    /**
     * POST /api/sales-orders/{id}/cancel
     *
     * ERP KEY ACTION: Cancel a Sales Order.
     * If inventory was already deducted (status = confirmed), it is RESTORED.
     * This prevents stock from disappearing when an order is cancelled.
     */
    public function cancel(SalesOrder $salesOrder): JsonResponse
    {
        $order = $this->salesOrderService->cancelOrder($salesOrder);

        return response()->json([
            'success'           => true,
            'message'           => "Order {$order->order_number} cancelled. Inventory restored.",
            'inventory_restored' => $salesOrder->hasInventoryDeducted(), // Was stock returned?
            'data'              => $order,
        ]);
    }
}
