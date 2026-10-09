<?php

namespace App\Enums;

/**
 * Status koneksi endpoint ke Diskominfo. Bukan status internet OPD.
 */
enum StatusMonitoring: string
{
    case Normal = 'normal';
    case KoneksiMenurun = 'menurun';
    case TidakTerjangkau = 'tidak_terjangkau';
    case BelumDiketahui = 'belum_diketahui';
    case Pemeliharaan = 'pemeliharaan';

    public function label(): string
    {
        return match ($this) {
            self::Normal => 'Normal',
            self::KoneksiMenurun => 'Koneksi menurun',
            self::TidakTerjangkau => 'Endpoint tidak terjangkau',
            self::BelumDiketahui => 'Belum diketahui',
            self::Pemeliharaan => 'Pemeliharaan',
        };
    }

    /**
     * Ikon dan kode huruf: status harus terbaca tanpa bergantung pada warna.
     */
    public function ikon(): string
    {
        return match ($this) {
            self::Normal => 'check-circle',
            self::KoneksiMenurun => 'exclamation-triangle',
            self::TidakTerjangkau => 'x-circle',
            self::BelumDiketahui => 'question-mark-circle',
            self::Pemeliharaan => 'wrench-screwdriver',
        };
    }

    /** Satu karakter untuk penanda peta. */
    public function kode(): string
    {
        return match ($this) {
            self::Normal => 'N',
            self::KoneksiMenurun => 'M',
            self::TidakTerjangkau => 'T',
            self::BelumDiketahui => '?',
            self::Pemeliharaan => 'P',
        };
    }

    /** Warna lencana Flux. */
    public function warna(): string
    {
        return match ($this) {
            self::Normal => 'green',
            self::KoneksiMenurun => 'amber',
            self::TidakTerjangkau => 'red',
            self::BelumDiketahui => 'zinc',
            self::Pemeliharaan => 'sky',
        };
    }
}
