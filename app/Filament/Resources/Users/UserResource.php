<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * Read-only directory of every user account and the tenants attached to them —
 * for visibility / stats. Mutations live elsewhere (tenant Members, Platform
 * Admins). No create / edit / delete here.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Access';

    protected static ?string $navigationLabel = 'Users';

    public static function canCreate(): bool
    {
        return false;
    }

    /** @return Builder<User> */
    public static function getEloquentQuery(): Builder
    {
        return User::query()->withCount('tenants');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('User')
                ->columns(2)
                ->schema([
                    TextEntry::make('name')->state(fn (User $record): string => $record->fullName()),
                    TextEntry::make('email')->copyable(),
                    IconEntry::make('is_platform_admin')->boolean()->label('Platform admin'),
                    TextEntry::make('created_at')->dateTime(),
                ]),
            Section::make('Tenants')
                ->schema([
                    RepeatableEntry::make('memberships')
                        ->hiddenLabel()
                        ->columns(3)
                        ->schema([
                            TextEntry::make('tenant.name')->label('Tenant'),
                            TextEntry::make('roles')->badge(),
                            TextEntry::make('status')->badge(),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Name')->state(fn (User $record): string => $record->fullName()),
                TextColumn::make('email')->searchable()->copyable(),
                IconColumn::make('is_platform_admin')->label('Platform admin')->boolean(),
                TextColumn::make('tenants_count')->label('Tenants')->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'view' => ViewUser::route('/{record}'),
        ];
    }
}
