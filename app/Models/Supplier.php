<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Supplier Model
 *
 * ERP CONCEPT: Suppliers are the source of your inventory.
 * They are the VENDOR side of the Purchase Flow.
 *
 * Think of the symmetry:
 * - Customer → Sales Order  → Inventory decreases
 * - Supplier → Purchase Order → Inventory increases
 *
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
 * @property string|null $contact_person
 */
class Supplier extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'contact_person',
    ];

    /**
     * All purchase orders placed with this supplier.
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class);
    }
}
