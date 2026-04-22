<?php


namespace App\Http\Controllers\commands;

use App\Services\OdooService;
use App\Services\OdooSalesService;
use App\Models\Customer;
use App\Models\SalesOrder;
use Illuminate\Console\Command;

class TestOdooConnection extends Command
{
    protected $signature = 'odoo:test';
    protected $description = 'Test the Odoo XML-RPC connection and demo sync';

    public function __construct(
        private readonly OdooService      $odoo,
        private readonly OdooSalesService $odooSales,
    )
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('Testing Odoo connection...');

        // 1. Test auth
        try {
            $uid = $this->odoo->authenticate();
            $this->info("✅ Authenticated! User ID: {$uid}");
        } catch (\Exception $e) {
            $this->error('❌ Auth failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        // 2. Fetch Odoo version info
        $version = $this->odoo->execute('res.partner', 'check_access_rights', [['read']], ['raise_exception' => false]);
        $this->info('✅ Object endpoint reachable');

        // 3. List Odoo customers
        $this->newLine();
        $this->info('Fetching customers from Odoo...');
        $customers = $this->odooSales->getOdooCustomers();
        $this->line("   Found " . count($customers) . " customers in Odoo");

        // 4. Sync a Laravel customer to Odoo
        $localCustomer = Customer::first();
        if ($localCustomer) {
            $odooId = $this->odooSales->syncCustomerToOdoo($localCustomer);
            $this->info("✅ Synced customer '{$localCustomer->name}' → Odoo ID: {$odooId}");
        }

        // 5. Push a sales order
        $order = SalesOrder::with(['customer', 'items.product'])->where('status', 'confirmed')->first();
        if ($order) {
            $odooOrderId = $this->odooSales->pushSalesOrderToOdoo($order);
            $this->info("✅ Pushed order {$order->order_number} → Odoo ID: {$odooOrderId}");
        }

        $this->newLine();
        $this->info('All tests passed! Check your Odoo Sales module to see the synced data.');

        return Command::SUCCESS;
    }
}