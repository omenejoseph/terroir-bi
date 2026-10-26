<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A free-form block of the villa's guest portal ("Beaches nearby", "How the heating works").
 *
 * @property string $id
 * @property string $villa_id
 * @property string $title
 * @property string|null $icon
 * @property string|null $content
 * @property int $sort_order
 * @property bool $is_active
 */
class VillaSection extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['villa_id', 'title', 'icon', 'content', 'sort_order', 'is_active'];

    protected $attributes = ['sort_order' => 0, 'is_active' => true];

    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }
}
