<?php

namespace App\Services;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PurchaseOrderService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly OdooService $odooService
    ) {}

    public function createOrder(array $data): PurchaseOrder
    {
        return DB::transaction(function () use ($data) {

            $totalCost = collect($data['items'])->sum(function ($item) {
                return $item['quantity'] * $item['unit_cost'];
            });

            $order = PurchaseOrder::create([
                'order_number' => $this->generateOrderNumber(),
                'supplier_id' => $data['supplier_id'],
                'status' => PurchaseOrder::STATUS_PENDING,
                'total_cost' => $totalCost,
                'notes' => $data['notes'] ?? null,
            ]);

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
     * Create PO and push to Odoo
     */
    public function createOrderAndPushToOdoo(array $data): PurchaseOrder
    {
        $order = $this->createOrder($data);

        try {
            $this->pushPurchaseOrderToOdoo($order);
            Log::info("Purchase order pushed to Odoo", [
                'purchase_order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to push purchase order to Odoo", [
                'purchase_order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $order;
    }

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

            $order->load('items.product');

            foreach ($order->items as $item) {
                $this->inventoryService->addStock(
                    $item->product_id,
                    $item->quantity
                );
            }

            $order->status = PurchaseOrder::STATUS_RECEIVED;
            $order->received_at = now();
            $order->save();

            // Sync receipt to Odoo if PO exists there
            try {
                if ($order->odoo_id) {
                    $this->odooService->execute(
                        'purchase.order',
                        'button_confirm',
                        [[$order->odoo_id]]
                    );
                    Log::info("Purchase order receipt synced to Odoo", [
                        'purchase_order_id' => $order->id,
                        'odoo_po_id' => $order->odoo_id,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error("Failed to sync receipt to Odoo", [
                    'purchase_order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $order->fresh(['supplier', 'items.product']);
        });
    }

    /**
     * Push a Purchase Order to Odoo
     */
    private function pushPurchaseOrderToOdoo(PurchaseOrder $order): int
    {
        $order->load(['supplier', 'items.product']);

        // Sync supplier first
        $supplierService = app('App\Services\OdooService'); // Get from container
        $odooSupplierId = $this->syncSupplierToOdoo($order->supplier);

        // Build PO lines
        $orderLines = [];
        foreach ($order->items as $item) {
            $odooProductId = $this->getOdooProductVariantId($item->product);

            $orderLines[] = [
                0, 0,
                [
                    'product_id' => $odooProductId,
                    'product_qty' => $item->quantity,
                    'price_unit' => (float) $item->unit_cost,
                    'name' => $item->product->name,
                ]
            ];
        }

        // Create PO in Odoo
        $odooPoId = $this->odooService->create('purchase.order', [
            'partner_id' => $odooSupplierId,
            'order_line' => $orderLines,
            'notes' => $order->notes ?? '',
        ]);

        // Save Odoo ID
        $order->update(['odoo_id' => $odooPoId]);

        return $odooPoId;
    }

    private function syncSupplierToOdoo($supplier): int
    {
        if ($supplier->odoo_id) {
            $this->odooService->write('res.partner', [$supplier->odoo_id], [
                'name' => $supplier->name,
                'email' => $supplier->email,
                'phone' => $supplier->phone,
            ]);
            return $supplier->odoo_id;
        }

        $odooId = $this->odooService->create('res.partner', [
            'name' => $supplier->name,
            'email' => $supplier->email,
            'phone' => $supplier->phone,
            'street' => $supplier->address,
            'supplier_rank' => 1,
            'is_company' => false,
        ]);

        $supplier->update(['odoo_id' => $odooId]);

        return $odooId;
    }

    private function getOdooProductVariantId($product): int
    {
        if (!$product->odoo_id) {
            throw new \Exception("Product {$product->name} not synced to Odoo");
        }

        $variants = $this->odooService->searchRead(
            'product.product',
            [['product_tmpl_id', '=', $product->odoo_id]],
            ['id'],
            limit: 1
        );

        return $variants[0]['id'];
    }

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