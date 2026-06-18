<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A reusable fermentation protocol: staged temperature targets and scheduled
 * actions that can be assigned to a lot and turned into dated work orders.
 *
 * @property string $id
 * @property string $name
 * @property string|null $wine_type
 * @property string|null $yeast_strain
 * @property string|null $target_temp_min
 * @property string|null $target_temp_max
 * @property string|null $punchdown_schedule
 * @property string|null $maceration
 * @property string|null $nutrients
 * @property bool $mlf
 * @property string|null $description
 * @property int|null $estimated_duration
 * @property array<int, array<string, mixed>>|null $stages
 * @property bool $is_active
 */
class FermentationTemplate extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'name', 'wine_type', 'yeast_strain', 'target_temp_min', 'target_temp_max',
        'punchdown_schedule', 'maceration', 'nutrients', 'mlf', 'description',
        'estimated_duration', 'stages', 'is_active',
    ];

    protected $attributes = [
        'mlf' => false,
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'target_temp_min' => 'decimal:2',
            'target_temp_max' => 'decimal:2',
            'mlf' => 'boolean',
            'estimated_duration' => 'integer',
            'stages' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
