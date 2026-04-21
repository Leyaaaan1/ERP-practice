<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Services\PurchaseOrderService;
use Illuminate\Database\Seeder;

/**
 * PurchaseOrderSeeder
 *
 * Creates sample purchase orders. One is left as 'pending' (not yet received),
 * and one is fully received (inventory increased) — to demonstrate both states.
 */
class PurchaseOrderSeeder extends Seeder
{
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrderService
    ) {}

    public function run(): void
    {
        $mouse   = Product::where('sku', 'MOUSE-WL-001')->first();
        $hub     = Product::where('sku', 'HUB-USBC-001')->first();
        $toner   = Product::where('sku', 'TONER-HP-85A')->first();
        $webcam  = Product::where('sku', 'CAM-WEB-1080P')->first();
        $paper   = Product::where('sku', 'PAPER-A4-500')->first();

        // PO 1: Created AND received — inventory was already restocked
        $po1 = $this->purchaseOrderService->createOrder([
            'supplier_id' => 1, // Manila Electronics Wholesale
            'notes'       => 'Monthly electronics restock',
            'items'       => [
                ['product_id' => $mouse->id,  'quantity' => 100, 'unit_cost' => 520.00],
                ['product_id' => $hub->id,    'quantity' => 50,  'unit_cost' => 1100.00],
                ['product_id' => $webcam->id, 'quantity' => 30,  'unit_cost' => 980.00],
            ],
        ]);

        // Simulate receiving this PO — inventory goes UP
        $this->purchaseOrderService->receiveOrder($po1);

        // PO 2: Created but NOT yet received — pending supplier delivery
        // Inventory NOT updated yet — demonstrates the two-step purchase flow
        $this->purchaseOrderService->createOrder([
            'supplier_id' => 2, // Pacific Office Supplies Co.
            'notes'       => 'Office supplies reorder — low stock alert',
            'items'       => [
                ['product_id' => $toner->id, 'quantity' => 20,  'unit_cost' => 1900.00],
                ['product_id' => $paper->id, 'quantity' => 100, 'unit_cost' => 195.00],
            ],
        ]);

        $this->command->info('✅ Purchase Orders seeded: 2');
        $this->command->line('   PO-1: RECEIVED (inventory updated)');
        $this->command->line('   PO-2: PENDING (awaiting supplier delivery — inventory not yet updated)');
    }
}
