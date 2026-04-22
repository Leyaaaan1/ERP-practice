<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Inventory Model
 *
 * ERP CONCEPT: Inventory tracks HOW MANY of each product you have.
 * It's intentionally separate from the Product model because:
 * 1. Stock levels change constantly (every sale, every purchase)
 * 2. Product info changes rarely (name, SKU, price)
 * 3. In multi-warehouse ERPs, one product has MANY inventory records (one per location)
 *
 * reorder_point: The minimum stock level before you should place a new purchase order.
 *   Example: if reorder_point is 20 and stock drops to 18, it's time to restock.
 *   In a full ERP, this triggers an automatic Purchase Order suggestion.
 *
 * @property int $id
 * @property int $product_id
 * @property int $quantity       Current stock on hand
 * @property int $reorder_point  Alert threshold
 */
class Inventory extends Model
{
    use HasFactory;

    protected $table = 'inventory';

    protected $fillable = [
        'product_id',
        'quantity',
        'reorder_point',
        'last_updated',
        'odoo_id'

    ];

    protected $casts = [
        'last_updated' => 'datetime',
    ];

    /**
     * The product this inventory record belongs to.
     * Use: $inventory->product->name
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Check if this item is below the reorder threshold.
     * Used to generate low-stock alerts.
     */
    public function isLowStock(): bool
    {
        return $this->quantity <= $this->reorder_point;
    }

    /**
     * How many units are below the reorder point?
     * Useful for generating reorder quantities.
     */
    public function shortfall(): int
    {
        if ($this->quantity >= $this->reorder_point) {
            return 0;
        }
        return $this->reorder_point - $this->quantity;
    }
}
