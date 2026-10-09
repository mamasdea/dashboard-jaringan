<?php

namespace App\Enums;

enum JenisHubungan: string
{
    case Sambungan = 'sambungan';
    case Terminasi = 'terminasi';
    case KoneksiEndpoint = 'koneksi_endpoint';
    case RuteTiang = 'rute_tiang';

    public function label(): string
    {
        return match ($this) {
            self::Sambungan => 'Sambungan core',
            self::Terminasi => 'Terminasi ke port',
            self::KoneksiEndpoint => 'Koneksi endpoint',
            self::RuteTiang => 'Rute tiang',
        };
    }
}
