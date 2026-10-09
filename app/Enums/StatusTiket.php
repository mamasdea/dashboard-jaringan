<?php

namespace App\Enums;

/**
 * Status pekerjaan teknisi pada sebuah tiket. Tidak diubah oleh monitoring.
 */
enum StatusTiket: string
{
    case Baru = 'baru';
    case Ditugaskan = 'ditugaskan';
    case Dikerjakan = 'dikerjakan';
    case Menunggu = 'menunggu';
    case Selesai = 'selesai';
    case Dibatalkan = 'dibatalkan';

    public function label(): string
    {
        return match ($this) {
            self::Baru => 'Baru',
            self::Ditugaskan => 'Ditugaskan',
            self::Dikerjakan => 'Dikerjakan',
            self::Menunggu => 'Menunggu',
            self::Selesai => 'Selesai',
            self::Dibatalkan => 'Dibatalkan',
        };
    }

    /**
     * Tiket yang masih membutuhkan pekerjaan.
     */
    public function terbuka(): bool
    {
        return ! in_array($this, [self::Selesai, self::Dibatalkan], true);
    }

    /**
     * @return list<string>
     */
    public static function nilaiTerbuka(): array
    {
        return array_values(array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => $s->terbuka())));
    }

    /** Warna lencana Flux. */
    public function warna(): string
    {
        return match ($this) {
            self::Baru => 'red',
            self::Ditugaskan => 'amber',
            self::Dikerjakan => 'sky',
            self::Menunggu => 'zinc',
            self::Selesai => 'green',
            self::Dibatalkan => 'zinc',
        };
    }
}
