<?php

namespace App\Filament\Pages;

use App\Actions\Bdd\GrantBddOperationAction;
use App\Actions\Bdd\RevokeBddOperationAction;
use App\Models\BddOperationGrant;
use App\Services\Bdd\CurrentOperator;
use App\Services\Bdd\OperationRegistry;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Throwable;
use UnitEnum;

/**
 * The BDD allowlist manager (fail-closed): built-in seeds/probes are always
 * available; every action class must be granted here (or from a scenario's
 * "Grant requested access" button) before the AI compiler may bind it and the
 * runner may invoke it. Blocklisted namespaces never appear.
 */
class BddAccess extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static string|UnitEnum|null $navigationGroup = 'Quality';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.bdd-access';

    public function getTitle(): string
    {
        return 'BDD test access';
    }

    /**
     * Built-in operations (always available, shown for reference).
     *
     * @return array<int, array<string, mixed>>
     */
    public function builtIns(): array
    {
        $registry = app(OperationRegistry::class);

        return collect($registry->granted())
            ->filter(fn ($spec) => ! $spec->requiresGrant)
            ->map(fn ($spec) => $spec->toArray())
            ->values()
            ->all();
    }

    /**
     * Discoverable action classes with their grant state.
     *
     * @return array<int, array<string, mixed>>
     */
    public function actions(): array
    {
        $registry = app(OperationRegistry::class);
        $granted = BddOperationGrant::query()->pluck('operation_key')->flip();

        return collect($registry->discoverActions())
            ->map(fn ($spec) => $spec->toArray() + ['granted' => isset($granted[$spec->key])])
            ->values()
            ->all();
    }

    public function grant(string $key): void
    {
        try {
            app(GrantBddOperationAction::class)->execute($key, CurrentOperator::id());
        } catch (Throwable $e) {
            Notification::make()->title('Could not grant')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Granted')->body($key)->success()->send();
    }

    public function revoke(string $key): void
    {
        app(RevokeBddOperationAction::class)->execute($key);

        Notification::make()
            ->title('Revoked')
            ->body($key.' — scenarios using it will park as "needs access" on their next run.')
            ->success()
            ->send();
    }
}
