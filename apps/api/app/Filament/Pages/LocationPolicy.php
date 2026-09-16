<?php

namespace App\Filament\Pages;

use App\Filament\Support\Administration;
use App\Modules\Access\LocationSettings;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

class LocationPolicy extends AdminPage
{
    protected static string $permission = 'admin.location.manage';

    protected static ?string $title = 'Геолокация и хранение';

    protected static ?string $navigationLabel = 'Геолокация и хранение';

    protected string $view = 'filament.pages.location-policy';

    #[Locked]
    public int $version = 0;

    public function mount(): void
    {
        $this->authorizeAction();
        $this->data = app(LocationSettings::class)->raw();
        $this->version = (int) DB::table('application_settings')->where('namespace', 'location')->where('key', 'policy')->value('version');
    }

    public function save(): void
    {
        app(Administration::class)->saveLocation($this->data, $this->version, $this->reason);
        $this->mount();
        $this->saved();
    }
}
