<?php

namespace App\Filament\Resources\PlatformAdmins\Pages;

use App\Actions\Tenancy\SetPlatformAdminAction;
use App\Filament\Resources\PlatformAdmins\PlatformAdminResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListPlatformAdmins extends ListRecords
{
    protected static string $resource = PlatformAdminResource::class;

    /**
     * @return array<int, Action|CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('New platform admin'),
            Action::make('promote')
                ->label('Promote existing user')
                ->icon(Heroicon::OutlinedArrowUpCircle)
                ->modalDescription('Grant an existing user back-office access.')
                ->schema([
                    Select::make('user_id')
                        ->label('User')
                        ->required()
                        ->searchable()
                        ->options(fn (): array => User::query()
                            ->where('is_platform_admin', false)
                            ->orderBy('email')
                            ->get()
                            ->mapWithKeys(fn (User $u): array => [$u->getKey() => $u->fullName().' ('.$u->email.')'])
                            ->all()),
                ])
                ->action(function (array $data): void {
                    $user = User::query()->whereKey($data['user_id'])->firstOrFail();
                    app(SetPlatformAdminAction::class)->execute($user, true);
                }),
        ];
    }
}
