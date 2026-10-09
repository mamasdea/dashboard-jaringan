<?php

use App\Jobs\CatatDetakAntrean;
use App\Kesehatan\PemeriksaKesehatan;
use Illuminate\Support\Facades\Schedule;

/*
| Jadwal dijalankan `php artisan schedule:run` tiap menit (timer systemd di
| deploy/systemd). Tidak ada jadwal yang menghubungi perangkat jaringan:
| pemeriksaan hanya dilakukan proses kolektor.
*/

// Tanda hidup penjadwal dan worker antrean, dibaca health check.
Schedule::call(fn () => PemeriksaKesehatan::catatDetak(PemeriksaKesehatan::DETAK_PENJADWAL))
    ->everyMinute()
    ->name('detak-penjadwal');

Schedule::job(new CatatDetakAntrean)->everyMinute()->name('detak-antrean');

// Retensi data monitoring mentah, di luar jam kerja.
Schedule::command('monitoring:pangkas')
    ->dailyAt('02:30')
    ->timezone((string) config('app.display_timezone'))
    ->withoutOverlapping()
    ->name('pangkas-monitoring');

// Pekerjaan antrean yang gagal disimpan dua minggu untuk ditinjau.
Schedule::command('queue:prune-failed --hours=336')->dailyAt('03:00')->name('pangkas-antrean-gagal');
Schedule::command('auth:clear-resets')->dailyAt('03:10')->name('pangkas-token-reset');
