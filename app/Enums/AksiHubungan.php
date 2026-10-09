<?php

namespace App\Enums;

enum AksiHubungan: string
{
    case Dibuat = 'dibuat';
    case Diubah = 'diubah';
    case Dihapus = 'dihapus';

    public function label(): string
    {
        return match ($this) {
            self::Dibuat => 'Dibuat',
            self::Diubah => 'Diubah',
            self::Dihapus => 'Dihapus',
        };
    }
}
