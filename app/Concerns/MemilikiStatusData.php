<?php

namespace App\Concerns;

use App\Enums\StatusData;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pendataan bertahap: objek boleh disimpan sebelum datanya lengkap. Kolom
 * `status_data` dihitung ulang setiap kali disimpan dari daftar kekurangan().
 */
trait MemilikiStatusData
{
    /**
     * Data yang masih harus diisi agar objek dianggap lengkap.
     *
     * @return list<string>
     */
    abstract public function kekurangan(): array;

    public static function bootMemilikiStatusData(): void
    {
        static::saving(function (self $model): void {
            $model->status_data = $model->kekurangan() === [] ? StatusData::Lengkap : StatusData::PerluDilengkapi;
        });
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopePerluDilengkapi(Builder $query): void
    {
        $query->where($this->qualifyColumn('status_data'), StatusData::PerluDilengkapi);
    }
}
