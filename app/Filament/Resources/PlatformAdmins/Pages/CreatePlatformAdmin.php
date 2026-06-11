<?php

namespace App\Filament\Resources\PlatformAdmins\Pages;

use App\Actions\Tenancy\SetPlatformAdminAction;
use App\Filament\Resources\PlatformAdmins\PlatformAdminResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class CreatePlatformAdmin extends CreateRecord
{
    protected static string $resource = PlatformAdminResource::class;

    /**
     * Create the account, then grant the platform-admin flag explicitly
     * (`is_platform_admin` is deliberately not mass-assignable).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $user = User::create([
            'first_name' => (string) $data['first_name'],
            'last_name' => (string) $data['last_name'],
            'email' => (string) $data['email'],
            'password' => Hash::make((string) $data['password']),
        ]);

        return app(SetPlatformAdminAction::class)->execute($user, true);
    }
}
