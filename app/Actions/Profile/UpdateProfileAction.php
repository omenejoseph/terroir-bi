<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Models\User;
use App\Services\Audit\AuditLogger;

class UpdateProfileAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{first_name: string, middle_name?: ?string, last_name: string}  $attributes
     */
    public function execute(User $user, array $attributes): User
    {
        $user->fill([
            'first_name' => trim($attributes['first_name']),
            'middle_name' => isset($attributes['middle_name']) && trim($attributes['middle_name']) !== '' ? trim($attributes['middle_name']) : null,
            'last_name' => trim($attributes['last_name']),
        ]);

        $changed = array_keys($user->getDirty());
        $user->save();

        if ($changed !== []) {
            $this->audit->record($user, 'user.profile_updated', $user, ['changed' => $changed]);
        }

        return $user;
    }
}
