<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Inventory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ProductInventoryTest
 *
 * Tests the barcode lookup and inventory adjustment features.
 *
 * RUN WITH: php artisan test --filter ProductInventoryTest
 */
class ProductInventoryTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'name'    => 'Barcode Test Product',
            'sku'     => 'SCAN-001',
            'barcode' => '1234567890128',
            'price'   => 500.00,
            'cost'    => 300.00,
            'unit'    => 'piece',
        ]);

        Inventory::create([
            'product_id'    => $this->product->id,
            'quantity'      => 25,
            'reorder_point' => 5,
        ]);
    }

    /** @test */
    public function it_looks_up_a_product_by_barcode(): void
    {
        $response = $this->getJson("/api/products/barcode/1234567890128");

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.sku', 'SCAN-001')
                 ->assertJsonPath('data.current_stock', 25)
                 ->assertJsonPath('data.is_low_stock', false);
    }

    /** @test */
    public function barcode_lookup_returns_404_for_unknown_barcode(): void
    {
        $this->getJson("/api/products/barcode/0000000000000")
             ->assertStatus(404)
             ->assertJsonPath('success', false);
    }

    /** @test */
    public function it_allows_positive_inventory_adjustment(): void
    {
        $response = $this->postJson("/api/inventory/{$this->product->id}/adjust", [
            'quantity_change' => 10,
            'reason'          => 'Found extra units in back storage',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('success', true)
                 ->assertJsonPath('data.new_quantity', 35); // 25 + 10

        $this->assertDatabaseHas('inventory', [
            'product_id' => $this->product->id,
            'quantity'   => 35,
        ]);
    }

    /** @test */
    public function it_allows_negative_inventory_adjustment(): void
    {
        $response = $this->postJson("/api/inventory/{$this->product->id}/adjust", [
            'quantity_change' => -5,
            'reason'          => 'Write off damaged units',
        ]);

        $response->assertStatus(200)
                 ->assertJsonPath('data.new_quantity', 20); // 25 - 5
    }

    /** @test */
    public function inventory_adjustment_cannot_go_below_zero(): void
    {
        $response = $this->postJson("/api/inventory/{$this->product->id}/adjust", [
            'quantity_change' => -100, // More than we have
            'reason'          => 'Bad adjustment',
        ]);

        $response->assertStatus(422)
                 ->assertJsonPath('success', false);

        // Stock unchanged
        $this->assertDatabaseHas('inventory', [
            'product_id' => $this->product->id,
            'quantity'   => 25,
        ]);
    }

    /** @test */
    public function it_returns_low_stock_products(): void
    {
        // Make this product low stock
        Inventory::where('product_id', $this->product->id)
            ->update(['quantity' => 3, 'reorder_point' => 5]);

        $response = $this->getJson('/api/inventory/low-stock');

        $response->assertStatus(200)
                 ->assertJsonPath('success', true);

        // Should include our low-stock product
        $this->assertGreaterThan(0, $response->json('alert_count'));
    }
}
