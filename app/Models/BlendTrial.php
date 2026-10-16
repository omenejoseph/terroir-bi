<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Audit\Auditable;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A saved blend trial from the blend workbench.
 *
 * @property string $id
 * @property string $name
 * @property list<array{wine_lot_id: string, ratio: float}> $components
 * @property string|null $target_volume
 * @property string|null $destination_vessel_id
 * @property array<string, mixed>|null $projection
 * @property string $created_by_id
 * @property Carbon|null $created_at
 */
class BlendTrial extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['name', 'components', 'target_volume', 'destination_vessel_id', 'projection', 'created_by_id'];

    protected function casts(): array
    {
        return ['components' => 'array', 'projection' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return array<string, mixed>
     */
    protected static function auditCreateSnapshot(Model $model): array
    {
        return ['name' => $model->getAttribute('name'), 'components' => $model->getAttribute('components')];
    }

    /** @return array<string, mixed> */
    public function toListArray(): array
    {
        return [
            'id' => (string) $this->getKey(),
            'name' => $this->name,
            'components' => $this->components,
            'target_volume' => $this->target_volume,
            'destination_vessel_id' => $this->destination_vessel_id,
            'projection' => $this->projection,
            'created_at' => $this->created_at?->toIso8601String(),
            'created_by' => $this->createdBy?->fullName(),
        ];
    }
}
