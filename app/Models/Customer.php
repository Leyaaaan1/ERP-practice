<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Customer Model
 *
 * ERP CONCEPT: In the Sales Flow, the Customer is the starting point.
 * Everything begins with "who is buying from us?"
 *
 * @property int $id
 * @property string $name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $address
 */
class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'address',
        'odoo_id'

    ];

    /**
     * A customer can have many sales orders over time.
     * This is a one-to-many relationship (1 customer → many orders).
     */
    public function salesOrders(): HasMany
    {
        return $this->hasMany(SalesOrder::class);
    }
}
