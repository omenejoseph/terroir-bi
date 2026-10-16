<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Where a product group (or one of its subcategories) sits in the Inventory list. A group row has an
 * empty `subcategory`; a subcategory row carries its group's name.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $category
 * @property string $group_name
 * @property string $subcategory
 * @property int $sort_order
 */
class InventoryGroupOrder extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['category', 'group_name', 'subcategory', 'sort_order'];

    protected $attributes = ['subcategory' => '', 'sort_order' => 0];

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }
}
