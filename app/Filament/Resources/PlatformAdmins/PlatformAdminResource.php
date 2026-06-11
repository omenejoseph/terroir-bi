<?php

namespace App\Filament\Resources\PlatformAdmins;

use App\Actions\Tenancy\SetPlatformAdminAction;
use App\Filament\Resources\PlatformAdmins\Pages\CreatePlatformAdmin;
use App\Filament\Resources\PlatformAdmins\Pages\ListPlatformAdmins;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use UnitEnum;

/**
 * Manage platform admins (super-admins): the only accounts that can sign in to
 * this back office (`User::canAccessPanel` → `is_platform_admin`). Create fresh
 * non-tenant admins or promote/revoke existing users.
 */
class PlatformAdminResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Access';

    protected static ?string $navigationLabel = 'Platform Admins';

    protected static ?string $modelLabel = 'platform admin';

    /** @return Builder<User> */
    public static function getEloquentQuery(): Builder
    {
        return User::query()->where('is_platform_admin', true);
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('first_name')->required()->maxLength(255),
            TextInput::make('last_name')->required()->maxLength(255),
            TextInput::make('email')
                ->email()->required()->maxLength(255)
                ->unique(table: User::class, column: 'email'),
            TextInput::make('password')
                ->password()->required()->rule(Password::min(8))
                ->helperText('They sign in to /admin with this.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Name')->state(fn (User $record): string => $record->fullName()),
                TextColumn::make('email')->searchable()->copyable(),
                TextColumn::make('created_at')->dateTime()->label('Admin since')->sortable(),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Revoke')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription('They will lose access to the back office.')
                    // Never revoke yourself or the last remaining platform admin.
                    ->visible(fn (User $record): bool => $record->getKey() !== Auth::id()
                        && User::query()->where('is_platform_admin', true)->count() > 1)
                    ->action(fn (User $record) => app(SetPlatformAdminAction::class)->execute($record, false)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlatformAdmins::route('/'),
            'create' => CreatePlatformAdmin::route('/create'),
        ];
    }
}
