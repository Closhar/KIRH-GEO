<?php

namespace App\Filament\Pages;

use App\Filament\Support\Administration;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;

class Tariffs extends AdminPage
{
    protected static string $permission = 'admin.plans.manage';

    protected static ?string $title = 'Тарифы и лимиты';

    protected static ?string $navigationLabel = 'Тарифы и лимиты';

    protected string $view = 'filament.pages.tariffs';

    public int $trialDays = 0;

    public ?string $trialPlanId = null;

    #[Locked]
    public int $trialVersion = 0;

    public function mount(): void
    {
        $this->authorizeAction();
        $this->data = ['code' => '', 'name' => '', 'provider' => 'sandbox', 'month_minor' => 0, 'year_minor' => 0, 'features' => []];
        foreach (DB::table('features')->get() as $feature) {
            $this->data['features'][$feature->id] = $feature->value_type === 'boolean' ? false : 0;
        }
        $trial = DB::table('application_settings')->where('namespace', 'billing')->where('key', 'trial_days')->first();
        $this->trialDays = (int) json_decode($trial->value ?? json_encode(config('billing.trial_days', 0)));
        $this->trialVersion = (int) ($trial->version ?? 0);
        $trialPlan = DB::table('application_settings')->where('namespace', 'billing')->where('key', 'trial_plan_id')->value('value');
        $this->trialPlanId = $trialPlan ? json_decode($trialPlan) : config('billing.trial_plan_id');
    }

    public function loadPlan(string $id): void
    {
        $this->authorizeAction();
        $this->mount();
        $plan = DB::table('plans')->where('id', $id)->first();
        abort_unless($plan, 404);
        $this->data['code'] = $plan->code;
        $this->data['name'] = $plan->name;
        foreach (DB::table('plan_features')->where('plan_id', $id)->get() as $feature) {
            $this->data['features'][$feature->feature_id] = json_decode($feature->value);
        }
        foreach (DB::table('plan_prices')->where('plan_id', $id)->get() as $price) {
            $this->data[$price->interval.'_minor'] = $price->amount_minor;
            $this->data['provider'] = $price->provider;
        }
    }

    public function saveDraft(): void
    {
        app(Administration::class)->createPlanDraft($this->data, $this->reason);
        $this->saved('Создана новая версия-черновик');
    }

    public function publish(string $id): void
    {
        app(Administration::class)->publishPlan($id, $this->reason);
        $this->saved('Тариф опубликован');
    }

    public function saveTrial(): void
    {
        app(Administration::class)->saveTrial($this->trialDays, $this->trialVersion, $this->reason, $this->trialPlanId);
        $this->trialVersion++;
        $this->saved();
    }

    protected function getViewData(): array
    {
        $this->authorizeAction();

        return ['plans' => DB::table('plans')->orderByDesc('created_at')->limit(100)->get(), 'features' => DB::table('features')->orderBy('key')->get()];
    }
}
