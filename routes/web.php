<?php

use App\Enums\Izin;
use App\Http\Controllers\AsetController;
use App\Http\Controllers\EksporLaporanController;
use App\Http\Controllers\FotoPerangkatController;
use App\Http\Controllers\FotoTiangController;
use App\Http\Controllers\FotoTiketController;
use App\Http\Controllers\KesehatanController;
use App\Http\Controllers\PetaDataController;
use App\Http\Controllers\PetaStatusController;
use App\Impor\PengimporCsv;
use App\Laporan\DaftarLaporan;
use App\Laporan\Ekspor;
use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\SegmenKabel;
use App\Models\Tiang;
use App\Models\Tiket;
use App\Models\TitikJaringan;
use App\Models\User;
use App\Support\TautanAset;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Health check untuk alat pemantau; hanya aktif bila KESEHATAN_TOKEN diisi.
Route::get('kesehatan', KesehatanController::class)->middleware('throttle:30,1')->name('kesehatan');

Route::middleware(['auth', 'verified'])->group(function () {
    // Dashboard hanya membaca hasil monitoring yang tersimpan.
    Route::middleware('can:'.Izin::LihatDashboard->value)->group(function () {
        Route::livewire('dashboard', 'pages::dashboard.index')->name('dashboard');
        Route::get('dashboard/peta', PetaStatusController::class)->name('dashboard.peta');
        Route::livewire('dashboard/opd/{opd}', 'pages::dashboard.opd')->name('dashboard.opd');
    });

    // Laporan dan ekspornya hanya membaca data tersimpan.
    Route::middleware('can:'.Izin::LihatLaporan->value)->group(function () {
        Route::livewire('laporan', 'pages::laporan.index')->name('laporan.index');
        Route::get('laporan/ekspor/{jenis}.{format}', EksporLaporanController::class)
            ->whereIn('jenis', array_keys(DaftarLaporan::JENIS))
            ->whereIn('format', array_keys(Ekspor::FORMAT))
            ->middleware('throttle:20,1')
            ->name('laporan.ekspor');
    });

    // Tiket gangguan. Kepemilikan tiket (teknisi yang ditugaskan) diperiksa
    // policy di mount() dan di setiap aksi.
    Route::middleware('can:viewAny,'.Tiket::class)->group(function () {
        Route::livewire('tiket', 'pages::tiket.index')->name('tiket.index');
        Route::livewire('tiket/{tiket}', 'pages::tiket.rinci')->name('tiket.rinci');
        Route::get('tiket/{tiket}/foto/{catatan}', FotoTiketController::class)
            ->middleware('can:view,tiket')
            ->name('tiket.foto');
    });

    Route::livewire('opd', 'pages::opd.index')
        ->middleware('can:viewAny,'.Opd::class)
        ->name('opd.index');

    Route::livewire('perangkat', 'pages::perangkat.index')
        ->middleware('can:viewAny,'.Perangkat::class)
        ->name('perangkat.index');

    Route::get('perangkat/{perangkat}/foto', FotoPerangkatController::class)
        ->middleware('can:view,perangkat')
        ->name('perangkat.foto');

    Route::livewire('impor/{jenis}', 'pages::impor.index')
        ->whereIn('jenis', PengimporCsv::JENIS)
        ->middleware('can:'.Izin::KelolaAset->value)
        ->name('impor');

    Route::prefix('fo')->name('fo.')->group(function () {
        Route::livewire('tiang', 'pages::fo.tiang')
            ->middleware('can:viewAny,'.Tiang::class)
            ->name('tiang');

        Route::get('tiang/{tiang}/foto', FotoTiangController::class)
            ->middleware('can:view,tiang')
            ->name('tiang.foto');

        Route::livewire('titik', 'pages::fo.titik')
            ->middleware('can:viewAny,'.TitikJaringan::class)
            ->name('titik');

        // Model diikat di mount() komponen, yang juga memeriksa `view`.
        Route::livewire('titik/{titik}', 'pages::fo.titik-rinci')
            ->middleware('can:viewAny,'.TitikJaringan::class)
            ->name('titik.rinci');

        Route::livewire('kabel', 'pages::fo.kabel')
            ->middleware('can:viewAny,'.SegmenKabel::class)
            ->name('kabel');

        Route::livewire('kabel/{segmen}', 'pages::fo.kabel-rinci')
            ->middleware('can:viewAny,'.SegmenKabel::class)
            ->name('kabel.rinci');

        // Penelusuran jalur dan potensi terdampak; hanya membaca inventaris.
        Route::livewire('penelusuran', 'pages::fo.penelusuran')
            ->middleware('can:'.Izin::LihatAset->value)
            ->name('penelusuran');
    });

    Route::livewire('peta', 'pages::peta.index')
        ->middleware('can:'.Izin::LihatAset->value)
        ->name('peta');

    Route::get('peta/data', PetaDataController::class)
        ->middleware('can:'.Izin::LihatAset->value)
        ->name('peta.data');

    // Sasaran QR pada label aset. Hak akses per aset diperiksa di controller.
    Route::get('aset/{jenis}/{kode}', [AsetController::class, 'rinci'])
        ->whereIn('jenis', array_keys(TautanAset::JENIS))
        ->where('kode', TautanAset::POLA_KODE)
        ->name('aset.rinci');

    Route::get('label/{jenis}', [AsetController::class, 'label'])
        ->whereIn('jenis', array_keys(TautanAset::JENIS))
        ->name('aset.label');

    Route::livewire('pengguna', 'pages::pengguna.index')
        ->middleware('can:viewAny,'.User::class)
        ->name('pengguna.index');

    Route::livewire('log-aktivitas', 'pages::log-aktivitas.index')
        ->middleware('can:'.Izin::LihatLogAktivitas->value)
        ->name('log-aktivitas.index');
});

require __DIR__.'/settings.php';
