<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Inventory;
use Illuminate\Database\Seeder;

/**
 * ProductSeeder
 *
 * Creates both Product records AND their Inventory records together.
 * This demonstrates the ERP principle that every product must have a
 * corresponding inventory record from the moment it's registered.
 *
 * ERP NOTE ON BARCODES:
 * The barcodes here are fake but follow the EAN-13 format (13 digits).
 * In real life, manufacturers print barcodes on packaging.
 * When you scan one at a POS terminal or receiving dock, your ERP
 * looks up this exact string in the products table.
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        // Each entry: product fields + initial_stock + reorder_point
        $products = [
            // Electronics
            [
                'name'          => 'Wireless Mouse',
                'sku'           => 'MOUSE-WL-001',
                'barcode'       => '8901234567890',
                'description'   => 'Ergonomic wireless mouse, 2.4GHz, USB receiver',
                'price'         => 850.00,
                'cost'          => 520.00,
                'unit'          => 'piece',
                'initial_stock' => 45,
                'reorder_point' => 10,
            ],
            [
                'name'          => 'USB-C Hub 7-in-1',
                'sku'           => 'HUB-USBC-001',
                'barcode'       => '8901234567891',
                'description'   => '7-in-1 USB-C hub with HDMI, USB 3.0, SD card reader',
                'price'         => 1850.00,
                'cost'          => 1100.00,
                'unit'          => 'piece',
                'initial_stock' => 20,
                'reorder_point' => 5,
            ],
            [
                'name'          => 'Mechanical Keyboard TKL',
                'sku'           => 'KB-MECH-TKL-001',
                'barcode'       => '8901234567892',
                'description'   => 'Tenkeyless mechanical keyboard, brown switches',
                'price'         => 3200.00,
                'cost'          => 1950.00,
                'unit'          => 'piece',
                'initial_stock' => 15,
                'reorder_point' => 5,
            ],
            [
                'name'          => 'HDMI Cable 2m',
                'sku'           => 'CABLE-HDMI-2M',
                'barcode'       => '8901234567893',
                'description'   => 'High-speed HDMI 2.0 cable, 2 meters, 4K support',
                'price'         => 320.00,
                'cost'          => 150.00,
                'unit'          => 'piece',
                'initial_stock' => 120,
                'reorder_point' => 30,
            ],
            // Office Supplies
            [
                'name'          => 'A4 Bond Paper (500 sheets)',
                'sku'           => 'PAPER-A4-500',
                'barcode'       => '8901234567894',
                'description'   => 'A4 80gsm bond paper, 500 sheets per ream',
                'price'         => 280.00,
                'cost'          => 195.00,
                'unit'          => 'ream',
                'initial_stock' => 200,
                'reorder_point' => 50,
            ],
            [
                'name'          => 'Ballpoint Pen Blue (Box of 12)',
                'sku'           => 'PEN-BP-BLUE-12',
                'barcode'       => '8901234567895',
                'description'   => 'Blue ballpoint pens, medium point, box of 12',
                'price'         => 120.00,
                'cost'          => 72.00,
                'unit'          => 'box',
                'initial_stock' => 80,
                'reorder_point' => 20,
            ],
            [
                'name'          => 'Stapler Heavy Duty',
                'sku'           => 'STAPLER-HD-001',
                'barcode'       => '8901234567896',
                'description'   => 'Heavy duty stapler, 50-sheet capacity',
                'price'         => 450.00,
                'cost'          => 270.00,
                'unit'          => 'piece',
                'initial_stock' => 30,
                'reorder_point' => 8,
            ],
            // Low stock item — to demonstrate the low-stock alert feature
            [
                'name'          => 'Toner Cartridge HP 85A',
                'sku'           => 'TONER-HP-85A',
                'barcode'       => '8901234567897',
                'description'   => 'HP 85A Black LaserJet Toner Cartridge',
                'price'         => 2800.00,
                'cost'          => 1900.00,
                'unit'          => 'piece',
                'initial_stock' => 3,   // ← Intentionally LOW (below reorder_point of 5)
                'reorder_point' => 5,
            ],
            // Zero stock item — to test stock validation errors
            [
                'name'          => 'Webcam 1080p',
                'sku'           => 'CAM-WEB-1080P',
                'barcode'       => '8901234567898',
                'description'   => 'Full HD webcam with built-in microphone',
                'price'         => 1650.00,
                'cost'          => 980.00,
                'unit'          => 'piece',
                'initial_stock' => 0,   // ← Zero stock — testing "out of stock" error
                'reorder_point' => 5,
            ],
        ];

        foreach ($products as $data) {
            $initialStock  = $data['initial_stock'];
            $reorderPoint  = $data['reorder_point'];

            // Remove non-product-table fields
            unset($data['initial_stock'], $data['reorder_point']);

            $product = Product::create($data);

            // Create inventory record immediately alongside the product
            Inventory::create([
                'product_id'    => $product->id,
                'quantity'      => $initialStock,
                'reorder_point' => $reorderPoint,
                'last_updated'  => now(),
            ]);
        }

        $this->command->info('✅ Products seeded: ' . count($products));
        $this->command->line('   (Includes 1 low-stock and 1 zero-stock product for testing)');
    }
}
