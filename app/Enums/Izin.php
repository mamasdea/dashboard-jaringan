<?php

namespace App\Enums;

/**
 * Izin yang didaftarkan sebagai Gate (lihat AppServiceProvider).
 *
 * Nilai enum adalah nama ability, mis. `$user->can(Izin::KelolaAset->value)`
 * atau middleware `can:kelola-aset`.
 */
enum Izin: string
{
    case KelolaPengguna = 'kelola-pengguna';
    case KelolaKonfigurasi = 'kelola-konfigurasi';
    case LihatLogAktivitas = 'lihat-log-aktivitas';
    case KelolaAset = 'kelola-aset';
    case KelolaEndpoint = 'kelola-endpoint';
    case KelolaTiket = 'kelola-tiket';
    case LihatAset = 'lihat-aset';

    /** Hanya tiket yang ditugaskan kepadanya; kepemilikan diperiksa di policy tiket. */
    case TanganiTiket = 'tangani-tiket';

    case LihatDashboard = 'lihat-dashboard';
    case LihatLaporan = 'lihat-laporan';
}
