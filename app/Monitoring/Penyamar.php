<?php

namespace App\Monitoring;

use SensitiveParameter;

/**
 * Menghapus rahasia dari teks sebelum teks itu disimpan atau dicatat ke log.
 * Pesan galat dari pustaka dan utilitas sistem kadang mengulang masukannya.
 */
final class Penyamar
{
    public const string PENGGANTI = '[disamarkan]';

    /**
     * @param  array<array-key, mixed>  $rahasia  Nilai yang tidak boleh muncul; selain string diabaikan.
     */
    public static function bersihkan(?string $teks, #[SensitiveParameter] array $rahasia): ?string
    {
        if ($teks === null) {
            return null;
        }

        foreach ($rahasia as $nilai) {
            // Nilai terlalu pendek akan menyamarkan teks biasa secara acak.
            if (is_string($nilai) && strlen($nilai) >= 3) {
                $teks = str_ireplace($nilai, self::PENGGANTI, $teks);
            }
        }

        return $teks;
    }
}
