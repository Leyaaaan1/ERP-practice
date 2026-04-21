<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\SalesOrderService;
use App\Services\PurchaseOrderService;
use App\Services\InventoryService;
use Illuminate\Console\Command;

/**
 * DemoErpFlow Command
 *
 * Run this command to see the full ERP flow demonstrated in your terminal.
 * It walks through sales and purchase flows step by step, showing how
 * inventory changes at each stage.
 *
 * USAGE: php artisan erp:demo
 *
 * This is a learning tool — it mirrors exactly what the API endpoints do.
 */
class DemoErpFlow extends Command
{
    protected $signature   = 'erp:demo';
    protected $description = 'Demonstrate the ERP sales and purchase flows interactively';

    public function __construct(
        private readonly SalesOrderService $salesOrderService,
        private readonly PurchaseOrderService $purchaseOrderService,
        private readonly InventoryService $inventoryService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->newLine();
        $this->line('╔══════════════════════════════════════════════════════╗');
        $this->line('║        ERP Learning Project — Flow Demonstration      ║');
        $this->line('╚══════════════════════════════════════════════════════╝');
        $this->newLine();

        $product  = Product::with('inventory')->first();
        $customer = Customer::first();
        $supplier = Supplier::first();

        if (!$product || !$customer || !$supplier) {
            $this->error('Please run `php artisan db:seed` first.');
            return Command::FAILURE;
        }

        $this->showStockLevel($product, 'INITIAL STOCK LEVEL');

        // ── SALES FLOW ────────────────────────────────────────────────────
        $this->newLine();
        $this->info('━━━ SALES FLOW (Outbound) ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line("Customer: {$customer->name}");
        $this->line("Product:  {$product->name} (SKU: {$product->sku})");
        $this->line("Ordering: 3 units at ₱{$product->price} each");
        $this->newLine();

        $salesOrder = $this->salesOrderService->createOrder([
            'customer_id' => $customer->id,
            'notes'       => 'Demo sales order',
            'items'       => [
                ['product_id' => $product->id, 'quantity' => 3, 'unit_price' => $product->price],
            ],
        ]);

        $this->info("✅ Sales Order created: {$salesOrder->order_number}");
        $this->line("   Status:       {$salesOrder->status}");
        $this->line("   Total:        ₱" . number_format($salesOrder->total_amount, 2));
        $this->showStockLevel($product->fresh('inventory'), 'STOCK AFTER SALE');

        // ── PURCHASE FLOW ─────────────────────────────────────────────────
        $this->newLine();
        $this->info('━━━ PURCHASE FLOW (Inbound) ━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->line("Supplier: {$supplier->name}");
        $this->line("Ordering: 50 units at ₱{$product->cost} each from supplier");
        $this->newLine();

        $purchaseOrder = $this->purchaseOrderService->createOrder([
            'supplier_id' => $supplier->id,
            'notes'       => 'Demo purchase order',
            'items'       => [
                ['product_id' => $product->id, 'quantity' => 50, 'unit_cost' => $product->cost],
            ],
        ]);

        $this->info("📋 Purchase Order created: {$purchaseOrder->order_number}");
        $this->line("   Status: {$purchaseOrder->status}");
        $this->showStockLevel($product->fresh('inventory'), 'STOCK AFTER PO CREATION (no change yet)');

        // Receive the PO
        $this->newLine();
        $this->line("📦 Receiving goods from supplier...");
        $this->purchaseOrderService->receiveOrder($purchaseOrder);

        $this->info("✅ Purchase Order received!");
        $this->showStockLevel($product->fresh('inventory'), 'STOCK AFTER RECEIVING GOODS');

        // ── LOW STOCK REPORT ──────────────────────────────────────────────
        $this->newLine();
        $this->info('━━━ LOW STOCK REPORT ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $lowStock = $this->inventoryService->getLowStockProducts();

        if ($lowStock->isEmpty()) {
            $this->line('   All products adequately stocked.');
        } else {
            foreach ($lowStock as $item) {
                $this->warn("   ⚠️  {$item->product->name}: {$item->quantity} units (reorder at {$item->reorder_point})");
            }
        }

        $this->newLine();
        $this->line('Demo complete. Check the database to see all the records created!');
        $this->newLine();

        return Command::SUCCESS;
    }

    private function showStockLevel(Product $product, string $label): void
    {
        $qty = $product->inventory?->quantity ?? 0;
        $this->newLine();
        $this->line("  ┌─ {$label}");
        $this->line("  │  {$product->name}: {$qty} units in stock");
        $this->line("  └────────────────────────────────────");
    }
}
