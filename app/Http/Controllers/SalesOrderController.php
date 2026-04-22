<?php

namespace App\Http\Controllers;

use App\Models\SalesOrder;
use App\Services\SalesOrderService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class SalesOrderController extends Controller
{
    public function __construct(
        private readonly SalesOrderService $salesOrderService
    ) {}

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
     * ?push_to_odoo=true to also push to Odoo
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

        $pushToOdoo = $request->boolean('push_to_odoo', false);

        $order = $pushToOdoo
            ? $this->salesOrderService->createOrderAndPushToOdoo($validated)
            : $this->salesOrderService->createOrder($validated);

        return response()->json([
            'success'            => true,
            'message'            => 'Sales order created and inventory updated.',
            'inventory_updated'  => true,
            'odoo_synced'        => $pushToOdoo && !empty($order->odoo_id),
            'data'               => $order,
        ], 201);
    }

    public function show(SalesOrder $salesOrder): JsonResponse
    {
        $salesOrder->load(['customer', 'items.product']);

        return response()->json([
            'success' => true,
            'data'    => $salesOrder,
        ]);
    }

    public function cancel(SalesOrder $salesOrder): JsonResponse
    {
        $order = $this->salesOrderService->cancelOrder($salesOrder);

        return response()->json([
            'success'           => true,
            'message'           => "Order {$order->order_number} cancelled. Inventory restored.",
            'inventory_restored' => $salesOrder->hasInventoryDeducted(),
            'odoo_synced'       => !empty($order->odoo_id),
            'data'              => $order,
        ]);
    }
}