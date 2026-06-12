<?php

namespace App\Filament\Resources\BddScenarios\Tables;

use App\Enums\BddRunStatus;
use App\Enums\BddScenarioStatus;
use App\Filament\Resources\BddScenarios\Actions\ScenarioActions;
use App\Filament\Resources\BddScenarios\BddScenarioResource;
use App\Models\BddScenario;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class BddScenariosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')->searchable()->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (BddScenarioStatus $state): string => match ($state) {
                        BddScenarioStatus::Ready => 'success',
                        BddScenarioStatus::NeedsAccess => 'warning',
                        BddScenarioStatus::CompileFailed => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('last_run_status')
                    ->label('Last run')
                    ->badge()
                    ->placeholder('— never —')
                    ->color(fn (?BddRunStatus $state): string => match ($state) {
                        BddRunStatus::Pass => 'success',
                        BddRunStatus::Fail, BddRunStatus::Error => 'danger',
                        BddRunStatus::NeedsAccess => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('last_run_at')->since()->placeholder('—'),
                IconColumn::make('is_active')->label('Active')->boolean(),
            ])
            ->recordUrl(fn (BddScenario $record): string => BddScenarioResource::getUrl('view', ['record' => $record]))
            ->recordActions([
                ScenarioActions::run(),
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                    ScenarioActions::grantRequested(),
                    DeleteAction::make(),
                ]),
            ]);
    }
}
