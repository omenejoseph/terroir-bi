<?php

namespace App\Filament\Pages;

use App\Actions\Notifications\SendBroadcastAction;
use App\Queries\ListTenantOptionsQuery;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Super-admin announcements. Composes a notification and pushes it to every
 * active member of the chosen tenants (or everyone) — it lands in each tenant's
 * in-app bell and as a web push on subscribed devices. No DB/queue work here;
 * the page delegates to SendBroadcastAction.
 */
class Broadcast extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = 'Communications';

    protected string $view = 'filament.pages.broadcast';

    public function getTitle(): string
    {
        return 'Broadcast';
    }

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('compose')
                ->label('Compose announcement')
                ->icon(Heroicon::OutlinedMegaphone)
                ->modalSubmitActionLabel('Send')
                ->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(120),
                    Textarea::make('body')
                        ->label('Message')
                        ->rows(3)
                        ->maxLength(500),
                    Select::make('tenants')
                        ->label('Audience')
                        ->multiple()
                        ->searchable()
                        ->options(fn (): array => app(ListTenantOptionsQuery::class)->options())
                        ->placeholder('All users')
                        ->helperText('Leave empty to send to every tenant.'),
                ])
                ->action(function (array $data): void {
                    /** @var list<string> $tenants */
                    $tenants = array_values(array_map('strval', (array) ($data['tenants'] ?? [])));

                    $result = app(SendBroadcastAction::class)->execute(
                        (string) $data['title'],
                        is_string($data['body'] ?? null) && $data['body'] !== '' ? $data['body'] : null,
                        $tenants === [] ? null : $tenants,
                    );

                    Notification::make()
                        ->title('Announcement sent')
                        ->body("{$result['recipients']} recipients across {$result['tenants']} tenant(s).")
                        ->success()
                        ->send();
                }),
        ];
    }
}
