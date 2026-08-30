<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * The dashboard, under Qoyod's name for it.
 *
 * Only the wording changes here — لوحة المتابعة is what the sidebar's first
 * entry has said for years in the system this one replaces, and the people
 * reading it should not have to learn a new name for the same screen.
 */
class Dashboard extends BaseDashboard
{
    public static function getNavigationLabel(): string
    {
        return __('company.dashboard');
    }

    public function getTitle(): string
    {
        return __('company.dashboard');
    }
}
