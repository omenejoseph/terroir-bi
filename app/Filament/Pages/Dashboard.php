<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Auth;

/**
 * The platform dashboard. Greets the signed-in admin by name instead of the
 * generic "Dashboard" heading. The sidebar nav label stays "Dashboard".
 */
class Dashboard extends BaseDashboard
{
    public function getHeading(): string|Htmlable
    {
        $firstName = Auth::user()?->first_name;

        return $firstName !== null && $firstName !== '' ? "Welcome {$firstName}" : 'Welcome';
    }
}
