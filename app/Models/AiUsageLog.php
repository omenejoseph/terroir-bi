<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiCapability;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A local record of one AI call's token usage and estimated cost.
 *
 * NOT tenant-scoped: tenant_id is set explicitly (and may be null for back-
 * office capability tests run with no tenant bound), so this model deliberately
 * avoids the BelongsToTenant auto-fill/guard.
 *
 * @property string|null $tenant_id
 * @property string|null $ai_import_id
 * @property AiCapability $capability
 * @property string|null $feature
 * @property string|null $provider
 * @property string|null $model
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property string|null $cost_usd
 * @property bool $ok
 */
class AiUsageLog extends Model
{
    use HasUlids;

    protected $fillable = [
        'tenant_id', 'ai_import_id', 'capability', 'feature', 'provider', 'model',
        'prompt_tokens', 'completion_tokens', 'cost_usd', 'ok',
    ];

    protected function casts(): array
    {
        return [
            'capability' => AiCapability::class,
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'cost_usd' => 'decimal:6',
            'ok' => 'boolean',
        ];
    }
}
