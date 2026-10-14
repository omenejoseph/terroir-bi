<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DoseBasis;
use App\Services\Audit\Auditable;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A structured additive dose within a fermentation protocol.
 *
 * @property string $id
 * @property string $fermentation_template_id
 * @property string|null $enological_product_id
 * @property string $name
 * @property string $dose
 * @property DoseBasis $basis
 * @property string|null $stage
 * @property string|null $notes
 * @property int $sort_order
 */
class ProtocolAdditive extends Model
{
    use Auditable;
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'fermentation_template_id', 'enological_product_id', 'name', 'dose', 'basis', 'stage', 'notes', 'sort_order',
    ];

    protected $attributes = ['sort_order' => 0];

    protected function casts(): array
    {
        return [
            'dose' => 'decimal:4',
            'basis' => DoseBasis::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<FermentationTemplate, $this>
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(FermentationTemplate::class, 'fermentation_template_id');
    }

    /**
     * @return BelongsTo<EnologicalProduct, $this>
     */
    public function enologicalProduct(): BelongsTo
    {
        return $this->belongsTo(EnologicalProduct::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function auditCreateSnapshot(Model $model): array
    {
        return [
            'name' => $model->getAttribute('name'),
            'dose' => (string) $model->getAttribute('dose'),
            'basis' => $model->getAttribute('basis')?->value,
        ];
    }
}
