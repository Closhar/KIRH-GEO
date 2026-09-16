<?php

namespace App\Filament\Pages;

use App\Filament\Support\AdminAccess;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

abstract class AdminPage extends Page
{
    protected static string $permission;

    public string $reason = '';

    public array $data = [];

    public static function canAccess(): bool
    {
        return AdminAccess::allows(auth('web')->user(), 'admin.access')
            && AdminAccess::allows(auth('web')->user(), static::$permission);
    }

    protected function authorizeAction(): void
    {
        AdminAccess::authorize(static::$permission);
    }

    protected function saved(string $title = 'Сохранено'): void
    {
        $this->reason = '';
        Notification::make()->title($title)->success()->send();
    }
}
