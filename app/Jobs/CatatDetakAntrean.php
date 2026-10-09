<?php

namespace App\Jobs;

use App\Kesehatan\PemeriksaKesehatan;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Dikirim penjadwal tiap menit. Bila worker antrean hidup, pekerjaan ini
 * berjalan dan memperbarui tanda hidupnya; bila tidak, tanda itu menua dan
 * health check melaporkannya.
 */
class CatatDetakAntrean implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Tanpa worker, pekerjaan ini tidak menumpuk di antrean. */
    public int $uniqueFor = 600;

    public function handle(): void
    {
        PemeriksaKesehatan::catatDetak(PemeriksaKesehatan::DETAK_ANTREAN);
    }
}
