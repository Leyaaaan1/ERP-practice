<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * SalesOrder Model
 *
 * ERP CONCEPT: A Sales Order (SO) is the core document of the Sales Flow.
 * It represents a confirmed agreement to deliver products to a customer.
 *
 * LIFECYCLE:
 *   1. pending   → Sales rep creates draft order
 *   2. confirmed → Order verified, INVENTORY DEDUCTED here
 *   3. shipped   → Warehouse ships the goods
 *   4. delivered → Customer confirms receipt
 *   5. cancelled → Order cancelled, INVENTORY RESTORED here
 *
 * @property int $id
 * @property string $order_number
 * @property int $customer_id
 * @property string $status
 * @property float $total_amount
 * @property string|null $notes
 */
class SalesOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'customer_id',
        'status',
        'total_amount',
        'notes',
        'odoo_id'
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
    ];

    // Valid order statuses — these reflect the real-world lifecycle of a sale
    const STATUS_PENDING = 'pending';
    const STATUS_CONFIRMED = 'confirmed';
    const STATUS_SHIPPED = 'shipped';
    const STATUS_DELIVERED = 'delivered';
    const STATUS_CANCELLED = 'cancelled';

    /**
     * The customer who placed this order.
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * The individual line items (products) on this order.
     * ERP Pattern: Header (SalesOrder) + Lines (SalesOrderItems)
     */
    public function items(): HasMany
    {
        return $this->hasMany(SalesOrderItem::class);
    }

    /**
     * Whether this order can still be cancelled.
     * Once shipped, cancellation is more complex (returns flow).
     */
    public function isCancellable(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_CONFIRMED,
        ]);
    }

    /**
     * Whether inventory has been deducted for this order.
     * Inventory is deducted when status moves to 'confirmed'.
     */
    public function hasInventoryDeducted(): bool
    {
        return in_array($this->status, [
            self::STATUS_CONFIRMED,
            self::STATUS_SHIPPED,
            self::STATUS_DELIVERED,
        ]);
    }
}
