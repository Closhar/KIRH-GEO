<?php

namespace App\Filament\Pages;

use App\Filament\Support\Administration;
use Illuminate\Support\Facades\DB;

class PartnerProgram extends AdminPage
{
    protected static string $permission = 'admin.partners.manage';

    protected static ?string $title = 'Партнёрская программа';

    protected static ?string $navigationLabel = 'Партнёрская программа';

    protected string $view = 'filament.pages.partner-program';

    public function mount(): void
    {
        $this->authorizeAction();
        $this->data = ['name' => 'Основная', 'commission_kind' => 'percent', 'commission_value' => 10, 'hold_days' => 30,
            'minimum_payout_minor' => 100000, 'attribution_window_days' => 30, 'recurring' => false, 'commission_max_cycles' => 1, 'exclude_promos' => false];
    }

    public function saveDraft(): void
    {
        app(Administration::class)->createPartnerVersion($this->data, $this->reason);
        $this->saved('Создан черновик условий');
    }

    public function loadVersion(string $id): void
    {
        $this->authorizeAction();
        $version = DB::table('partner_program_versions as v')->join('partner_programs as p', 'p.id', '=', 'v.program_id')
            ->where('v.id', $id)->select('v.*', 'p.name')->first();
        abort_unless($version, 404);
        $rules = json_decode($version->rules, true);
        $this->data = ['name' => $version->name, 'commission_kind' => $version->rate_bps !== null ? 'percent' : 'fixed',
            'commission_value' => $version->rate_bps !== null ? $version->rate_bps / 100 : $version->fixed_minor,
            'hold_days' => $version->hold_days, 'minimum_payout_minor' => $version->minimum_payout_minor,
            'attribution_window_days' => $rules['attribution_window_days'] ?? 30, 'recurring' => ($rules['payments'] ?? 'first') === 'recurring',
            'commission_max_cycles' => $rules['commission_max_cycles'] ?? 1, 'exclude_promos' => $rules['exclude_promos'] ?? false];
    }

    public function publish(string $id): void
    {
        app(Administration::class)->publishPartnerVersion($id, $this->reason);
        $this->saved('Условия опубликованы');
    }

    protected function getViewData(): array
    {
        $this->authorizeAction();

        return ['versions' => DB::table('partner_program_versions as v')->join('partner_programs as p', 'p.id', '=', 'v.program_id')
            ->select('v.*', 'p.name')->orderByDesc('v.version')->limit(100)->get()];
    }
}
