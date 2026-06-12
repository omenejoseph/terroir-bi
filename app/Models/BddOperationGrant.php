<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry in the explicit BDD operation allowlist (fail-closed): an action
 * class the compiler may bind and the runner may invoke, e.g.
 * "action:App\Actions\Orders\CreateOrderAction". Built-in seeds/probes are
 * always available and are NOT stored here; blocklisted namespaces can never
 * be granted (enforced by OperationRegistry, not by this model).
 *
 * @property string $id
 * @property string $operation_key
 * @property string|null $granted_by_id
 * @property string|null $note
 */
class BddOperationGrant extends Model
{
    use HasUlids;

    protected $fillable = [
        'operation_key',
        'granted_by_id',
        'note',
    ];
}
