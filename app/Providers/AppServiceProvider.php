<?php

namespace App\Providers;

use App\Enums\AksiAktivitas;
use App\Enums\Izin;
use App\Models\User;
use App\Support\PencatatAktivitas;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Fortify\Fortify;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->daftarkanIzin();
        $this->catatAktivitasAutentikasi();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Setiap izin menjadi Gate; matriksnya ada di enum Peran.
     */
    protected function daftarkanIzin(): void
    {
        foreach (Izin::cases() as $izin) {
            Gate::define($izin->value, fn (User $user): bool => $user->boleh($izin));
        }
    }

    protected function catatAktivitasAutentikasi(): void
    {
        Event::listen(function (Login $event): void {
            if ($event->user instanceof User) {
                app(PencatatAktivitas::class)->catat(AksiAktivitas::Masuk, $event->user, pelaku: $event->user);
            }
        });

        Event::listen(function (Logout $event): void {
            if ($event->user instanceof User) {
                app(PencatatAktivitas::class)->catat(AksiAktivitas::Keluar, $event->user, pelaku: $event->user);
            }
        });

        Event::listen(function (Failed $event): void {
            $user = $event->user instanceof User ? $event->user : null;

            app(PencatatAktivitas::class)->catat(AksiAktivitas::GagalMasuk, $user, [
                'email' => $event->credentials[Fortify::username()] ?? null,
            ]);
        });

        Event::listen(function (Lockout $event): void {
            app(PencatatAktivitas::class)->catat(AksiAktivitas::TerkunciSementara, rincian: [
                'email' => $event->request->input(Fortify::username()),
            ]);
        });

        Event::listen(function (TwoFactorAuthenticationConfirmed $event): void {
            app(PencatatAktivitas::class)->catat(AksiAktivitas::DuaFaktorDiaktifkan, $event->user);
        });

        Event::listen(function (TwoFactorAuthenticationDisabled $event): void {
            app(PencatatAktivitas::class)->catat(AksiAktivitas::DuaFaktorDinonaktifkan, $event->user);
        });
    }
}
