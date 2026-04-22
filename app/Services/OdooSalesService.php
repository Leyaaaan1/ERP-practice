<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\SalesOrder;

/**
 * OdooSalesService
 *
 * Bridges your Laravel ERP and Odoo Sales module.
 * Demonstrates: partner sync, product sync, and sales order push.
 *
 * ODOO MODEL CHEATSHEET:
 *   res.partner        → Customers / Suppliers
 *   product.product    → Sellable product variants
 *   product.template   → Product master (groups variants)
 *   sale.order         → Sales orders (header)
 *   sale.order.line    → Sales order line items
 *   stock.quant        → Inventory quantities
 */
class OdooSalesService
{
    public function __construct(
        private readonly OdooService $odoo
    ) {}

    // ──────────────────────────────────────────────────────────────
    // CUSTOMER (res.partner) SYNC
    // ──────────────────────────────────────────────────────────────

    /**
     * Push a Laravel customer to Odoo as a res.partner.
     * Returns the Odoo partner ID.
     *
     * LEARNING NOTE: Odoo calls both customers AND suppliers "partners".
     * The flag `customer_rank > 0` marks them as customers.
     */
    public function syncCustomerToOdoo(Customer $customer): int
    {
        // Check if already synced (you'd store odoo_id on your customer model)
        if ($customer->odoo_id) {
            $this->odoo->write('res.partner', [$customer->odoo_id], [
                'name'  => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
            ]);
            return $customer->odoo_id;
        }

        // Create new partner in Odoo
        $odooId = $this->odoo->create('res.partner', [
            'name'          => $customer->name,
            'email'         => $customer->email,
            'phone'         => $customer->phone,
            'street'        => $customer->address,
            'customer_rank' => 1, // Marks as customer in Odoo
            'is_company'    => false,
        ]);

        // Save Odoo ID back to your Laravel model
        $customer->update(['odoo_id' => $odooId]);

        return $odooId;
    }

    /**
     * Pull all customers from Odoo into an array.
     * Useful for exploring what's in Odoo.
     */
    public function getOdooCustomers(): array
    {
        return $this->odoo->searchRead(
            'res.partner',
            [['customer_rank', '>', 0]],  // Only customers
            ['id', 'name', 'email', 'phone', 'street'],
            limit: 50
        );
    }

    // ──────────────────────────────────────────────────────────────
    // PRODUCT SYNC
    // ──────────────────────────────────────────────────────────────

    /**
     * Push a Laravel product to Odoo as a product.product.
     * Returns the Odoo product ID.
     *
     * LEARNING NOTE:
     * Odoo has product.template (master) and product.product (variant).
     * For simple products with no variants, they're 1:1.
     * We create on product.template and Odoo auto-creates the variant.
     */
    public function syncProductToOdoo(Product $product): int
    {
        if ($product->odoo_id) {
            $this->odoo->write('product.template', [$product->odoo_id], [
                'name'          => $product->name,
                'list_price'    => (float) $product->price,
                'standard_price' => (float) ($product->cost ?? $product->price),
                'default_code'  => $product->sku,
                'barcode'       => $product->barcode,
            ]);
            return $product->odoo_id;
        }

        // Create new product in Odoo with all required fields
        $odooId = $this->odoo->create('product.template', [
            'name'            => $product->name,
            'type'            => 'consu',       // storable product
            'categ_id'        => 1,               // Default category ID in Odoo
            'list_price'      => (float) $product->price,
            'standard_price'  => (float) ($product->cost ?? $product->price),
            'default_code'    => $product->sku,
            'barcode'         => $product->barcode ?? null,
            'sale_ok'         => true,
            'purchase_ok'     => true,

        ]);

        // Save Odoo ID to Laravel product
        $product->update(['odoo_id' => $odooId]);

        return $odooId;
    }
    /**
     * Get products from Odoo.
     */
    public function getOdooProducts(): array
    {
        return $this->odoo->searchRead(
            'product.product',
            [['sale_ok', '=', true]],
            ['id', 'name', 'default_code', 'list_price', 'qty_available'],
            limit: 100
        );
    }

    // ──────────────────────────────────────────────────────────────
    // SALES ORDER PUSH
    // ──────────────────────────────────────────────────────────────

