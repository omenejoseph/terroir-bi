<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiImportStatus;
use App\Enums\AiImportType;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An AI data-entry batch: one uploaded document whose extracted lines are
 * reviewed line-by-line and committed into real records.
 *
 * @property string $id
 * @property AiImportType $type
 * @property AiImportStatus $status
 * @property string|null $source_object_key
 * @property string|null $source_filename
 * @property string|null $source_mime
 * @property string|null $provider
 * @property string|null $model
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property string|null $cost_usd
 * @property string|null $error
 * @property string $created_by_id
 * @property Carbon|null $created_at
 */
class AiImport extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'type', 'status', 'source_object_key', 'source_filename', 'source_mime',
        'provider', 'model', 'prompt_tokens', 'completion_tokens', 'cost_usd',
        'error', 'created_by_id',
    ];

    protected $attributes = [
        'status' => 'uploaded',
    ];

    protected function casts(): array
    {
        return [
            'type' => AiImportType::class,
            'status' => AiImportStatus::class,
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'cost_usd' => 'decimal:6',
        ];
    }

    /**
     * @return HasMany<AiImportLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(AiImportLine::class)->orderBy('index');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
