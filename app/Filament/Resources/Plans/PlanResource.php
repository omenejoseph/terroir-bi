<?php

namespace App\Filament\Resources\Plans;

use App\Filament\Resources\Plans\Pages\CreatePlan;
use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Plans\Pages\ListPlans;
use App\Filament\Resources\Plans\Pages\ViewPlan;
use App\Filament\Resources\Plans\Schemas\PlanForm;
use App\Filament\Resources\Plans\Schemas\PlanInfolist;
use App\Filament\Resources\Plans\Tables\PlansTable;
use App\Models\Plan;
use App\Queries\ListPlansQuery;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    /**
     * All reads go through a Query class — no DB query is built in the resource.
     *
     * @return Builder<Plan>
     */
    public static function getEloquentQuery(): Builder
    {
        return app(ListPlansQuery::class)->builder();
    }

    public static function form(Schema $schema): Schema
    {
        return PlanForm::configure($schema);
    }

    /** The read-only view — the default click target from the table. */
    public static function infolist(Schema $schema): Schema
    {
        return PlanInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PlansTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'create' => CreatePlan::route('/create'),
            'view' => ViewPlan::route('/{record}'),
            'edit' => EditPlan::route('/{record}/edit'),
        ];
    }
}
