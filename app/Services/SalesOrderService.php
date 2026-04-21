<?php

namespace App\Services;

use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/**
 * SalesOrderService
 *
 * ERP CONCEPT: This service manages the entire Sales Flow.
 * It orchestrates: order creation → stock validation → inventory deduction.
 *
 * THE SALES FLOW (step by step):
 *   1. Receive order request with customer_id and items[]
 *   2. Validate all products exist and have sufficient stock
 *   3. Generate a unique order number
 *   4. Create the SalesOrder header record
 *   5. Create each SalesOrderItem line
 *   6. Deduct inventory for each item
 *   7. Calculate and store the total amount
 *   All of this happens inside ONE transaction — if any step fails,
 *   NOTHING is saved.
 *
 * IMPORTANT: The InventoryService does the actual stock deduction.
 * This service coordinates the overall flow and delegates to InventoryService.
 * This separation keeps each service focused on one responsibility.
 */
class SalesOrderService
{
    public function __construct(
        private readonly InventoryService $inventoryService
    ) {}

    /**
     * Create a new sales order and deduct inventory.
     *
     * @param array $data {
     *   customer_id: int,
     *   notes: string|null,
     *   items: array of {product_id, quantity, unit_price}
     * }
     * @return SalesOrder
     * @throws \Exception if any product is out of stock
     */
    public function createOrder(array $data): SalesOrder
    {
        // STEP 1: Pre-validate all items BEFORE starting the transaction.
        // We check stock availability upfront to give clear error messages.
        // (The InventoryService also validates, but doing it here first
        //  provides better UX by catching all stock issues at once.)
        $this->validateStockAvailability($data['items']);

        // STEP 2: Everything inside this transaction is atomic.
        // If any exception is thrown, ALL database changes are rolled back.
        return DB::transaction(function () use ($data) {

            // STEP 3: Calculate total amount from items
            $totalAmount = collect($data['items'])->sum(function ($item) {
                return $item['quantity'] * $item['unit_price'];
            });

            // STEP 4: Create the order header
            $order = SalesOrder::create([
                'order_number' => $this->generateOrderNumber(),
                'customer_id' => $data['customer_id'],
                'status' => SalesOrder::STATUS_CONFIRMED, // Auto-confirm on create
                'total_amount' => $totalAmount,
                'notes' => $data['notes'] ?? null,
            ]);

            // STEP 5: Create each line item and deduct inventory
            foreach ($data['items'] as $itemData) {
                // Create the line item record
                SalesOrderItem::create([
                    'sales_order_id' => $order->id,
                    'product_id' => $itemData['product_id'],
                    'quantity' => $itemData['quantity'],
                    'unit_price' => $itemData['unit_price'],
                    // Store subtotal on the line — useful for quick queries
                    'subtotal' => $itemData['quantity'] * $itemData['unit_price'],
                ]);

                // STEP 6: Deduct from inventory
                // If this throws (e.g., race condition depleted stock),
                // the entire transaction rolls back.
                $this->inventoryService->deductStock(
                    $itemData['product_id'],
                    $itemData['quantity']
                );
            }

            // Load relationships for the response
            return $order->load(['customer', 'items.product']);
        });
    }

    /**
     * Cancel a sales order and restore inventory.
     *
     * ERP CONCEPT: Cancellation is the reverse of the sales flow.
     * If stock was already deducted (status = confirmed), we must restore it.
     *
     * @throws \Exception if order cannot be cancelled
     */
    public function cancelOrder(SalesOrder $order): SalesOrder
    {
        if (!$order->isCancellable()) {
            throw new \Exception(
                "Order {$order->order_number} cannot be cancelled. " .
                "Current status: {$order->status}"
            );
        }

        return DB::transaction(function () use ($order) {
            // If inventory was deducted (status = confirmed), restore it
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

            return $order->fresh(['customer', 'items.product']);
        });
    }

    /**
     * Pre-validate that all items have sufficient stock.
     * Throws a descriptive exception if any product is unavailable.
     *
     * @throws \Exception
     */
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

    /**
     * Generate a unique, human-readable order number.
     *
     * Format: SO-YYYYMMDD-NNN
     * Example: SO-20250115-042
     *
     * ERP CONCEPT: Order numbers must be unique and human-readable
     * for communication with customers and for warehouse picking slips.
     */
    private function generateOrderNumber(): string
    {
        $date = now()->format('Ymd');
        $prefix = "SO-{$date}-";

        // Find the highest order number for today and increment
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
