<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A global (not tenant-scoped) key-value setting managed from the back office.
 * Read/written through App\Support\GlobalSettings, which caches lookups.
 *
 * @property string $key
 * @property mixed $value
 */
class GlobalSetting extends Model
{
    use HasUlids;

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return [
            'value' => 'array',
        ];
    }
}
