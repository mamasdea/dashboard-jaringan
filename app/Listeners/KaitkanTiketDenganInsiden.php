<?php

namespace App\Listeners;

use App\Events\InsidenDibuka;
use App\Events\InsidenDitutup;
use App\Tiket\LayananTiket;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Menghubungkan insiden monitoring dengan tiket gangguan. Hanya menulis ke
 * database; tidak menghubungi perangkat.
 *
 * Dipanggil di dalam transaksi penilaian status. Pekerjaan tiket dibungkus
 * savepoint sendiri: bila gagal, hanya bagian tiket yang dibatalkan dan
 * status monitoring tetap tersimpan.
 */
class KaitkanTiketDenganInsiden
{
    public function __construct(private readonly LayananTiket $layanan) {}

    public function handleInsidenDibuka(InsidenDibuka $event): void
    {
        $this->aman(fn () => $this->layanan->bukaDariInsiden($event->insiden, $event->endpoint));
    }

    public function handleInsidenDitutup(InsidenDitutup $event): void
    {
        $this->aman(fn () => $this->layanan->catatPenutupanInsiden($event->insiden));
    }

    /**
     * @param  Closure(): mixed  $aksi
     */
    private function aman(Closure $aksi): void
    {
        try {
            DB::transaction($aksi);
        } catch (Throwable $galat) {
            report($galat);
        }
    }
}
