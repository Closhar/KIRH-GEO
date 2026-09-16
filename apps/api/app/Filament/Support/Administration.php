<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class Administration
{
    public function saveLocation(array $data, int $version, string $reason): void
    {
        AdminAccess::authorize('admin.location.manage');
        $rules = [
            'history_max_days' => 'required|integer|min:1|max:3650',
            'history_default_days' => 'required|integer|min:0|lte:history_max_days',
            'batch_max_points' => 'required|integer|min:1|max:500',
            'batch_max_bytes' => 'required|integer|min:1024|max:1048576',
            'offline_max_hours' => 'required|integer|min:1|max:168',
            'future_tolerance_seconds' => 'required|integer|min:0|max:300',
            'current_ttl_seconds' => 'required|integer|min:60|max:604800',
            'realtime_max_age_seconds' => 'required|integer|min:10|max:3600',
        ];
        foreach (['idle', 'normal', 'live', 'sport', 'sos'] as $mode) {
            $rules["modes.$mode.capture_seconds"] = 'required|integer|min:1|max:3600';
            $rules["modes.$mode.upload_seconds"] = "required|integer|min:1|max:3600|gte:modes.$mode.capture_seconds";
        }
        $clean = Validator::make($data, $rules)->validate();
        array_walk_recursive($clean, static function (&$value): void {
            $value = (int) $value;
        });
        $this->reason($reason);
        DB::transaction(function () use ($clean, $version, $reason): void {
            $record = DB::table('application_settings')->where('namespace', 'location')->where('key', 'policy')->lockForUpdate()->first();
            if ($record === null || (int) $record->version !== $version) {
                throw ValidationException::withMessages(['data' => 'Настройки изменились. Обновите страницу.']);
            }
            DB::table('application_settings')->where('namespace', 'location')->where('key', 'policy')->update([
                'value' => json_encode($clean, JSON_THROW_ON_ERROR), 'version' => $version + 1,
                'updated_by' => auth('web')->id(), 'updated_at' => now(),
            ]);
            AdminAccess::audit('location.settings.updated', 'application_settings', null, $reason, ['version' => $version + 1, 'policy' => $clean]);
        });
    }

    public function createPlanDraft(array $data, string $reason): string
    {
        AdminAccess::authorize('admin.plans.manage');
        $clean = Validator::make($data, [
            'code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,49}$/'],
            'name' => 'required|string|max:100', 'month_minor' => 'required|integer|min:0|max:100000000',
            'year_minor' => 'required|integer|min:0|max:100000000',
            'provider' => ['required', Rule::in(['sandbox', 'yookassa'])], 'features' => 'required|array',
        ])->validate();
        $this->reason($reason);
        $features = DB::table('features')->get();
        $values = [];
        foreach ($features as $feature) {
            $value = $clean['features'][$feature->id] ?? null;
            Validator::make(['value' => $value], ['value' => $feature->value_type === 'boolean' ? 'required|boolean' : 'required|integer|min:0|max:1000000'])->validate();
            $values[$feature->id] = $feature->value_type === 'boolean' ? (bool) $value : (int) $value;
        }

        return DB::transaction(function () use ($clean, $values, $reason): string {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['plan:'.$clean['code']]);
            $version = (int) DB::table('plans')->where('code', $clean['code'])->max('version') + 1;
            $id = (string) Str::uuid();
            DB::table('plans')->insert(['id' => $id, 'code' => $clean['code'], 'version' => $version, 'name' => $clean['name'], 'status' => 'draft']);
            foreach ($values as $featureId => $value) {
                DB::table('plan_features')->insert(['plan_id' => $id, 'feature_id' => $featureId, 'value' => json_encode($value)]);
            }
            foreach (['month', 'year'] as $interval) {
                DB::table('plan_prices')->insert(['plan_id' => $id, 'interval' => $interval, 'currency' => 'RUB', 'amount_minor' => $clean[$interval.'_minor'], 'provider' => $clean['provider']]);
            }
            AdminAccess::audit('plan.draft.created', 'plans', $id, $reason, ['code' => $clean['code'], 'version' => $version]);

            return $id;
        });
    }

    public function publishPlan(string $id, string $reason): void
    {
        AdminAccess::authorize('admin.plans.manage');
        $this->reason($reason);
        DB::transaction(function () use ($id, $reason): void {
            $plan = DB::table('plans')->where('id', $id)->lockForUpdate()->first();
            abort_unless($plan && $plan->status === 'draft', 409, 'Only a draft can be published.');
            abort_unless(DB::table('plan_prices')->where('plan_id', $id)->count() === 2, 422);
            DB::table('plans')->where('id', $id)->update(['status' => 'published', 'published_at' => now()]);
            AdminAccess::audit('plan.published', 'plans', $id, $reason);
        });
    }

    public function saveTrial(int $days, int $version, string $reason, ?string $planId = null): void
    {
        AdminAccess::authorize('admin.plans.manage');
        Validator::make(['days' => $days], ['days' => 'required|integer|min:0|max:90'])->validate();
        $this->reason($reason);
        Validator::make(['plan_id' => $planId], ['plan_id' => [$days > 0 ? 'required' : 'nullable', Rule::exists('plans', 'id')->where('status', 'published')]])->validate();
        if ($days > 0 && ! DB::table('plan_prices')->where('plan_id', $planId)->where('interval', 'month')->where('active', true)->exists()) {
            throw ValidationException::withMessages(['trialPlanId' => 'У тарифа должна быть активная месячная цена.']);
        }
        DB::transaction(function () use ($days, $version, $reason, $planId): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['setting:billing:trial_days']);
            $record = DB::table('application_settings')->where('namespace', 'billing')->where('key', 'trial_days')->first();
            if ((int) ($record->version ?? 0) !== $version) {
                throw ValidationException::withMessages(['trialDays' => 'Настройки изменились. Обновите страницу.']);
            }
            DB::table('application_settings')->updateOrInsert(['namespace' => 'billing', 'key' => 'trial_days'], [
                'value' => json_encode($days), 'version' => $version + 1, 'updated_by' => auth('web')->id(), 'updated_at' => now(),
            ]);
            DB::table('application_settings')->updateOrInsert(['namespace' => 'billing', 'key' => 'trial_plan_id'], [
                'value' => json_encode($planId), 'version' => $version + 1, 'updated_by' => auth('web')->id(), 'updated_at' => now(),
            ]);
            AdminAccess::audit('billing.trial.updated', 'application_settings', null, $reason, ['days' => $days, 'plan_id' => $planId]);
        });
    }

    public function createPromo(array $data, string $reason): string
    {
        AdminAccess::authorize('admin.promos.manage');
        $clean = Validator::make($data, [
            'code' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{4,64}$/'], 'label' => 'required|string|max:100',
            'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at',
            'max_uses' => 'required|integer|min:1|max:1000000',
            'per_user_limit' => 'required|integer|min:1|max:100', 'per_workspace_limit' => 'required|integer|min:1|max:100',
            'type' => ['required', Rule::in(['free_access', 'discount', 'free_months'])],
            'plan_id' => ['nullable', 'required_if:type,free_access', Rule::exists('plans', 'id')->where('status', 'published')],
            'duration_days' => 'nullable|required_if:type,free_access|integer|min:1|max:365',
            'months' => 'nullable|required_if:type,free_months|integer|min:1|max:24',
            'discount_kind' => ['nullable', 'required_if:type,discount', Rule::in(['percent', 'fixed'])],
            'discount_value' => 'nullable|required_if:type,discount|integer|min:1|max:100000000',
            'cycles' => 'required|integer|min:1|max:24',
        ])->validate();
        $this->reason($reason);
        if ($clean['type'] === 'discount' && $clean['discount_kind'] === 'percent' && $clean['discount_value'] > 100) {
            throw ValidationException::withMessages(['data.discount_value' => 'Процент должен быть от 1 до 100.']);
        }

        return DB::transaction(function () use ($clean, $reason): string {
            $hash = hash('sha256', strtoupper(trim($clean['code'])));
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['promo:'.$hash]);
            if (DB::table('promo_codes')->where('code_hash', $hash)->exists()) {
                throw ValidationException::withMessages(['data.code' => 'Такой код уже существует.']);
            }
            $id = (string) Str::uuid();
            DB::table('promo_codes')->insert([
                'id' => $id, 'code_hash' => $hash, 'label' => $clean['label'], 'starts_at' => $clean['starts_at'],
                'ends_at' => $clean['ends_at'], 'max_uses' => $clean['max_uses'],
                'per_user_limit' => $clean['per_user_limit'], 'per_workspace_limit' => $clean['per_workspace_limit'],
            ]);
            $benefit = ['promo_code_id' => $id, 'type' => $clean['type'], 'cycles' => $clean['cycles']];
            if ($clean['type'] === 'free_access') {
                $benefit += ['plan_id' => $clean['plan_id'], 'duration_days' => $clean['duration_days']];
            } elseif ($clean['type'] === 'free_months') {
                $benefit += ['months' => $clean['months'], 'plan_id' => $clean['plan_id'] ?? null];
            } else {
                $benefit += $clean['discount_kind'] === 'percent'
                    ? ['discount_bps' => $clean['discount_value'] * 100]
                    : ['amount_minor' => $clean['discount_value'], 'currency' => 'RUB'];
            }
            DB::table('promo_benefits')->insert($benefit);
            AdminAccess::audit('promo.created', 'promo_codes', $id, $reason, ['type' => $clean['type']]);

            return $id;
        });
    }

    public function deactivatePromo(string $id, string $reason): void
    {
        AdminAccess::authorize('admin.promos.manage');
        $this->reason($reason);
        DB::transaction(function () use ($id, $reason): void {
            abort_unless(DB::table('promo_codes')->where('id', $id)->update(['active' => false]), 404);
            AdminAccess::audit('promo.deactivated', 'promo_codes', $id, $reason);
        });
    }

    public function createPartnerVersion(array $data, string $reason): string
    {
        AdminAccess::authorize('admin.partners.manage');
        $clean = Validator::make($data, [
            'name' => 'required|string|max:100', 'commission_kind' => ['required', Rule::in(['percent', 'fixed'])],
            'commission_value' => 'required|integer|min:0|max:100000000',
            'hold_days' => 'required|integer|min:1|max:365', 'minimum_payout_minor' => 'required|integer|min:1|max:100000000',
            'attribution_window_days' => 'required|integer|min:1|max:365',
            'recurring' => 'required|boolean', 'commission_max_cycles' => 'required|integer|min:1|max:120', 'exclude_promos' => 'required|boolean',
        ])->validate();
        $this->reason($reason);
        if ($clean['commission_kind'] === 'percent' && $clean['commission_value'] > 100) {
            throw ValidationException::withMessages(['data.commission_value' => 'Процент должен быть от 0 до 100.']);
        }

        return DB::transaction(function () use ($clean, $reason): string {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['partner-program:'.$clean['name']]);
            $program = DB::table('partner_programs')->where('name', $clean['name'])->first();
            $programId = $program->id ?? (string) Str::uuid();
            if (! $program) {
                DB::table('partner_programs')->insert(['id' => $programId, 'name' => $clean['name']]);
            }
            $version = (int) DB::table('partner_program_versions')->where('program_id', $programId)->max('version') + 1;
            $id = (string) Str::uuid();
            DB::table('partner_program_versions')->insert([
                'id' => $id, 'program_id' => $programId, 'version' => $version, 'status' => 'draft',
                'rate_bps' => $clean['commission_kind'] === 'percent' ? $clean['commission_value'] * 100 : null,
                'fixed_minor' => $clean['commission_kind'] === 'fixed' ? $clean['commission_value'] : null,
                'hold_days' => $clean['hold_days'], 'minimum_payout_minor' => $clean['minimum_payout_minor'],
                'rules' => json_encode([
                    'payments' => $clean['recurring'] ? 'recurring' : 'first',
                    'attribution_window_days' => (int) $clean['attribution_window_days'],
                    'commission_max_cycles' => (int) $clean['commission_max_cycles'],
                    'exclude_promos' => (bool) $clean['exclude_promos'],
                ], JSON_THROW_ON_ERROR),
            ]);
            AdminAccess::audit('partner.program.draft.created', 'partner_program_versions', $id, $reason);

            return $id;
        });
    }

    public function publishPartnerVersion(string $id, string $reason): void
    {
        AdminAccess::authorize('admin.partners.manage');
        $this->reason($reason);
        DB::transaction(function () use ($id, $reason): void {
            $version = DB::table('partner_program_versions')->where('id', $id)->lockForUpdate()->first();
            abort_unless($version && $version->status === 'draft', 409);
            DB::table('partner_program_versions')->where('id', $id)->update(['status' => 'published', 'published_at' => now()]);
            DB::table('partner_programs')->where('id', $version->program_id)->update(['active' => true]);
            AdminAccess::audit('partner.program.published', 'partner_program_versions', $id, $reason);
        });
    }

    private function reason(string $reason): void
    {
        Validator::make(['reason' => trim($reason)], ['reason' => 'required|string|min:5|max:500'])->validate();
    }
}
