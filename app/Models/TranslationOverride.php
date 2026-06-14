<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A global (platform-wide) override of a translation string, keyed by
 * (locale, key). Managed in the back office.
 *
 * @property string $id
 * @property string $locale
 * @property string $key
 * @property string $value
 */
class TranslationOverride extends Model
{
    use HasUlids;

    protected $fillable = [
        'locale',
        'key',
        'value',
    ];
}
