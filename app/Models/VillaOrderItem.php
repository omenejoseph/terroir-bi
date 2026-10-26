<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $order_id
 * @property string|null $menu_item_id
 * @property string $name
 * @property int $quantity
 * @property Money $unit_price
 * @property Money $total
 * @property string|null $notes
 */
class VillaOrderItem extends Model
{
    use BelongsToTenant;
    use HasUlids;

    public $timestamps = false;

    protected $fillable = ['order_id', 'menu_item_id', 'name', 'quantity', 'unit_price', 'total', 'notes'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price' => MoneyCast::class,
            'total' => MoneyCast::class,
        ];
    }
}
