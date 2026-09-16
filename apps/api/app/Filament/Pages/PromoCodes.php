<?php

namespace App\Filament\Pages;

use App\Filament\Support\Administration;
use Illuminate\Support\Facades\DB;

class PromoCodes extends AdminPage
{
    protected static string $permission = 'admin.promos.manage';

    protected static ?string $title = 'Промокоды';

    protected static ?string $navigationLabel = 'Промокоды';

    protected string $view = 'filament.pages.promo-codes';

    public function mount(): void
    {
        $this->authorizeAction();
        $this->data = ['code' => '', 'label' => '', 'starts_at' => now()->format('Y-m-d\TH:i'), 'ends_at' => now()->addMonth()->format('Y-m-d\TH:i'),
            'max_uses' => 100, 'per_user_limit' => 1, 'per_workspace_limit' => 1, 'type' => 'free_access', 'plan_id' => null,
            'duration_days' => 30, 'months' => 1, 'discount_kind' => 'percent', 'discount_value' => 10, 'cycles' => 1];
    }

    public function save(): void
    {
        app(Administration::class)->createPromo($this->data, $this->reason);
        $this->data['code'] = '';
        $this->saved('Промокод создан. Сохраните исходный код: в базе хранится только хеш.');
    }

    public function deactivate(string $id): void
    {
        app(Administration::class)->deactivatePromo($id, $this->reason);
        $this->saved();
    }

    protected function getViewData(): array
    {
        $this->authorizeAction();

        return ['promos' => DB::table('promo_codes')->select('id', 'label', 'uses', 'max_uses', 'active', 'ends_at')->orderByDesc('created_at')->limit(100)->get(),
            'plans' => DB::table('plans')->where('status', 'published')->get()];
    }
}
