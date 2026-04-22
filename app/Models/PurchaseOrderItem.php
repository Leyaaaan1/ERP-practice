<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * PurchaseOrderItem Model
 *
 * ERP CONCEPT: Line items for a purchase order.
 * Same Header+Lines pattern as Sales Orders.
 *
 * unit_cost: The price paid to the supplier, frozen at time of purchase.
 * This is used for COGS (Cost of Goods Sold) calculations and margin analysis.
 *
 * @property int $id
 * @property int $purchase_order_id
 * @property int $product_id
 * @property int $quantity
 * @property float $unit_cost   Cost frozen at time of purchase
 * @property float $subtotal
 */
class PurchaseOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'purchase_order_id',
        'product_id',
        'quantity',
        'unit_cost',
        'subtotal',
        'odoo_id'
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