    /**
     * Push a confirmed Laravel SalesOrder to Odoo as a sale.order.
     *
     * LEARNING NOTE:
     * In Odoo, a sale.order is created in 'draft' state.
     * To confirm it (like your 'confirmed' status), you call
     * the action_confirm() method on it.
     *
     * sale.order fields:
     *   partner_id    → Customer (res.partner ID)
     *   order_line    → Line items (use Command 0 = create new lines)
     *
     * sale.order.line fields:
     *   product_id    → product.product ID
     *   product_uom_qty → quantity
     *   price_unit    → unit price
     */
    public function pushSalesOrderToOdoo(SalesOrder $order): int
    {
        $order->load(['customer', 'items.product']);

        // 1. Ensure customer exists in Odoo
        $odooPartnerId = $this->syncCustomerToOdoo($order->customer);

        // 2. Build order lines
        // Odoo uses "Command" tuples: (0, 0, {values}) means "create new line"
        $orderLines = [];
        foreach ($order->items as $item) {
            $odooProductId = $this->getOdooProductVariantId($item->product);

            $orderLines[] = [
                0, 0,  // Command: create
                [
                    'product_id'      => $odooProductId,
                    'product_uom_qty' => $item->quantity,
                    'price_unit'      => (float) $item->unit_price,
                    'name'            => $item->product->name,
                ]
            ];
        }

        // 3. Create the sale.order in Odoo
        $odooOrderId = $this->odoo->create('sale.order', [
            'partner_id'    => $odooPartnerId,
            'note'          => $order->notes ?? '',
            'client_order_ref' => $order->order_number, // Your SO number as reference
            'order_line'    => $orderLines,
        ]);

        // 4. Confirm the order in Odoo (changes state from draft → sale)
        $uid = $this->odoo->authenticate();
        // action_confirm is a button/action in Odoo, called via execute_kw
        $this->odoo->write('sale.order', [$odooOrderId], []); // Update trigger
        // To actually confirm: use the execute method
        $this->callOdooMethod('sale.order', 'action_confirm', [[$odooOrderId]]);

        // 5. Store Odoo order ID on your Laravel order
        $order->update(['odoo_id' => $odooOrderId]);

        return $odooOrderId;
    }

    /**
     * Pull sales orders from Odoo.
     * State values: 'draft', 'sent', 'sale' (confirmed), 'done', 'cancel'
     */
    public function getOdooSalesOrders(string $state = 'sale'): array
    {
        return $this->odoo->searchRead(
            'sale.order',
            [['state', '=', $state]],
            ['id', 'name', 'partner_id', 'amount_total', 'state', 'date_order'],
            limit: 50
        );
    }

    /**
     * Get a specific Odoo sales order with its lines.
     */
    public function getOdooSalesOrderWithLines(int $odooOrderId): array
    {
        $orders = $this->odoo->read(
            'sale.order',
            [$odooOrderId],
            ['id', 'name', 'partner_id', 'amount_total', 'state', 'order_line']
        );

        if (empty($orders)) return [];

        $order = $orders[0];

        // Fetch the line items separately
        $order['lines'] = $this->odoo->read(
            'sale.order.line',
            $order['order_line'],
            ['product_id', 'product_uom_qty', 'price_unit', 'price_subtotal', 'name']
        );

        return $order;
    }

    // ──────────────────────────────────────────────────────────────
    // INVENTORY CHECK
    // ──────────────────────────────────────────────────────────────

    /**
     * Get stock level from Odoo for a product.
     * Uses product.product's qty_available field.
     *
     * LEARNING NOTE:
     * In Odoo, inventory is tracked via stock.quant records.
     * The qty_available field on product.product is a computed
     * convenience field that sums all quants.
     */
    public function getOdooStockLevel(int $odooProductTemplateId): float
    {
        // Get the product variant ID from template ID
        $variants = $this->odoo->searchRead(
            'product.product',
            [['product_tmpl_id', '=', $odooProductTemplateId]],
            ['id', 'qty_available', 'name'],
            limit: 1
        );

        return $variants[0]['qty_available'] ?? 0.0;
    }

    // ──────────────────────────────────────────────────────────────
    // HELPERS
    // ──────────────────────────────────────────────────────────────

    /**
     * Get or create the Odoo product.product (variant) ID.
     * When you create a product.template, Odoo auto-creates one product.product.
     */
    private function getOdooProductVariantId(Product $product): int
    {
        $odooTemplateId = $this->syncProductToOdoo($product);

        $variants = $this->odoo->searchRead(
            'product.product',
            [['product_tmpl_id', '=', $odooTemplateId]],
            ['id'],
            limit: 1
        );

        return $variants[0]['id'];
    }

    /**
     * Call an Odoo action/button method directly.
     * Used for workflow transitions like action_confirm, action_cancel, etc.
     */
    public function callOdooMethod(string $model, string $method, array $args): mixed
    {
        $uid = $this->odoo->authenticate();

        // We need to reach into OdooService's xmlRpcCall —
        // or add a public executeMethod() on OdooService.
        // Best practice: add this to OdooService as a public method.
        return $this->odoo->execute($model, $method, $args);
    }
}