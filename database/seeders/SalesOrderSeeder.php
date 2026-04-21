<?php

namespace Database\Seeders;

use App\Models\SalesOrder;
use App\Models\SalesOrderItem;
use App\Models\Product;
use App\Services\SalesOrderService;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;

/**
 * SalesOrderSeeder
 *
 * Creates sample sales orders using the actual SalesOrderService,
 * so inventory is properly deducted — just like a real order would work.
 *
 * After seeding, check the inventory table to see the stock reductions!
 * This demonstrates how the sales flow affects inventory in real time.
 */
class SalesOrderSeeder extends Seeder
{
    public function __construct(
        private readonly SalesOrderService $salesOrderService
    ) {}

    public function run(): void
    {
        // Get product IDs by SKU for reliable referencing
        $mouse    = Product::where('sku', 'MOUSE-WL-001')->first();
        $hub      = Product::where('sku', 'HUB-USBC-001')->first();
        $keyboard = Product::where('sku', 'KB-MECH-TKL-001')->first();
        $hdmi     = Product::where('sku', 'CABLE-HDMI-2M')->first();
        $paper    = Product::where('sku', 'PAPER-A4-500')->first();
        $pens     = Product::where('sku', 'PEN-BP-BLUE-12')->first();

        $orders = [
            // Order 1: Tech company buying electronics
            [
                'customer_id' => 3, // Tech Solutions Inc.
                'notes'       => 'Urgent — for new employee setup',
                'items'       => [
                    ['product_id' => $mouse->id,    'quantity' => 5,  'unit_price' => $mouse->price],
                    ['product_id' => $hub->id,      'quantity' => 3,  'unit_price' => $hub->price],
                    ['product_id' => $keyboard->id, 'quantity' => 2,  'unit_price' => $keyboard->price],
                ],
            ],
            // Order 2: Individual customer buying cables + office supplies
            [
                'customer_id' => 1, // Juan dela Cruz
                'notes'       => null,
                'items'       => [
                    ['product_id' => $hdmi->id,  'quantity' => 2,  'unit_price' => $hdmi->price],
                    ['product_id' => $paper->id, 'quantity' => 5,  'unit_price' => $paper->price],
                ],
            ],
            // Order 3: Hardware store buying office supplies in bulk
            [
                'customer_id' => 5, // Sunshine Hardware Store
                'notes'       => 'Monthly office supply restock',
                'items'       => [
                    ['product_id' => $paper->id, 'quantity' => 20, 'unit_price' => 265.00], // Bulk discount
                    ['product_id' => $pens->id,  'quantity' => 10, 'unit_price' => 110.00], // Bulk discount
                ],
            ],
        ];

        foreach ($orders as $orderData) {
            // Use the real service — this deducts inventory too!
            $this->salesOrderService->createOrder($orderData);
        }

        $this->command->info('✅ Sales Orders seeded: ' . count($orders));
        $this->command->line('   Inventory was deducted for each order.');
    }
}
