<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\PurchaseOrderService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

/**
 * PurchaseOrderController
 *
 * ERP CONCEPT: This controller handles the Purchase Flow.
 *
 * PURCHASE FLOW REMINDER:
 *   POST /purchase-orders          → Create PO (NO inventory change yet)
 *   POST /purchase-orders/{id}/receive → Receive goods (inventory INCREASES)
 *
 * The "receive" action is the KEY event — it's what adds stock to your warehouse.
 */
class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrderService
    ) {}

    /**
     * GET /api/purchase-orders
     * List all purchase orders.
     */
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
     * Create a new purchase order.
     * NOTE: Inventory is NOT updated here. Only updated on receive.
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

        $order = $this->purchaseOrderService->createOrder($validated);

        return response()->json([
            'success' => true,
            'message' => 'Purchase order created. Inventory will be updated when goods are received.',
            'data'    => $order,
        ], 201);
    }

    /**
     * GET /api/purchase-orders/{id}
     * Get a single PO with supplier and line items.
     */
    public function show(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $purchaseOrder->load(['supplier', 'items.product']);

        return response()->json([
            'success' => true,
            'data'    => $purchaseOrder,
        ]);
    }

    /**
     * POST /api/purchase-orders/{id}/receive
     *
     * ERP KEY ACTION: Receive the goods from a supplier.
     *
     * This simulates the physical arrival of goods at your warehouse:
     *   1. Mark the PO as "received"
     *   2. Record the receipt timestamp
     *   3. Increase inventory for every item on the PO
     *
     * After this call, the products are available for sale.
     */
    public function receive(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $order = $this->purchaseOrderService->receiveOrder($purchaseOrder);

        return response()->json([
            'success'            => true,
            'message'            => "Purchase order {$order->order_number} received. Inventory updated.",
            'inventory_updated'  => true,
            'data'               => $order,
        ]);
    }
}
