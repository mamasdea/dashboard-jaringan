<?php

namespace App\Concerns;

use App\Enums\AksiAktivitas;
use App\Support\PencatatAktivitas;

/**
 * Mencatat pembuatan, perubahan (nilai lama dan baru), dan penghapusan model
 * ke log aktivitas.
 */
trait DicatatAktivitasnya
{
    /**
     * @return array{dibuat: AksiAktivitas, diubah: AksiAktivitas, dihapus: AksiAktivitas}
     */
    abstract public static function aksiAktivitas(): array;

    public static function bootDicatatAktivitasnya(): void
    {
        static::created(function (self $model): void {
            app(PencatatAktivitas::class)->catat(static::aksiAktivitas()['dibuat'], $model);
        });

        static::updated(function (self $model): void {
            $perubahan = [];

            foreach ($model->getChanges() as $kolom => $nilai) {
                if ($kolom !== $model->getUpdatedAtColumn()) {
                    $dari = $model->getRawOriginal($kolom);

                    // Nilai panjang (mis. jalur peta) tidak disalin utuh ke log.
                    $perubahan[$kolom] = max(strlen((string) json_encode($dari)), strlen((string) json_encode($nilai))) > 300
                        ? 'diubah'
                        : ['dari' => $dari, 'menjadi' => $nilai];
                }
            }

            if ($perubahan !== []) {
                app(PencatatAktivitas::class)->catat(static::aksiAktivitas()['diubah'], $model, $perubahan);
            }
        });

        static::deleted(function (self $model): void {
            app(PencatatAktivitas::class)->catat(static::aksiAktivitas()['dihapus'], $model);
        });
    }
}
