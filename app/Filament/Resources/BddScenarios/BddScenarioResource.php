<?php

namespace App\Filament\Resources\BddScenarios;

use App\Filament\Resources\BddScenarios\Pages\CreateBddScenario;
use App\Filament\Resources\BddScenarios\Pages\EditBddScenario;
use App\Filament\Resources\BddScenarios\Pages\ListBddScenarios;
use App\Filament\Resources\BddScenarios\Pages\ViewBddScenario;
use App\Filament\Resources\BddScenarios\Schemas\BddScenarioForm;
use App\Filament\Resources\BddScenarios\Schemas\BddScenarioInfolist;
use App\Filament\Resources\BddScenarios\Tables\BddScenariosTable;
use App\Models\BddScenario;
use App\Queries\Bdd\ListBddScenariosQuery;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class BddScenarioResource extends Resource
{
    protected static ?string $model = BddScenario::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static string|UnitEnum|null $navigationGroup = 'Quality';

    protected static ?string $modelLabel = 'BDD scenario';

    protected static ?string $pluralModelLabel = 'BDD scenarios';

    /**
     * Reads go through a Query class — no DB query is built in the resource.
     *
     * @return Builder<BddScenario>
     */
    public static function getEloquentQuery(): Builder
    {
        return app(ListBddScenariosQuery::class)->builder();
    }

    public static function form(Schema $schema): Schema
    {
        return BddScenarioForm::configure($schema);
    }

    /** The read-only view — the default click target from the table. */
    public static function infolist(Schema $schema): Schema
    {
        return BddScenarioInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BddScenariosTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListBddScenarios::route('/'),
            'create' => CreateBddScenario::route('/create'),
            'view' => ViewBddScenario::route('/{record}'),
            'edit' => EditBddScenario::route('/{record}/edit'),
        ];
    }
}
