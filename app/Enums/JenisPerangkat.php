<?php

namespace App\Enums;

enum JenisPerangkat: string
{
    case Switch = 'switch';
    case Router = 'router';
    case Firewall = 'firewall';
    case AccessPoint = 'access_point';
    case Olt = 'olt';
    case MediaConverter = 'media_converter';
    case Lainnya = 'lainnya';

    public function label(): string
    {
        return match ($this) {
            self::Switch => 'Switch',
            self::Router => 'Router',
            self::Firewall => 'Firewall',
            self::AccessPoint => 'Access point',
            self::Olt => 'OLT',
            self::MediaConverter => 'Media converter',
            self::Lainnya => 'Lainnya',
        };
    }
}
