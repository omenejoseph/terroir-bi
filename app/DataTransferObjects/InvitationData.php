<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\Invitation;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * A pending invitation. The plaintext token is only ever returned at creation
 * time (in $acceptToken) so the caller can build the invite link; it is never
 * persisted or exposed afterwards.
 *
 * @implements Arrayable<string, mixed>
 */
final class InvitationData implements Arrayable, JsonSerializable
{
    /**
     * @param  list<string>  $roles
     */
    public function __construct(
        public readonly string $id,
        public readonly string $email,
        public readonly array $roles,
        public readonly string $expiresAt,
        public readonly bool $pending,
        public readonly ?string $acceptToken = null,
    ) {}

    public static function fromModel(Invitation $invitation, ?string $acceptToken = null): self
    {
        return new self(
            id: $invitation->getKey(),
            email: $invitation->email,
            roles: array_values(array_map(fn ($role) => $role->value, $invitation->roles->all())),
            expiresAt: $invitation->expires_at->toIso8601String(),
            pending: $invitation->isPending(),
            acceptToken: $acceptToken,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $data = [
            'id' => $this->id,
            'email' => $this->email,
            'roles' => $this->roles,
            'expires_at' => $this->expiresAt,
            'pending' => $this->pending,
        ];

        if ($this->acceptToken !== null) {
            $data['accept_token'] = $this->acceptToken;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
