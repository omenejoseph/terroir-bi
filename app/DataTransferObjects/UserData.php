<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use App\Models\User;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final class UserData implements Arrayable, JsonSerializable
{
    public function __construct(
        public readonly string $id,
        public readonly string $firstName,
        public readonly ?string $middleName,
        public readonly string $lastName,
        public readonly string $email,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(
            id: $user->getKey(),
            firstName: $user->first_name,
            middleName: $user->middle_name,
            lastName: $user->last_name,
            email: $user->email,
        );
    }

    public function fullName(): string
    {
        return implode(' ', array_filter([$this->firstName, $this->middleName, $this->lastName]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->firstName,
            'middle_name' => $this->middleName,
            'last_name' => $this->lastName,
            'name' => $this->fullName(),
            'email' => $this->email,
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
