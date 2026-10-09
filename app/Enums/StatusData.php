<?php

namespace App\Enums;

/**
 * Kelengkapan pendataan sebuah objek inventaris.
 */
enum StatusData: string
{
    case Lengkap = 'lengkap';
    case PerluDilengkapi = 'perlu_dilengkapi';

    public function label(): string
    {
        return match ($this) {
            self::Lengkap => 'Lengkap',
            self::PerluDilengkapi => 'Perlu dilengkapi',
        };
    }
}
