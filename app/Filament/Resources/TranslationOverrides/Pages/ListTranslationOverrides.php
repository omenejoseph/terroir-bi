<?php

namespace App\Filament\Resources\TranslationOverrides\Pages;

use App\Filament\Resources\TranslationOverrides\TranslationOverrideResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTranslationOverrides extends ListRecords
{
    protected static string $resource = TranslationOverrideResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
