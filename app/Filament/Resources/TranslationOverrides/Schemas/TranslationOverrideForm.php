<?php

namespace App\Filament\Resources\TranslationOverrides\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class TranslationOverrideForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('locale')
                    ->options(['hr' => 'Hrvatski', 'en' => 'English'])
                    ->default('hr')
                    ->required(),
                TextInput::make('key')
                    ->required()
                    ->maxLength(255)
                    ->helperText('The bundled string to override (e.g. dashboard.welcome, or a JSON source string).'),
                Textarea::make('value')
                    ->required()
                    ->rows(3),
            ]);
    }
}
