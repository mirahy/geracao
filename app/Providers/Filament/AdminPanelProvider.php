<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\RegisterUser;
use App\Filament\Pages\UnitAccessRequests;
use App\Http\Controllers\DocumentFileController;
use App\Http\Controllers\RateioPaymentFileController;
use App\Http\Middleware\RedirectPendingUsers;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->login()
            ->registration(RegisterUser::class)
            ->databaseNotifications()
            ->profile()
            ->userMenuItems([
                Action::make('unitAccessRequests')->label('Solicitar acesso a unidades')
                    ->icon(Heroicon::OutlinedKey)
                    ->url(fn (): string => UnitAccessRequests::getUrl())
                    ->visible(fn (): bool => UnitAccessRequests::canAccess()),
            ])
            ->colors([
                'primary' => Color::Amber,
                'gray' => Color::Slate,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->authenticatedRoutes(function () {
                Route::get('documents/{document}/file', DocumentFileController::class)->name('documents.file');
                Route::get('rateio-payments/{payment}/file', RateioPaymentFileController::class)->name('rateio-payments.file');
            })
            ->brandName('Geração Solar')
            ->brandLogo(asset('assets/logo.png'))
            ->brandLogoHeight('2.25rem')
            ->favicon(asset('assets/favicon.png'))
            ->sidebarCollapsibleOnDesktop()
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                RedirectPendingUsers::class,
            ]);
    }
}
