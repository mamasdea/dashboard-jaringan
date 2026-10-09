<?php

namespace App\Enums;

/**
 * Status core kabel dan port terminasi. Tersedia/terpakai diturunkan dari hubungan yang tercatat; rusak ditandai manual.
 */
enum StatusPemakaian: string
{
    case Tersedia = 'tersedia';
    case Terpakai = 'terpakai';
    case Rusak = 'rusak';

    public function label(): string
    {
        return match ($this) {
            self::Tersedia => 'Tersedia',
            self::Terpakai => 'Terpakai',
            self::Rusak => 'Rusak',
        };
    }
}
