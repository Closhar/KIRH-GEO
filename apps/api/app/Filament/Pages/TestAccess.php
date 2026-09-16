<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Modules\Access\TestAccessService;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

class TestAccess extends AdminPage
{
    protected static string $permission = 'admin.plans.manage';

    protected static ?string $title = 'Тестовый доступ';

    protected static ?string $navigationLabel = 'Тестовый доступ';

    protected string $view = 'filament.pages.test-access';

    public bool $enabled = false;

    #[Locked]
    public int $version = 0;

    public function mount(): void
    {
        $this->authorizeAction();
        $service = app(TestAccessService::class);
        $this->enabled = $service->enabled();
        $this->version = (int) DB::table('application_settings')->where('namespace', 'billing')->where('key', 'test_access_limits')->value('version');
        $limits = $service->limits();
        $this->data = ['features' => []];
        foreach (DB::table('features')->orderBy('key')->get() as $feature) {
            $this->data['features'][$feature->id] = $limits[$feature->id]
                ?? ($feature->value_type === 'boolean' ? false : 0);
        }
    }

    public function save(): void
    {
        app(TestAccessService::class)->save($this->enabled, $this->data['features'] ?? [], $this->reason);
        $this->mount();
        $this->saved('Настройки тестового доступа сохранены');
    }

    protected function getViewData(): array
    {
        $this->authorizeAction();

        return ['features' => DB::table('features')->orderBy('key')->get()];
    }
}
