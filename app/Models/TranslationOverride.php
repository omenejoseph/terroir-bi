<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A per-tenant override of a translation string, keyed by (locale, key). The
 * tenant_id is set automatically by BelongsToTenant.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $locale
 * @property string $key
 * @property string $value
 */
class TranslationOverride extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = [
        'locale',
        'key',
        'value',
    ];
}
