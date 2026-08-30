<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use App\Http\Middleware\SetUserTimezone;
use Illuminate\Support\Facades\Auth;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('/admin')
            ->login()
            ->authGuard('web')

            ->favicon(asset('logo-demo.svg'))
            ->renderHook(
                'panels::body.start',
                fn (): string => config('app.demo_mode')
                    ? '<div style="background:#0E1526;border-bottom:1px solid rgba(91,200,220,.16);color:#8B97AB;text-align:center;font-size:12px;padding:6px 12px;letter-spacing:.02em;"><span style="color:#F4B740;font-weight:600;">Interactive demo.</span> Every student, class and metric on this page is generated sample data. Nothing here refers to a real person. Resets daily. <span style="color:#586278;">&middot;</span> <a href="https://omninomad-llc.com" style="color:#5BC8DC;text-decoration:none;">Built by OmniNomad</a></div>'
                    : ''
            )
            ->renderHook(
                'panels::auth.login.form.before',
                fn (): string => config('app.demo_mode')
                    ? '<div style="border:1px solid rgba(244,183,64,.45);background:rgba(244,183,64,.06);border-radius:10px;padding:10px 14px;margin-bottom:4px;font-size:13px;line-height:1.6;"><strong style="color:#F4B740;">Demo access</strong><br>Email: <code>demo@lumina.academy</code><br>Password: <code>lumina-demo</code></div>'
                    : ''
            )
            ->brandName('Lumina Admin')
            ->brandLogo(fn () => view('filament.brand', ['name' => 'Lumina Admin']))
            ->font('Hanken Grotesk')
            ->defaultThemeMode(\Filament\Enums\ThemeMode::Dark)
            ->colors([
                'primary' => '#F4B740',
            ])
            ->navigationGroups([
                \Filament\Navigation\NavigationGroup::make()
                    ->label('UK')
                    ->collapsed(true),
                \Filament\Navigation\NavigationGroup::make()
                    ->label('Spain')
                    ->collapsed(true),
                \Filament\Navigation\NavigationGroup::make()
                    ->label('France')
                    ->collapsed(true),
                \Filament\Navigation\NavigationGroup::make()
                    ->label('Academic Management')
                    ->collapsed(false),
                \Filament\Navigation\NavigationGroup::make()
                    ->label('System')
                    ->collapsed(false),
                \Filament\Navigation\NavigationGroup::make()
                    ->label('Help')
                    ->collapsed(true),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                \App\Filament\Pages\Dashboard::class,
            ])
            // Auto-discover widgets so pages can reference them by class.
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            // Keep default dashboard clean by returning widgets from the page itself.
            ->widgets([])
            ->maxContentWidth('6xl')
            ->sidebarWidth('15vw')
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
