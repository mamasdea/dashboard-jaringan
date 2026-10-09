<?php

namespace App\Enums;

/**
 * Keluaran satu pemeriksaan. Kegagalan perangkat dibedakan dari kegagalan
 * kolektor: hanya yang pertama menjadi bukti bahwa endpoint tidak terjangkau.
 */
enum KeluaranPemeriksaan: string
{
    /** Endpoint menjawab. */
    case Berhasil = 'berhasil';

    /** Pemeriksaan berjalan sampai selesai dan endpoint tidak menjawab. */
    case PerangkatGagal = 'gagal_perangkat';

    /** Pemeriksaan tidak dapat diselesaikan: adapter galat atau melewati batas waktu. */
    case GalatKolektor = 'galat_kolektor';

    public function label(): string
    {
        return match ($this) {
            self::Berhasil => 'Berhasil',
            self::PerangkatGagal => 'Endpoint tidak menjawab',
            self::GalatKolektor => 'Galat kolektor',
        };
    }
}
