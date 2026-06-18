<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A tasting session grouping several tasting notes.
 *
 * @property string $id
 * @property string $created_by_id
 * @property string|null $title
 * @property Carbon $date
 * @property string|null $note
 */
class TastingReport extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['created_by_id', 'title', 'date', 'note'];

    protected function casts(): array
    {
        return ['date' => 'datetime'];
    }

    /**
     * @return HasMany<CellarTastingNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(CellarTastingNote::class);
    }
}
