<?php

namespace App\Providers\Filament;

use App\Filament\Pages\LocationPolicy;
use App\Filament\Pages\PartnerProgram;
use App\Filament\Pages\PromoCodes;
use App\Filament\Pages\SupportOverview;
use App\Filament\Pages\Tariffs;
use App\Filament\Pages\TestAccess;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel->default()->id('admin')->path('admin')->login()->profile()
            ->brandName('KIRH GEO')->brandLogo(asset('brand/kt-geo-logo.png'))->brandLogoHeight('3rem')
            ->favicon(asset('brand/kt-geo-logo.png'))
            ->colors(['primary' => Color::hex('#56318f'), 'success' => Color::hex('#00b58b')])
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: true)
            ->pages([LocationPolicy::class, Tariffs::class, TestAccess::class, PromoCodes::class, PartnerProgram::class, SupportOverview::class])
            ->middleware([
                EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class,
                AuthenticateSession::class, ShareErrorsFromSession::class, VerifyCsrfToken::class,
                SubstituteBindings::class, DisableBladeIconComponents::class, DispatchServingFilamentEvent::class,
            ])->authMiddleware([Authenticate::class]);
    }
}
