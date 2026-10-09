<?php

namespace App\Enums;

/**
 * Tempat perangkat terpasang secara fisik. Pemiliknya tetap OPD pada `opd_id`;
 * perangkat sisi Diskominfo untuk tautan sebuah OPD berpenempatan Diskominfo.
 */
enum PenempatanPerangkat: string
{
    case Opd = 'opd';
    case Diskominfo = 'diskominfo';

    public function label(): string
    {
        return match ($this) {
            self::Opd => 'Di OPD',
            self::Diskominfo => 'Di Diskominfo',
        };
    }
}
