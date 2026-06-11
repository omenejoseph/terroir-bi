<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Tenants\TenantResource;
use App\Models\Tenant;
use App\Queries\ListTenantsNeedingAttentionQuery;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Carbon;

/**
 * The admin's daily worklist: tenants whose Stripe billing is failing or whose
 * trial ends within two weeks. Rows link to the tenant view, where the billing
 * actions live.
 */
class TenantsNeedingAttentionTable extends TableWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Needs attention')
            ->description('Failing payments and trials ending within 14 days.')
            ->query(fn () => app(ListTenantsNeedingAttentionQuery::class)->builder(Carbon::now()))
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('plan.name')->label('Plan')->badge()->placeholder('—'),
                TextColumn::make('subscription.stripe_status')
                    ->label('Stripe status')
                    ->badge()
                    ->color(fn (?string $state): string => in_array($state, ['past_due', 'unpaid'], true) ? 'danger' : 'warning')
                    ->placeholder('—'),
                TextColumn::make('subscription.trial_ends_at')
                    ->label('Trial ends')
                    ->dateTime()
                    ->placeholder('—'),
                TextColumn::make('subscription.current_period_end')
                    ->label('Period ends')
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->recordUrl(fn (Tenant $record): string => TenantResource::getUrl('view', ['record' => $record]))
            ->emptyStateHeading('All clear')
            ->emptyStateDescription('No failing payments or expiring trials right now.')
            ->paginated(false);
    }
}
