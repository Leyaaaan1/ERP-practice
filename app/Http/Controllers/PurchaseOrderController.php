<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\PurchaseOrderService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrderService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $orders = PurchaseOrder::with('supplier')
            ->when($request->status, fn($q, $s) => $q->where('status', $s))
            ->when($request->supplier_id, fn($q, $id) => $q->where('supplier_id', $id))
            ->latest()
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data'    => $orders,
        ]);
    }

    /**
     * POST /api/purchase-orders
     * ?push_to_odoo=true to also push to Odoo
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id'           => 'required|exists:suppliers,id',
            'notes'                 => 'nullable|string',
            'items'                 => 'required|array|min:1',
            'items.*.product_id'    => 'required|exists:products,id',
            'items.*.quantity'      => 'required|integer|min:1',
            'items.*.unit_cost'     => 'required|numeric|min:0',
        ]);

        $pushToOdoo = $request->boolean('push_to_odoo', false);

        $order = $pushToOdoo
            ? $this->purchaseOrderService->createOrderAndPushToOdoo($validated)
            : $this->purchaseOrderService->createOrder($validated);

        return response()->json([
            'success' => true,
            'message' => 'Purchase order created. Inventory will be updated when goods are received.',
            'odoo_synced' => $pushToOdoo && !empty($order->odoo_id),
            'data'    => $order,
        ], 201);
    }

    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->load(['supplier', 'items.product']);

        return response()->json([
            'success' => true,
            'data'    => $purchaseOrder,
        ]);
    }

    public function receive(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $order = $this->purchaseOrderService->receiveOrder($purchaseOrder);

        return response()->json([
            'success'            => true,
            'message'            => "Purchase order {$order->order_number} received. Inventory updated.",
            'inventory_updated'  => true,
            'odoo_synced'        => !empty($order->odoo_id),
            'data'               => $order,
        ]);
    }
}