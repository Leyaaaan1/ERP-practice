<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * DatabaseSeeder — Master seeder that calls all module seeders in order.
 *
 * ORDER MATTERS here because of foreign key constraints:
 *   1. Customers (no dependencies)
 *   2. Suppliers (no dependencies)
 *   3. Products (no dependencies)
 *   4. Inventory (depends on Products)
 *   5. SalesOrders (depends on Customers + Products + Inventory)
 *   6. PurchaseOrders (depends on Suppliers + Products)
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            CustomerSeeder::class,
            SupplierSeeder::class,
            ProductSeeder::class,       // Also seeds Inventory
            SalesOrderSeeder::class,
            PurchaseOrderSeeder::class,
        ]);
    }
}
