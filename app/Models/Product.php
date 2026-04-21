<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Product Model
 *
 * ERP CONCEPT: Products are the items you buy and sell.
 * Every product has a SKU (internal code) and optionally a barcode
 * (for scanner-based lookups in a warehouse or POS system).
 *
 * @property int $id
 * @property string $name
 * @property string $sku          Stock Keeping Unit — your internal code
 * @property string|null $barcode Scannable barcode (EAN-13, UPC, etc.)
 * @property float $price         Default selling price
 * @property float $cost          Default purchase cost
 * @property string $unit         Unit of measure (piece, kg, box...)
 * @property bool $is_active
 */
class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'sku',
        'barcode',
        'description',
        'price',
        'cost',
        'unit',
        'is_active',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'cost' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    /**
     * Each product has exactly ONE inventory record.
     * This is a one-to-one relationship.
     * Use: $product->inventory->quantity
     */
    public function inventory(): HasOne
    {
        return $this->hasOne(Inventory::class);
    }

    /**
     * A product can appear on many sales order lines.
     */
    public function salesOrderItems(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    /**
     * A product can appear on many purchase order lines.
     */
    public function purchaseOrderItems(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /**
     * Convenience method: get current stock level.
     * Usage: $product->currentStock()
     */
    public function currentStock(): int
    {
        return $this->inventory?->quantity ?? 0;
    }

    /**
     * Check if product has enough stock for a given quantity.
     * Used in the sales flow to validate before deducting.
     */
    public function hasStock(int $quantity): bool
    {
        return $this->currentStock() >= $quantity;
    }
}
