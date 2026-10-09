<?php

namespace App\Enums;

/**
 * Prioritas penanganan tiket gangguan.
 */
enum PrioritasTiket: string
{
    case Rendah = 'rendah';
    case Sedang = 'sedang';
    case Tinggi = 'tinggi';
    case Kritis = 'kritis';

    public function label(): string
    {
        return match ($this) {
            self::Rendah => 'Rendah',
            self::Sedang => 'Sedang',
            self::Tinggi => 'Tinggi',
            self::Kritis => 'Kritis',
        };
    }

    /** Warna lencana Flux. */
    public function warna(): string
    {
        return match ($this) {
            self::Rendah => 'zinc',
            self::Sedang => 'sky',
            self::Tinggi => 'amber',
            self::Kritis => 'red',
        };
    }
}
