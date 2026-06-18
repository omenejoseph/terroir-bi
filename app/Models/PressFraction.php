<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PressFractionType;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $harvest_entry_id
 * @property PressFractionType $fraction_type
 * @property string $volume_liters
 */
class PressFraction extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['harvest_entry_id', 'wine_lot_id', 'vessel_id', 'fraction_type', 'volume_liters', 'yield_percent', 'press_program', 'pressure_bar', 'note'];

    protected function casts(): array
    {
        return [
            'fraction_type' => PressFractionType::class,
            'volume_liters' => 'decimal:3',
            'yield_percent' => 'decimal:2',
            'pressure_bar' => 'decimal:2',
        ];
    }
}
