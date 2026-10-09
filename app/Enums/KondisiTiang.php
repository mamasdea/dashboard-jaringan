<?php

namespace App\Enums;

enum KondisiTiang: string
{
    case Baik = 'baik';
    case Miring = 'miring';
    case Rusak = 'rusak';
    case Hilang = 'hilang';

    public function label(): string
    {
        return match ($this) {
            self::Baik => 'Baik',
            self::Miring => 'Miring',
            self::Rusak => 'Rusak',
            self::Hilang => 'Hilang',
        };
    }
}
