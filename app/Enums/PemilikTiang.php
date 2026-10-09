<?php

namespace App\Enums;

enum PemilikTiang: string
{
    case Diskominfo = 'diskominfo';
    case Pln = 'pln';
    case Telkom = 'telkom';
    case Swasta = 'swasta';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Diskominfo => 'Diskominfo',
            self::Pln => 'PLN',
            self::Telkom => 'Telkom',
            self::Swasta => 'Penyedia swasta',
            self::Lainnya => 'Lainnya',
        };
    }
}
