<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Inventory;
use App\Models\SalesOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SalesOrderTest
 *
 * These tests verify the entire Sales Flow:
 *   1. Creating an order deducts the correct quantity from inventory
 *   2. Attempting to oversell is rejected with a clear error
 *   3. Cancelling an order restores inventory
 *   4. All of this happens atomically (transaction integrity)
 *
 * RUN WITH: php artisan test --filter SalesOrderTest
 */
class SalesOrderTest extends TestCase
{
    use RefreshDatabase;

    private Customer $customer;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a test customer
        $this->customer = Customer::create([
            'name'  => 'Test Customer',
            'email' => 'test@example.com',
        ]);

        // Create a test product with 50 units in stock
        $this->product = Product::create([
            'name'  => 'Test Widget',
            'sku'   => 'TEST-001',
            'price' => 100.00,
            'cost'  => 60.00,
            'unit'  => 'piece',
        ]);

        Inventory::create([
            'product_id'    => $this->product->id,
            'quantity'      => 50,
            'reorder_point' => 10,
        ]);
    }

    /** @test */
    public function it_creates_a_sales_order_and_deducts_inventory(): void
    {
        $response = $this->postJson('/api/sales-orders', [
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity'   => 5,
                    'unit_price' => 100.00,
                ],
            ],
        ]);

        $response->assertStatus(201)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.status', 'confirmed')
                 ->assertJsonPath('data.total_amount', '500.00');

        // CRITICAL: Verify inventory was actually deducted
        $this->assertDatabaseHas('inventory', [
            'product_id' => $this->product->id,
            'quantity'   => 45, // 50 - 5
        ]);
    }

    /** @test */
    public function it_rejects_order_when_stock_is_insufficient(): void
    {
        // Try to order 100 units when only 50 are in stock
        $response = $this->postJson('/api/sales-orders', [
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity'   => 100,
                    'unit_price' => 100.00,
                ],
            ],
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('success', false);

        // Stock should be UNCHANGED (transaction rolled back)
        $this->assertDatabaseHas('inventory', [
            'product_id' => $this->product->id,
            'quantity'   => 50, // Unchanged!
        ]);

        // No order should have been created
        $this->assertDatabaseCount('sales_orders', 0);
    }

    /** @test */
    public function it_cancels_a_confirmed_order_and_restores_inventory(): void
    {
        // First, create an order that deducts 10 units
        $createResponse = $this->postJson('/api/sales-orders', [
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id,
                    'quantity'   => 10,
                    'unit_price' => 100.00,
                ],
            ],
        ]);

        $orderId = $createResponse->json('data.id');

        // Inventory should now be 40
        $this->assertDatabaseHas('inventory', [
            'product_id' => $this->product->id,
            'quantity'   => 40,
        ]);

        // Now cancel the order
        $cancelResponse = $this->postJson("/api/sales-orders/{$orderId}/cancel");

        $cancelResponse->assertStatus(200)
                       ->assertJsonPath('success', true)
                       ->assertJsonPath('data.status', 'cancelled');

        // Inventory should be restored to 50
        $this->assertDatabaseHas('inventory', [
            'product_id' => $this->product->id,
            'quantity'   => 50, // Restored!
        ]);
    }

    /** @test */
    public function it_generates_a_unique_order_number(): void
    {
        // Create two orders on the same day
        $this->postJson('/api/sales-orders', [
            'customer_id' => $this->customer->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100]],
        ]);

        $this->postJson('/api/sales-orders', [
            'customer_id' => $this->customer->id,
            'items' => [['product_id' => $this->product->id, 'quantity' => 1, 'unit_price' => 100]],
        ]);

        $orders = SalesOrder::all();
        $this->assertCount(2, $orders);
        // Order numbers should be different
        $this->assertNotEquals($orders[0]->order_number, $orders[1]->order_number);
    }

    /** @test */
    public function it_validates_required_fields(): void
    {
        $response = $this->postJson('/api/sales-orders', []);

        $response->assertStatus(422)
                 ->assertJsonPath('success', false)
                 ->assertJsonStructure(['errors' => ['customer_id', 'items']]);
    }
}
