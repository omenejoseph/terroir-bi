<?php

namespace App\Filament\Resources\Tenants\RelationManagers;

use App\Actions\Tenancy\AddTenantMemberAction;
use App\Enums\MembershipStatus;
use App\Enums\TenantRole;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Password;

/**
 * The users who are members of this tenant. "Add member" provisions a fresh user
 * account (a new login) and grants it the chosen tenant roles.
 */
class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'memberships';

    protected static ?string $title = 'Members';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('id')
            ->columns([
                TextColumn::make('user_name')
                    ->label('Name')
                    ->state(fn (Membership $record): string => $record->user?->fullName() ?? '—'),
                TextColumn::make('user.email')->label('Email')->searchable(),
                TextColumn::make('roles')->badge(),
                TextColumn::make('status')->badge(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Add member')
                    ->modalHeading('Add a member')
                    ->schema([
                        TextInput::make('first_name')->required()->maxLength(255),
                        TextInput::make('last_name')->required()->maxLength(255),
                        TextInput::make('email')
                            ->email()->required()->maxLength(255)
                            ->unique(table: User::class, column: 'email'),
                        TextInput::make('password')
                            ->password()->required()
                            ->rule(Password::min(8))
                            ->helperText('The new user signs in to the tenant app with this.'),
                        self::rolesField(),
                        self::statusField(),
                    ])
                    ->using(function (array $data): Membership {
                        $tenant = $this->getOwnerRecord();
                        if (! $tenant instanceof Tenant) {
                            throw new \LogicException('The members relation manager requires a Tenant owner.');
                        }

                        return app(AddTenantMemberAction::class)->execute($tenant, $data);
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->schema([self::rolesField(), self::statusField()]),
                DeleteAction::make(),
            ]);
    }

    private static function rolesField(): CheckboxList
    {
        return CheckboxList::make('roles')
            ->options(collect(TenantRole::cases())->mapWithKeys(fn (TenantRole $r) => [$r->value => $r->label()])->all())
            ->columns(2)
            ->default([TenantRole::Admin->value])
            ->formatStateUsing(fn (mixed $state): array => collect(is_iterable($state) ? $state : [])
                ->map(fn (mixed $r): string => $r instanceof TenantRole ? $r->value : (string) $r)->values()->all());
    }

    private static function statusField(): Select
    {
        return Select::make('status')
            ->options(collect(MembershipStatus::cases())->mapWithKeys(fn (MembershipStatus $s) => [$s->value => ucfirst($s->value)])->all())
            ->default(MembershipStatus::Active->value)
            ->required();
    }
}
