<?php

namespace App\Filament\Resources\TranslationOverrides\Pages;

use App\Filament\Resources\TranslationOverrides\TranslationOverrideResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTranslationOverride extends EditRecord
{
    protected static string $resource = TranslationOverrideResource::class;

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
