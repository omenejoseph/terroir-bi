<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $cost_id
 * @property string $object_key
 * @property string $filename
 * @property string $content_type
 * @property int $size_bytes
 */
class CostAttachment extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['cost_id', 'object_key', 'filename', 'content_type', 'size_bytes'];

    protected function casts(): array
    {
        return ['size_bytes' => 'integer'];
    }

    /**
     * @return BelongsTo<Cost, $this>
     */
    public function cost(): BelongsTo
    {
        return $this->belongsTo(Cost::class);
    }
}
