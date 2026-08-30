<?php

namespace App\Providers\Filament;

use App\Http\Middleware\SetUserTimezone;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class PortalPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('portal')
            ->path('/portal')
            ->login()
            ->authGuard('web')
            ->renderHook(
                'panels::body.start',
                fn (): string => config('app.demo_mode')
                    ? '<div style="background:#0E1526;border-bottom:1px solid rgba(91,200,220,.16);color:#8B97AB;text-align:center;font-size:12px;padding:6px 12px;letter-spacing:.02em;"><span style="color:#F4B740;font-weight:600;">Interactive demo.</span> Every student, class and metric on this page is generated sample data. Nothing here refers to a real person. Resets daily. <span style="color:#586278;">&middot;</span> <a href="https://omninomad-llc.com" style="color:#5BC8DC;text-decoration:none;">Built by OmniNomad</a></div>'
                    : ''
            )
            ->renderHook(
                'panels::auth.login.form.before',
                fn (): string => config('app.demo_mode')
                    ? '<div style="border:1px solid rgba(91,200,220,.45);background:rgba(91,200,220,.06);border-radius:10px;padding:10px 14px;margin-bottom:4px;font-size:13px;line-height:1.6;"><strong style="color:#5BC8DC;">Demo access</strong><br>Email: <code>teacher@lumina.academy</code><br>Password: <code>lumina-demo</code></div>'
                    : ''
            )
            ->brandName('Lumina Portal')
            ->brandLogo(fn () => view('filament.brand', ['name' => 'Lumina Portal']))
            ->font('Hanken Grotesk')
            ->defaultThemeMode(\Filament\Enums\ThemeMode::Dark)
            ->favicon(asset('logo-demo.svg'))
            ->colors([
                'primary' => '#5BC8DC',
            ])
            ->discoverResources(in: app_path('Filament/Portal/Resources'), for: 'App\\Filament\\Portal\\Resources')
            ->resources([
                \App\Filament\Resources\AssessmentTemplateResource::class,
            ])
            ->discoverPages(in: app_path('Filament/Portal/Pages'), for: 'App\\Filament\\Portal\\Pages')
            ->discoverWidgets(in: app_path('Filament/Portal/Widgets'), for: 'App\\Filament\\Portal\\Widgets')
            ->pages([
                \App\Filament\Portal\Pages\PortalDashboard::class,
                \App\Filament\Portal\Pages\MyRoster::class,
                \App\Filament\Portal\Pages\MyStudents::class,
                \App\Filament\Portal\Pages\MyCalendar::class,
                \App\Filament\Portal\Pages\ClassSessionRoster::class,
                \App\Filament\Portal\Pages\TakeAttendance::class,
                \App\Filament\Portal\Pages\StudentProfile::class,
            ])
            ->renderHook('panels::content.start', fn () => view('filament.hooks.content-wrapper-start'))
            ->renderHook('panels::content.end', fn () => view('filament.hooks.content-wrapper-end'))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetUserTimezone::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
