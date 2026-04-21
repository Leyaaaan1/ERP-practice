<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Inventory;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PurchaseOrderTest
 *
 * Verifies the Purchase Flow:
 *   1. Creating a PO does NOT change inventory
 *   2. Receiving a PO adds the correct quantity to inventory
 *   3. A PO cannot be received twice
 *
 * RUN WITH: php artisan test --filter PurchaseOrderTest
 */
class PurchaseOrderTest extends TestCase
{
    use RefreshDatabase;

    private Supplier $supplier;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->supplier = Supplier::create([
            'name'  => 'Test Supplier',
            'email' => 'supplier@test.com',
        ]);

        $this->product = Product::create([
            'name'  => 'Test Component',
            'sku'   => 'COMP-001',
            'price' => 200.00,
            'cost'  => 120.00,
            'unit'  => 'piece',
        ]);

        Inventory::create([
            'product_id'    => $this->product->id,
            'quantity'      => 10,  // Starting stock
            'reorder_point' => 20,
        ]);
    }

    /** @test */
    public function creating_a_purchase_order_does_not_change_inventory(): void
    {
        $this->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 120.00],
            ],
        ])->assertStatus(201);

        // KEY ERP RULE: Inventory should NOT have changed yet!
        $this->assertDatabaseHas('inventory', [
            'product_id' => $this->product->id,
            'quantity'   => 10, // UNCHANGED
        ]);
    }

    /** @test */
    public function receiving_a_purchase_order_increases_inventory(): void
    {
        // Step 1: Create the PO
        $createResponse = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 50, 'unit_cost' => 120.00],
            ],
        ]);

        $orderId = $createResponse->json('data.id');

        // Step 2: Receive the PO (goods arrive at warehouse)
        $receiveResponse = $this->postJson("/api/purchase-orders/{$orderId}/receive");

        $receiveResponse->assertStatus(200)
                        ->assertJsonPath('success', true)
                        ->assertJsonPath('data.status', 'received')
                        ->assertJsonPath('inventory_updated', true);

        // CRITICAL: Inventory should now be increased
        $this->assertDatabaseHas('inventory', [
            'product_id' => $this->product->id,
            'quantity'   => 60, // 10 + 50
        ]);

        // received_at should be set
        $order = \App\Models\PurchaseOrder::find($orderId);
        $this->assertNotNull($order->received_at);
    }

    /** @test */
    public function a_purchase_order_cannot_be_received_twice(): void
    {
        $createResponse = $this->postJson('/api/purchase-orders', [
            'supplier_id' => $this->supplier->id,
            'items' => [
                ['product_id' => $this->product->id, 'quantity' => 20, 'unit_cost' => 120.00],
            ],
        ]);

        $orderId = $createResponse->json('data.id');

        // First receive — should succeed
        $this->postJson("/api/purchase-orders/{$orderId}/receive")->assertStatus(200);

        // Second receive — should FAIL
        $response = $this->postJson("/api/purchase-orders/{$orderId}/receive");
        $response->assertStatus(422)
                 ->assertJsonPath('success', false);

        // Inventory should only have been added ONCE (10 + 20 = 30, not 10 + 20 + 20 = 50)
        $this->assertDatabaseHas('inventory', [
            'product_id' => $this->product->id,
            'quantity'   => 30,
        ]);
    }
}
