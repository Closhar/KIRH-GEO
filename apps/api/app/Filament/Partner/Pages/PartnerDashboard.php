<?php

namespace App\Filament\Partner\Pages;

use App\Modules\Partners\Application\PartnerService;
use Filament\Pages\Page;
use Illuminate\Support\Facades\DB;

class PartnerDashboard extends Page
{
    protected static ?string $title = 'Кабинет партнёра';

    protected static ?string $navigationLabel = 'Баланс и начисления';

    protected string $view = 'filament.partner.dashboard';

    public static function canAccess(): bool
    {
        $user = auth('web')->user();

        return $user && $user->status === 'active'
            && DB::table('partners')->where('user_id', $user->id)->where('status', 'active')->exists();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess(), 403);
        $summary = app(PartnerService::class)->summary(auth('web')->id());
        $partnerId = $summary['partner_id'];

        return ['summary' => $summary,
            'payouts' => DB::table('partner_payouts')->where('partner_id', $partnerId)
                ->select('amount_minor', 'currency', 'status', 'requested_at', 'paid_at')->orderByDesc('requested_at')->limit(50)->get(),
            'campaigns' => DB::table('referral_links as l')->join('partner_program_versions as v', 'v.id', '=', 'l.program_version_id')
                ->join('partner_programs as p', 'p.id', '=', 'v.program_id')->where('l.partner_id', $partnerId)->whereNull('l.revoked_at')
                ->select('l.campaign', 'l.expires_at', 'p.name', 'v.version', 'v.rate_bps', 'v.fixed_minor', 'v.hold_days', 'v.minimum_payout_minor')->limit(50)->get()];
    }
}
