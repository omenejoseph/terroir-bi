<?php

namespace App\Filament\Resources\Plans\RelationManagers;

use App\Filament\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * The tenants subscribed to this plan. Read-only — a tenant's plan is set on the
 * tenant (Edit / billing), not here.
 */
class TenantsRelationManager extends RelationManager
{
    protected static string $relationship = 'tenants';

    protected static ?string $title = 'Tenants';

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug'),
                TextColumn::make('status')->badge(),
                TextColumn::make('subscription.stripe_status')->label('Stripe')->placeholder('—'),
            ])
            ->recordActions([
                Action::make('open')
                    ->label('Open')
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (Tenant $record): string => TenantResource::getUrl('view', ['record' => $record])),
            ]);
    }
}
