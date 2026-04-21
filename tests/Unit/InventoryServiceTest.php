<?php

namespace Tests\Unit;

use App\Models\Product;
use App\Models\Inventory;
use App\Services\InventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * InventoryServiceTest
 *
 * Unit tests for the InventoryService.
 * These test the pure business logic in isolation.
 *
 * RUN WITH: php artisan test --filter InventoryServiceTest
 */
class InventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryService $service;
    private Product $product;
    private Inventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new InventoryService();

        $this->product = Product::create([
            'name'  => 'Service Test Product',
            'sku'   => 'SVC-TEST-001',
            'price' => 100.00,
            'cost'  => 60.00,
            'unit'  => 'piece',
        ]);

        $this->inventory = Inventory::create([
            'product_id'    => $this->product->id,
            'quantity'      => 100,
            'reorder_point' => 20,
        ]);
    }

    /** @test */
    public function deduct_stock_reduces_quantity_correctly(): void
    {
        $result = $this->service->deductStock($this->product->id, 30);

        $this->assertEquals(70, $result->quantity);
        $this->assertDatabaseHas('inventory', [
            'product_id' => $this->product->id,
            'quantity'   => 70,
        ]);
    }

    /** @test */
    public function deduct_stock_throws_when_insufficient(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Insufficient stock/');

        $this->service->deductStock($this->product->id, 200); // More than 100 available
    }

    /** @test */
    public function add_stock_increases_quantity_correctly(): void
    {
        $result = $this->service->addStock($this->product->id, 50);

        $this->assertEquals(150, $result->quantity);
    }

    /** @test */
    public function adjust_stock_handles_positive_change(): void
    {
        $result = $this->service->adjustStock($this->product->id, 25, 'Found extra in storage');

        $this->assertEquals(125, $result->quantity);
    }

    /** @test */
    public function adjust_stock_handles_negative_change(): void
    {
        $result = $this->service->adjustStock($this->product->id, -10, 'Damaged goods write-off');

        $this->assertEquals(90, $result->quantity);
    }

    /** @test */
    public function adjust_stock_throws_when_result_would_be_negative(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/negative stock/');

        $this->service->adjustStock($this->product->id, -500, 'Bad adjustment');
    }

    /** @test */
    public function restore_stock_is_equivalent_to_add_stock(): void
    {
        $this->service->deductStock($this->product->id, 40);
        // Inventory is now 60

        $result = $this->service->restoreStock($this->product->id, 40);
        // Should be back to 100

        $this->assertEquals(100, $result->quantity);
    }

    /** @test */
    public function inventory_model_correctly_detects_low_stock(): void
    {
        // Set quantity below reorder point
        $this->inventory->quantity = 15;
        $this->inventory->reorder_point = 20;
        $this->inventory->save();

        $this->assertTrue($this->inventory->isLowStock());
        $this->assertEquals(5, $this->inventory->shortfall());
    }

    /** @test */
    public function inventory_model_correctly_detects_adequate_stock(): void
    {
        $this->inventory->quantity = 50;
        $this->inventory->reorder_point = 20;
        $this->inventory->save();

        $this->assertFalse($this->inventory->isLowStock());
        $this->assertEquals(0, $this->inventory->shortfall());
    }

    /** @test */
    public function get_low_stock_products_returns_correct_items(): void
    {
        // Set inventory below reorder point
        $this->inventory->update(['quantity' => 5, 'reorder_point' => 20]);

        $lowStockItems = $this->service->getLowStockProducts();

        $this->assertCount(1, $lowStockItems);
        $this->assertEquals($this->product->id, $lowStockItems->first()->product_id);
    }
}
