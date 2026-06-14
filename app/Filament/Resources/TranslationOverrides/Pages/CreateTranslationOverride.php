<?php

namespace App\Filament\Resources\TranslationOverrides\Pages;

use App\Filament\Resources\TranslationOverrides\TranslationOverrideResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTranslationOverride extends CreateRecord
{
    protected static string $resource = TranslationOverrideResource::class;

    /** Back to the list after creating, not into the edit form. */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
