<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Audit\Auditable;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A label the organisation puts on its customers ("Restaurant", "Hotel", …), in an order it
 * chooses. Free-form and per tenant; the fixed sales channel stays on `customers.customer_type`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $name
 * @property int $sort_order
 * @property bool $is_active
 */
class CustomerCategory extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'name',
        'sort_order',
        'is_active',
    ];

    protected $attributes = [
        'sort_order' => 0,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Customer, $this>
     */
    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class, 'customer_category_id');
    }
}
