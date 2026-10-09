<?php

namespace App\Fo;

use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\SegmenKabel;
use App\Models\Tiang;
use App\Models\TitikJaringan;

/**
 * Hasil analisis dampak untuk satu aset FO. Selalu ditampilkan dengan
 * AnalisisDampak::LABEL dan daftar keterbatasannya.
 */
final readonly class HasilDampak
{
    /**
     * @param  list<SegmenKabel>  $segmen  Kabel yang diperiksa.
     * @param  list<array{opd: Opd, perangkat: list<Perangkat>, jalur: list<Jalur>, lengkap: bool}>  $opd
     * @param  list<Jalur>  $jalurTanpaPerangkat  Core terpakai yang ujungnya tidak sampai ke perangkat mana pun.
     * @param  list<string>  $keterbatasan
     */
    public function __construct(
        public SegmenKabel|TitikJaringan|Tiang $aset,
        public array $segmen,
        public array $opd,
        public array $jalurTanpaPerangkat,
        public int $coreTidakTerpakai,
        public array $keterbatasan,
    ) {}

    public function jumlahJalur(): int
    {
        $kunci = [];

        foreach ($this->opd as $baris) {
            foreach ($baris['jalur'] as $jalur) {
                $kunci[$jalur->kunci()] = true;
            }
        }

        return count($kunci);
    }
}
