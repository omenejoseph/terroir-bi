<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An arbitrary document (PDF, image, spreadsheet, …) attached to an item.
 *
 * @property string $id
 * @property string $inventory_item_id
 * @property string $name
 * @property string $object_key
 * @property string $content_type
 * @property int $size_bytes
 */
class InventoryDocument extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'inventory_item_id',
        'name',
        'object_key',
        'content_type',
        'size_bytes',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<InventoryItem, $this>
     */
    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
