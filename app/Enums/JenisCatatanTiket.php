<?php

namespace App\Enums;

/**
 * Jenis entri pada linimasa tiket.
 */
enum JenisCatatanTiket: string
{
    case Tindakan = 'tindakan';
    case Catatan = 'catatan';
    case Sistem = 'sistem';

    public function label(): string
    {
        return match ($this) {
            self::Tindakan => 'Tindakan',
            self::Catatan => 'Catatan',
            self::Sistem => 'Sistem',
        };
    }
}
