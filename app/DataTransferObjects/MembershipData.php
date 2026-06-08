<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use RuntimeException;

/**
 * A tenant member (the user + their roles/status in the current tenant).
 *
 * @implements Arrayable<string, mixed>
 */
final class MembershipData implements Arrayable, JsonSerializable
{
    /**
     * @param  list<string>  $roles
     */
    public function __construct(
        public readonly string $membershipId,
        public readonly string $userId,
        public readonly string $name,
        public readonly string $email,
        public readonly array $roles,
        public readonly string $status,
    ) {}

    public static function fromModel(Membership $membership): self
    {
        $user = $membership->user;

        if (! $user instanceof User) {
            throw new RuntimeException('Membership is missing its user relation.');
        }

        return new self(
            membershipId: $membership->getKey(),
            userId: $membership->user_id,
            name: $user->fullName(),
            email: $user->email,
            roles: array_values(array_map(fn ($role) => $role->value, $membership->roles->all())),
            status: $membership->status->value,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->membershipId,
            'user_id' => $this->userId,
            'name' => $this->name,
            'email' => $this->email,
            'roles' => $this->roles,
            'status' => $this->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
