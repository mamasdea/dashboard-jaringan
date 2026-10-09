<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Untuk model dengan kolom `simulasi`: data contoh selalu berlabel, dan
 * labelnya dijaga juga oleh check constraint di database.
 *
 * Bawaan: kolom `nama` berawalan "[SIMULASI]". Model tanpa nama (tiang, titik
 * jaringan, segmen kabel) menimpa kolomLabelSimulasi() menjadi `kode`, yang
 * berawalan "SIM-".
 */
trait BerlabelSimulasi
{
    public static function kolomLabelSimulasi(): string
    {
        return 'nama';
    }

    public static function awalanSimulasi(): string
    {
        return static::kolomLabelSimulasi() === 'kode' ? 'SIM-' : '[SIMULASI]';
    }

    public static function bootBerlabelSimulasi(): void
    {
        static::saving(function (self $model): void {
            $kolom = static::kolomLabelSimulasi();
            $awalan = static::awalanSimulasi();
            $nilai = (string) $model->getAttribute($kolom);

            if ($model->simulasi && ! str_starts_with($nilai, $awalan)) {
                $model->setAttribute($kolom, $awalan.($kolom === 'kode' ? '' : ' ').$nilai);
            }
        });
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeSimulasi(Builder $query): void
    {
        $query->where($this->qualifyColumn('simulasi'), true);
    }
}
