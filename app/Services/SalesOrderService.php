<?php

namespace App\Services;

use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SalesOrderService
{
    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly OdooSalesService $odooSalesService
    ) {}

    public function createOrder(array $data): SalesOrder
    {
        $this->validateStockAvailability($data['items']);

        return DB::transaction(function () use ($data) {

            $totalAmount = collect($data['items'])->sum(function ($item) {
                return $item['quantity'] * $item['unit_price'];
            });

            $order = SalesOrder::create([
                'order_number' => $this->generateOrderNumber(),
                'customer_id' => $data['customer_id'],
                'status' => SalesOrder::STATUS_CONFIRMED,
                'total_amount' => $totalAmount,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['items'] as $itemData) {
                SalesOrderItem::create([
                    'sales_order_id' => $order->id,
                    'product_id' => $itemData['product_id'],
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
                    'subtotal' => $itemData['quantity'] * $itemData['unit_price'],
                ]);

                $this->inventoryService->deductStock(
                    $itemData['product_id'],
                    $itemData['quantity']
                );
            }

            return $order->load(['customer', 'items.product']);
        });
    }

    /**
     * Create order and push to Odoo
     */
    public function createOrderAndPushToOdoo(array $data): SalesOrder
    {
        $order = $this->createOrder($data);

        try {
            $this->odooSalesService->pushSalesOrderToOdoo($order);
            Log::info("Sales order pushed to Odoo", [
                'sales_order_id' => $order->id,
                'order_number' => $order->order_number,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to push sales order to Odoo", [
                'sales_order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);
            // Order is still created locally - Odoo sync is optional
        }

        return $order;
    }

    public function cancelOrder(SalesOrder $order): SalesOrder
    {
        if (!$order->isCancellable()) {
            throw new \Exception(
                "Order {$order->order_number} cannot be cancelled. " .
                "Current status: {$order->status}"
            );
        }

        return DB::transaction(function () use ($order) {
            if ($order->hasInventoryDeducted()) {
                foreach ($order->items as $item) {
                    $this->inventoryService->restoreStock(
                        $item->product_id,
                        $item->quantity
                    );
                }
            }

            $order->status = SalesOrder::STATUS_CANCELLED;
            $order->save();

            // Sync cancellation to Odoo if order exists there
            try {
                if ($order->odoo_id) {
                    $this->odooSalesService->callOdooMethod(
                        'sale.order',
                        'action_cancel',
                        [[$order->odoo_id]]
                    );
                    Log::info("Sales order cancellation synced to Odoo", [
                        'sales_order_id' => $order->id,
                        'odoo_order_id' => $order->odoo_id,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error("Failed to sync cancellation to Odoo", [
                    'sales_order_id' => $order->id,
                    'error' => $e->getMessage(),
                ]);
            }

            return $order->fresh(['customer', 'items.product']);
        });
    }

    private function validateStockAvailability(array $items): void
    {
        $errors = [];

        foreach ($items as $item) {
            $product = Product::with('inventory')->find($item['product_id']);

            if (!$product) {
                $errors[] = "Product ID {$item['product_id']} not found.";
                continue;
            }

            if (!$product->is_active) {
                $errors[] = "Product '{$product->name}' is not active.";
                continue;
            }

            $available = $product->currentStock();
            if ($available < $item['quantity']) {
                $errors[] = "'{$product->name}': requested {$item['quantity']}, only {$available} in stock.";
            }
        }

        if (!empty($errors)) {
            throw new \Exception("Stock validation failed:\n" . implode("\n", $errors));
        }
    }

    private function generateOrderNumber(): string
    {
        $date = now()->format('Ymd');
        $prefix = "SO-{$date}-";

        $lastOrder = SalesOrder::where('order_number', 'like', "{$prefix}%")
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