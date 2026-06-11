<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use App\Queries\ListMostActiveTenantsQuery;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Carbon;

/**
 * Tenant usage leaderboard: who actually works in the product (orders placed
 * in the last 30 days), how many seats they have, and when they last ordered.
 * Quiet rows at the bottom are churn-risk candidates.
 */
class MostActiveTenantsTable extends TableWidget
{
    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Most active tenants')
            ->description('Ranked by orders placed in the last 30 days.')
            ->query(fn () => app(ListMostActiveTenantsQuery::class)->builder(Carbon::now()))
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('plan.name')->label('Plan')->badge()->placeholder('—'),
                TextColumn::make('orders_recent_count')
                    ->label('Orders (30d)')
                    ->badge()
                    ->color(fn (mixed $state): string => ((int) $state) > 0 ? 'primary' : 'gray'),
                TextColumn::make('members_count')->label('Seats'),
                TextColumn::make('last_order_at')
                    ->label('Last order')
                    ->since()
                    ->placeholder('never'),
            ])
            ->recordUrl(fn (Tenant $record): string => TenantResource::getUrl('view', ['record' => $record]))
            ->defaultPaginationPageOption(5)
            ->paginated([5, 10, 25]);
    }
}
