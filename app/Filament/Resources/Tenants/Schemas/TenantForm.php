<?php

namespace App\Filament\Resources\Tenants\Schemas;

use App\Enums\TenantStatus;
use App\Queries\ListPlansQuery;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')->required()->maxLength(255)->disabledOn('edit'),
                TextInput::make('slug')->required()->maxLength(255)->visibleOn('create'),
                Select::make('status')
                    ->options(collect(TenantStatus::cases())->mapWithKeys(fn (TenantStatus $s) => [$s->value => $s->name])->all())
                    ->default(TenantStatus::Trial->value)
                    ->required(),
                Select::make('plan_id')
                    ->label('Plan')
                    ->options(fn (): array => app(ListPlansQuery::class)->options())
                    ->searchable()
                    ->placeholder('No plan (unrestricted)'),

                // First-admin + locale, only when provisioning a new tenant.
                Section::make('First admin')
                    ->visibleOn('create')
                    ->columns(2)
                    ->schema([
                        TextInput::make('admin_first_name')->label('First name')->required(),
                        TextInput::make('admin_last_name')->label('Last name')->required(),
                        TextInput::make('admin_email')->label('Email')->email()->required(),
                        TextInput::make('admin_password')->label('Password')->password()->required()->minLength(8),
                        Select::make('currency')
                            ->options(self::currencyOptions())
                            ->default((string) config('money.default', 'EUR'))
                            ->native(false)
                            ->required(),
                        Select::make('locale')
                            ->label('Locale')
                            ->options(self::localeOptions())
                            ->default((string) config('app.locale', 'hr'))
                            ->native(false)
                            ->required(),
                    ]),

                // The live Stripe subscription state lives on the read-only View
                // page (TenantInfolist) — this form only edits what the admin owns.
            ]);
    }

    /**
     * Supported currencies (code → "EUR — Euro") from the money config.
     *
     * @return array<string, string>
     */
    private static function currencyOptions(): array
    {
        $options = [];
        foreach ((array) config('money.currencies', []) as $code => $meta) {
            $name = is_array($meta) && isset($meta['name']) ? (string) $meta['name'] : (string) $code;
            $options[(string) $code] = $code.' — '.$name;
        }

        return $options;
    }

    /**
     * Supported locales (code → label) from the app config.
     *
     * @return array<string, string>
     */
    private static function localeOptions(): array
    {
        $labels = ['hr' => 'Hrvatski (Croatian)', 'en' => 'English'];
        $options = [];
        foreach ((array) config('app.supported_locales', []) as $code) {
            $code = (string) $code;
            $options[$code] = $labels[$code] ?? strtoupper($code);
        }

        return $options;
    }
}
