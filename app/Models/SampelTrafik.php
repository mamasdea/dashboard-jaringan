<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Deret waktu trafik interface uplink. Laju null beserta alasannya bila
 * tidak dapat dihitung (sampel pertama, penghitung reset, perangkat restart).
 *
 * @property int $id
 * @property int $interface_snmp_id
 * @property CarbonImmutable $diambil_pada
 * @property string|null $selang_detik
 * @property string|null $bps_masuk
 * @property string|null $bps_keluar
 * @property string|null $galat_masuk
 * @property string|null $galat_keluar
 * @property string|null $buang_masuk
 * @property string|null $buang_keluar
 * @property string|null $alasan_laju_kosong
 * @property int|null $status_operasional
 * @property bool $simulasi
 */
class SampelTrafik extends Model
{
    protected $table = 'sampel_trafik';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'diambil_pada' => 'immutable_datetime',
            'status_operasional' => 'integer',
            'simulasi' => 'boolean',
        ];
    }
}
