<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiImportLineStatus;
use App\Enums\AiTargetType;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI-proposed record awaiting review. The committed value is the user's
 * `edited_payload` when present, otherwise the AI's `payload`.
 *
 * @property string $id
 * @property string $ai_import_id
 * @property int $index
 * @property AiTargetType $target_type
 * @property array<string, mixed> $payload
 * @property array<string, mixed>|null $edited_payload
 * @property string|null $category
 * @property float|null $confidence
 * @property AiImportLineStatus $status
 * @property string|null $committed_id
 */
class AiImportLine extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'ai_import_id', 'index', 'target_type', 'payload', 'edited_payload',
        'category', 'confidence', 'status', 'committed_id',
    ];

    protected $attributes = [
        'status' => 'pending',
    ];

    protected function casts(): array
    {
        return [
            'target_type' => AiTargetType::class,
            'payload' => 'array',
            'edited_payload' => 'array',
            'index' => 'integer',
            'confidence' => 'float',
            'status' => AiImportLineStatus::class,
        ];
    }

    /**
     * The payload to commit: user corrections win over the AI proposal.
     *
     * @return array<string, mixed>
     */
    public function effectivePayload(): array
    {
        return $this->edited_payload ?? $this->payload;
    }

    /**
     * @return BelongsTo<AiImport, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(AiImport::class, 'ai_import_id');
    }
}
