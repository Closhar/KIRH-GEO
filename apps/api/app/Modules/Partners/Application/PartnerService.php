<?php

declare(strict_types=1);

namespace App\Modules\Partners\Application;

use App\Modules\Access\PermissionService;
use App\Modules\Partners\Domain\CommissionMath;
use App\Support\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PartnerService
{
    public function __construct(private PermissionService $permissions) {}

    public function attribute(string $userId, string $workspaceId, string $code): void
    {
        $this->permissions->assert($userId, $workspaceId, 'billing.manage');
        DB::transaction(function () use ($userId, $workspaceId, $code): void {
            $workspace = DB::table('workspaces')->where('id', $workspaceId)->lockForUpdate()->first();
            $referral = DB::table('referral_codes')->where('code_hash', hash('sha256', mb_strtoupper(trim($code))))->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();
            if (! $referral) {
                throw new ApiException('REFERRAL_UNAVAILABLE', 'Referral code unavailable.', 422);
            }
            $partner = DB::table('partners')->where('id', $referral->partner_id)->where('status', 'active')->first();
            $version = DB::table('partner_program_versions as v')->join('partner_programs as p', 'p.id', '=', 'v.program_id')->where('v.id', $referral->program_version_id)->where('v.status', 'published')->where('p.active', true)->select('v.*')->first();
            if (! $partner || ! $version) {
                throw new ApiException('REFERRAL_UNAVAILABLE', 'Referral program unavailable.', 422);
            }
            if (in_array($partner->user_id, [$userId, $workspace->owner_user_id, $workspace->billing_owner_user_id], true)) {
                throw new ApiException('SELF_REFERRAL', 'Self referrals are not permitted.', 422);
            }
            $old = DB::table('referral_attributions')->where('workspace_id', $workspaceId)->first();
            if ($old) {
                if ($old->partner_id === $partner->id) {
                    return;
                }
                throw new ApiException('ATTRIBUTION_LOCKED', 'Workspace referral is already attributed.', 409);
            }
            if (DB::table('payments')->where('workspace_id', $workspaceId)->whereNotNull('paid_at')->exists()) {
                throw new ApiException('ATTRIBUTION_LATE', 'Referral must be applied before the first payment.', 409);
            }
            $rules = json_decode($version->rules, true);
            $this->validateRules($rules);
            $days = (int) ($rules['attribution_window_days'] ?? 30);
            if (now()->greaterThan(CarbonImmutable::parse($workspace->created_at)->addDays($days))) {
                throw new ApiException('ATTRIBUTION_EXPIRED', 'Attribution window has expired.', 422);
            }
            DB::table('referral_attributions')->insert(['id' => (string) Str::uuid(), 'workspace_id' => $workspaceId, 'partner_id' => $partner->id, 'program_version_id' => $version->id, 'referral_code_id' => $referral->id, 'source' => 'code']);
            $this->audit($userId, 'partner.attributed', $partner->id, $workspaceId);
        }, 3);
    }

    /** Called within verified settlement transaction. Free access never calls this method. */
    public function accruePayment(string $paymentId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Commission settlement requires the payment transaction.');
        }
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        if (! $payment || $payment->status !== 'succeeded' || $payment->amount_minor < 1) {
            return;
        }
        $attribution = DB::table('referral_attributions')->where('workspace_id', $payment->workspace_id)->lockForUpdate()->first();
        if (! $attribution) {
            return;
        }
        $partner = DB::table('partners')->where('id', $attribution->partner_id)->lockForUpdate()->first();
        if (! $partner || $partner->status !== 'active') {
            return;
        }
        $workspace = DB::table('workspaces')->where('id', $payment->workspace_id)->first();
        if (in_array($partner->user_id, [$workspace->owner_user_id, $workspace->billing_owner_user_id], true)) {
            return;
        }
        $version = DB::table('partner_program_versions')->where('id', $attribution->program_version_id)->first();
        $rules = json_decode($version->rules, true);
        $this->validateRules($rules);
        if ($payment->currency !== $version->currency) {
            return;
        }
        $key = ['payment_id' => $paymentId, 'attribution_id' => $attribution->id, 'commission_kind' => 'payment'];
        if (DB::table('partner_commissions')->where($key)->exists()) {
            return;
        }
        if (($rules['payments'] ?? 'first') === 'first' && DB::table('partner_commissions')->where('attribution_id', $attribution->id)->exists()) {
            return;
        }
        if (DB::table('partner_commissions')->where('attribution_id', $attribution->id)->count() >= (int) ($rules['commission_max_cycles'] ?? 120)) {
            return;
        }
        if (($rules['exclude_promos'] ?? false) && DB::table('promo_redemptions')->where('workspace_id', $payment->workspace_id)->where('redeemed_at', '<=', $payment->created_at)->exists()) {
            return;
        }
        $amount = CommissionMath::calculate((int) $payment->amount_minor, $version->rate_bps === null ? null : (int) $version->rate_bps, $version->fixed_minor === null ? null : (int) $version->fixed_minor);
        if ($amount < 1) {
            return;
        }
        $id = (string) Str::uuid();
        DB::table('partner_commissions')->insert($key + ['id' => $id, 'program_version_id' => $version->id, 'base_minor' => $payment->amount_minor, 'amount_minor' => $amount, 'currency' => $payment->currency, 'calculation_snapshot' => json_encode(['rate_bps' => $version->rate_bps, 'fixed_minor' => $version->fixed_minor, 'rules' => $rules], JSON_THROW_ON_ERROR), 'hold_until' => now()->addDays($version->hold_days), 'status' => $version->hold_days > 0 ? 'held' : 'payable']);
        $this->ledger($partner->id, 'accrual', $amount, $payment->currency, 'accrual:'.$id, $id);
        DB::table('referral_attributions')->where('id', $attribution->id)->update(['locked_at' => now()]);
    }

    public function reverseRefund(string $paymentId, string $refundId): void
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('Commission reversal requires the refund transaction.');
        }
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        $totalRefunded = (int) DB::table('refunds')->where('payment_id', $paymentId)->where('status', 'succeeded')->sum('amount_minor');
        foreach (DB::table('partner_commissions')->where('payment_id', $paymentId)->get() as $commission) {
            $partnerId = DB::table('referral_attributions')->where('id', $commission->attribution_id)->value('partner_id');
            DB::table('partners')->where('id', $partnerId)->lockForUpdate()->first();
            $key = 'refund:'.$refundId.':'.$commission->id;
            if (DB::table('partner_ledger_entries')->where('idempotency_key', $key)->exists()) {
                continue;
            }
            $already = -(int) DB::table('partner_ledger_entries')->where('commission_id', $commission->id)->where('type', 'reversal')->sum('amount_minor');
            $reversal = CommissionMath::reversal((int) $commission->amount_minor, (int) $payment->amount_minor, $totalRefunded, $already);
            if ($reversal > 0) {
                $this->ledger($partnerId, 'reversal', -$reversal, $commission->currency, $key, $commission->id, null, $refundId);
            }
            // Paid/reserved rows remain traceable; reversal creates debt and blocks unsafe payout.
            if ($totalRefunded === (int) $payment->amount_minor && ! in_array($commission->status, ['paid', 'reserved'], true)) {
                DB::table('partner_commissions')->where('id', $commission->id)->update(['status' => 'reversed']);
            }
        }
    }

    public function summary(string $userId): array
    {
        $partner = $this->ownPartner($userId);
        $commissions = DB::table('partner_commissions as c')->join('referral_attributions as a', 'a.id', '=', 'c.attribution_id')->where('a.partner_id', $partner->id)->select('c.*')->get();
        $pending = 0;
        $eligible = 0;
        foreach ($commissions as $commission) {
            $net = $this->netCommission($commission);
            if (in_array($commission->status, ['held', 'pending', 'payable'], true)) {
                if (CarbonImmutable::parse($commission->hold_until)->isFuture()) {
                    $pending += $net;
                } else {
                    $eligible += $net;
                }
            }
        }
        $balance = (int) DB::table('partner_ledger_entries')->where('partner_id', $partner->id)->where('currency', 'RUB')->sum('amount_minor');

        return ['partner_id' => $partner->id, 'referred_count' => DB::table('referral_attributions')->where('partner_id', $partner->id)->count(), 'pending_minor' => $pending, 'payable_minor' => max(0, min($eligible, $balance - $pending)), 'paid_minor' => (int) DB::table('partner_payouts')->where('partner_id', $partner->id)->where('status', 'paid')->sum('amount_minor'), 'currency' => 'RUB'];
    }

    public function requestPayout(string $userId, string $key, int $amount): array
    {
        return DB::transaction(function () use ($userId, $key, $amount): array {
            $partner = $this->ownPartner($userId, true);
            if (! $partner->payout_profile_reference) {
                throw new ApiException('PAYOUT_PROFILE_REQUIRED', 'Verified payout profile is required.', 422);
            }
            $idempotency = 'partner:'.$partner->id.':'.$key;
            $old = DB::table('partner_payouts')->where('idempotency_key', $idempotency)->first();
            if ($old) {
                if ((int) $old->amount_minor !== $amount) {
                    throw new ApiException('IDEMPOTENCY_CONFLICT', 'Payout key amount differs.', 409);
                }

                return ['id' => $old->id, 'amount_minor' => (int) $old->amount_minor, 'status' => $old->status];
            }
            $available = $this->summary($userId)['payable_minor'];
            $eligible = DB::table('partner_commissions as c')->join('referral_attributions as a', 'a.id', '=', 'c.attribution_id')->where('a.partner_id', $partner->id)->whereIn('c.status', ['pending', 'held', 'payable'])->where('c.hold_until', '<=', now())->where('c.currency', 'RUB')->orderBy('c.hold_until')->select('c.*')->get();
            $minimum = 0;
            foreach ($eligible as $commission) {
                $minimum = max($minimum, (int) DB::table('partner_program_versions')->where('id', $commission->program_version_id)->value('minimum_payout_minor'));
            }
            if ($amount < 1 || $amount !== $available || $amount < $minimum) {
                throw new ApiException('PAYOUT_AMOUNT', 'Request the full available balance above the program minimum.', 422, ['available_minor' => $available, 'minimum_minor' => $minimum]);
            }
            $id = (string) Str::uuid();
            DB::table('partner_payouts')->insert(['id' => $id, 'partner_id' => $partner->id, 'amount_minor' => $amount, 'currency' => 'RUB', 'status' => 'reserved', 'idempotency_key' => $idempotency]);
            $remaining = $amount;
            foreach ($eligible as $commission) {
                $part = min($remaining, $this->netCommission($commission));
                if ($part > 0) {
                    DB::table('partner_payout_items')->insert(['payout_id' => $id, 'commission_id' => $commission->id, 'amount_minor' => $part]);
                    $remaining -= $part;
                }
                DB::table('partner_commissions')->where('id', $commission->id)->update(['status' => $part > 0 ? 'reserved' : 'canceled']);
            }
            $this->ledger($partner->id, 'reserve', -$amount, 'RUB', 'reserve:'.$id, null, $id);
            $this->audit($userId, 'partner.payout_reserved', $id);

            return ['id' => $id, 'amount_minor' => $amount, 'status' => 'reserved'];
        }, 3);
    }

    /** Administrator confirms an already executed manual payment, never sends money. */
    public function confirmManualPayout(string $actorId, string $payoutId, string $externalReference, string $reason): void
    {
        $allowed = DB::table('admin_role_assignments as a')->join('roles as r', 'r.id', '=', 'a.role_id')->join('role_permissions as rp', 'rp.role_id', '=', 'r.id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')->where('a.user_id', $actorId)->where('r.scope', 'admin')->where('p.key', 'partners.payout.manage')->exists();
        if (! $allowed || strlen(trim($reason)) < 5 || trim($externalReference) === '') {
            throw new ApiException('PERMISSION_DENIED', 'Payout confirmation requires permission and an audit reason.');
        }
        DB::transaction(function () use ($actorId, $payoutId, $externalReference, $reason): void {
            $payout = DB::table('partner_payouts')->where('id', $payoutId)->first();
            if (! $payout) {
                throw new ApiException('NOT_FOUND', 'Payout unavailable.', 404);
            }
            DB::table('partners')->where('id', $payout->partner_id)->lockForUpdate()->first();
            $payout = DB::table('partner_payouts')->where('id', $payoutId)->lockForUpdate()->first();
            if ($payout->status === 'paid') {
                if ($payout->external_reference !== $externalReference) {
                    throw new ApiException('IDEMPOTENCY_CONFLICT', 'Payout was confirmed with another reference.', 409);
                }

                return;
            }
            $balance = (int) DB::table('partner_ledger_entries')->where('partner_id', $payout->partner_id)->where('currency', $payout->currency)->sum('amount_minor');
            if ($payout->status !== 'reserved' || $balance < 0) {
                throw new ApiException('PAYOUT_REVIEW_REQUIRED', 'Payout requires reconciliation after balance changes.', 409);
            }
            $this->ledger($payout->partner_id, 'release', (int) $payout->amount_minor, $payout->currency, 'settle-release:'.$payoutId, null, $payoutId);
            $this->ledger($payout->partner_id, 'payout', -(int) $payout->amount_minor, $payout->currency, 'paid:'.$payoutId, null, $payoutId);
            DB::table('partner_payouts')->where('id', $payoutId)->update(['status' => 'paid', 'external_reference' => $externalReference, 'paid_at' => now()]);
            DB::table('partner_commissions')->whereIn('id', DB::table('partner_payout_items')->where('payout_id', $payoutId)->select('commission_id'))->update(['status' => 'paid']);
            $this->audit($actorId, 'partner.payout_confirmed', $payoutId, null, $reason);
        }, 3);
    }

    private function ownPartner(string $userId, bool $lock = false): object
    {
        $query = DB::table('partners')->where('user_id', $userId)->where('status', 'active');
        $partner = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $partner) {
            throw new ApiException('NOT_FOUND', 'Partner account unavailable.', 404);
        }

        return $partner;
    }

    private function netCommission(object $commission): int
    {
        return max(0, (int) $commission->amount_minor + (int) DB::table('partner_ledger_entries')->where('commission_id', $commission->id)->where('type', 'reversal')->sum('amount_minor'));
    }

    private function validateRules(array $rules): void
    {
        if (array_diff(array_keys($rules), ['payments', 'attribution_window_days', 'commission_max_cycles', 'exclude_promos']) || ! in_array($rules['payments'] ?? 'first', ['first', 'recurring'], true) || ($rules['attribution_window_days'] ?? 30) < 1 || ($rules['attribution_window_days'] ?? 30) > 365 || ($rules['commission_max_cycles'] ?? 120) < 1 || ($rules['commission_max_cycles'] ?? 120) > 120 || (isset($rules['exclude_promos']) && ! is_bool($rules['exclude_promos']))) {
            throw new ApiException('PARTNER_RULES_UNSUPPORTED', 'Partner program rules require review.', 422);
        }
    }

    private function ledger(string $partnerId, string $type, int $amount, string $currency, string $key, ?string $commissionId = null, ?string $payoutId = null, ?string $refundId = null): void
    {
        DB::table('partner_ledger_entries')->insert(['id' => (string) Str::uuid(), 'partner_id' => $partnerId, 'commission_id' => $commissionId, 'payout_id' => $payoutId, 'refund_id' => $refundId, 'type' => $type, 'amount_minor' => $amount, 'currency' => $currency, 'idempotency_key' => $key]);
    }

    private function audit(string $actorId, string $action, string $targetId, ?string $workspaceId = null, ?string $reason = null): void
    {
        DB::table('audit_logs')->insert(['id' => (string) Str::uuid(), 'actor_id' => $actorId, 'workspace_id' => $workspaceId, 'action' => $action, 'target_type' => 'partner', 'target_id' => $targetId, 'reason' => $reason]);
    }
}
