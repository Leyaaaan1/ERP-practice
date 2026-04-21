<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;

/**
 * PurchaseOrderService
 *
 * ERP CONCEPT: This service manages the entire Purchase Flow.
 * It is the INBOUND counterpart to the SalesOrderService.
 *
 * THE PURCHASE FLOW (step by step):
 *   1. Create Purchase Order with supplier_id and items[]
 *      → No inventory change yet. You've only REQUESTED goods.
 *   2. Receive the Purchase Order (POST /purchase-orders/{id}/receive)
 *      → THIS is when inventory increases. Goods are physically in the warehouse.
 *
 * WHY TWO STEPS?
 * In real ERP systems, you can't add stock until goods arrive.
 * The supplier might ship late, partially, or with wrong items.
 * Separating "order" and "receive" mirrors real warehouse operations.
 *
 * CONTRAST WITH SALES:
 * Sales Order: stock deducted at CREATION (goods are promised to customer)
 * Purchase Order: stock added at RECEIPT (goods are confirmed in warehouse)
 */
class PurchaseOrderService
{
    public function __construct(
        private readonly InventoryService $inventoryService
    ) {}

    /**
     * Create a new purchase order (does NOT update inventory).
     *
     * @param array $data {
     *   supplier_id: int,
     *   notes: string|null,
     *   items: array of {product_id, quantity, unit_cost}
     * }
     */
    public function createOrder(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data) {

            // Calculate total cost from all line items
            $totalCost = collect($data['items'])->sum(function ($item) {
                return $item['quantity'] * $item['unit_cost'];
            });

            // Create PO header — starts as 'pending' (not yet sent to supplier)
            $order = PurchaseOrder::create([
                'order_number' => $this->generateOrderNumber(),
                'supplier_id' => $data['supplier_id'],
                'status' => PurchaseOrder::STATUS_PENDING,
                'total_cost' => $totalCost,
                'notes' => $data['notes'] ?? null,
            ]);

            // Create line items (no inventory change here)
            foreach ($data['items'] as $itemData) {
                PurchaseOrderItem::create([
                    'purchase_order_id' => $order->id,
                    'product_id' => $itemData['product_id'],
                    'quantity' => $itemData['quantity'],
                    'unit_cost' => $itemData['unit_cost'],
                    'subtotal' => $itemData['quantity'] * $itemData['unit_cost'],
                ]);
            }

            return $order->load(['supplier', 'items.product']);
        });
    }

    /**
     * Receive a purchase order — THIS is when inventory is updated.
     *
     * ERP CONCEPT: "Receiving" is a warehouse term for physically accepting
     * and counting goods that have arrived from a supplier. Only after
     * receiving are goods considered part of available inventory.
     *
     * @throws \Exception if order is not in a receivable state
     */
    public function receiveOrder(PurchaseOrder $order): PurchaseOrder
    {
        if (!$order->isReceivable()) {
            throw new \Exception(
                "Purchase Order {$order->order_number} cannot be received. " .
                "Current status: {$order->status}. " .
                "Only 'pending' or 'ordered' POs can be received."
            );
        }

        return DB::transaction(function () use ($order) {

            // Load items with product details
            $order->load('items.product');

            // ERP KEY ACTION: Add stock for every item in the PO
            // This simulates goods arriving at the warehouse and being
            // counted and stocked onto shelves.
            foreach ($order->items as $item) {
                $this->inventoryService->addStock(
                    $item->product_id,
                    $item->quantity
                );
            }

            // Mark the PO as received and record when it arrived
            $order->status = PurchaseOrder::STATUS_RECEIVED;
            $order->received_at = now();
            $order->save();

            return $order->fresh(['supplier', 'items.product']);
        });
    }

    /**
     * Generate a unique, human-readable purchase order number.
     * Format: PO-YYYYMMDD-NNN
     * Example: PO-20250115-003
     */
    private function generateOrderNumber(): string
    {
        $date = now()->format('Ymd');
        $prefix = "PO-{$date}-";

        $lastOrder = PurchaseOrder::where('order_number', 'like', "{$prefix}%")
            ->orderBy('order_number', 'desc')
            ->first();

        if ($lastOrder) {
            $lastNumber = (int) substr($lastOrder->order_number, -3);
            $nextNumber = str_pad($lastNumber + 1, 3, '0', STR_PAD_LEFT);
        } else {
            $nextNumber = '001';
        }

        return $prefix . $nextNumber;
    }
}
