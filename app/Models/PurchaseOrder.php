<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * PurchaseOrder Model
 *
 * ERP CONCEPT: A Purchase Order (PO) is sent TO a supplier to REQUEST goods.
 * It is the core document of the Purchase Flow.
 *
 * KEY DIFFERENCE from Sales Orders:
 * - Sales Order: inventory deducted on CONFIRMATION
 * - Purchase Order: inventory added only on RECEIPT of goods
 *
 * This mimics real-world: you might order 100 items but receive them in
 * batches, or some might arrive damaged. You only count what you have.
 *
 * STATUS MACHINE:
 *   pending  → PO created, not yet sent
 *   ordered  → PO sent to supplier, awaiting delivery
 *   received → GOODS ARRIVED, inventory updated ← This is the key event
 *   cancelled → PO cancelled (no inventory change)
 *
 * @property int $id
 * @property string $order_number
 * @property int $supplier_id
 * @property string $status
 * @property float $total_cost
 * @property string|null $notes
 * @property \Carbon\Carbon|null $received_at
 */
class PurchaseOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'supplier_id',
        'status',
        'total_cost',
        'notes',
        'received_at',
    ];

    protected $casts = [
        'total_cost' => 'decimal:2',
        'received_at' => 'datetime',
    ];

    const STATUS_PENDING = 'pending';
    const STATUS_ORDERED = 'ordered';
    const STATUS_RECEIVED = 'received';
    const STATUS_CANCELLED = 'cancelled';

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    /**
     * Can this PO still be received?
     * Only 'pending' and 'ordered' POs can be received.
     */
    public function isReceivable(): bool
    {
        return in_array($this->status, [
            self::STATUS_PENDING,
            self::STATUS_ORDERED,
        ]);
    }
}
