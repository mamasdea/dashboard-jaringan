<?php

namespace App\Enums;

enum JenisTitikJaringan: string
{
    case Odc = 'odc';
    case Odp = 'odp';
    case Closure = 'closure';
    case Otb = 'otb';

    public function label(): string
    {
        return match ($this) {
            self::Odc => 'ODC',
            self::Odp => 'ODP',
            self::Closure => 'Closure',
            self::Otb => 'Terminasi/OTB',
        };
    }

    /**
     * Closure hanya berisi sambungan; jenis lain memiliki port terminasi.
     */
    public function berPort(): bool
    {
        return $this !== self::Closure;
    }
}
