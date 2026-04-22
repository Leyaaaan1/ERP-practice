<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * SalesOrderItem Model (Order Line Item)
 *
 * ERP CONCEPT: This is the "line item" of a sales order.
 * Each row represents ONE product ordered in ONE quantity at ONE price.
 *
 * IMPORTANT: unit_price is stored here — NOT pulled from the product.
 * Why? Because the product's price may change later. You need to know
 * what price was charged AT THE TIME of the sale. This is critical for
 * financial records and auditing.
 *
 * @property int $id
 * @property int $sales_order_id
 * @property int $product_id
 * @property int $quantity
 * @property float $unit_price   Price frozen at time of sale
 * @property float $subtotal     quantity × unit_price
 */
class SalesOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'sales_order_id',
        'product_id',
        'quantity',
        'unit_price',
        'subtotal',
        'odoo_id'
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'subtotal' => 'decimal:2',
    ];

    public function salesOrder(): BelongsTo
    {
        return $this->belongsTo(SalesOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
