<?php

namespace App\Filament\Resources\TranslationOverrides;

use App\Filament\Resources\TranslationOverrides\Pages\CreateTranslationOverride;
use App\Filament\Resources\TranslationOverrides\Pages\EditTranslationOverride;
use App\Filament\Resources\TranslationOverrides\Pages\ListTranslationOverrides;
use App\Filament\Resources\TranslationOverrides\Schemas\TranslationOverrideForm;
use App\Filament\Resources\TranslationOverrides\Tables\TranslationOverridesTable;
use App\Models\TranslationOverride;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TranslationOverrideResource extends Resource
{
    protected static ?string $model = TranslationOverride::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static string|UnitEnum|null $navigationGroup = 'Localization';

    protected static ?string $recordTitleAttribute = 'key';

    protected static ?string $modelLabel = 'translation';

    public static function form(Schema $schema): Schema
    {
        return TranslationOverrideForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TranslationOverridesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTranslationOverrides::route('/'),
            'create' => CreateTranslationOverride::route('/create'),
            'edit' => EditTranslationOverride::route('/{record}/edit'),
        ];
    }
}
