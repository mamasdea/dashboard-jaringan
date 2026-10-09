<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Interface perangkat menurut SNMP, dikenali dari namanya (ifName atau
 * ifDescr) dan dikuatkan alamat fisik. `if_index` hanya nomor urut terakhir.
 *
 * @property int $id
 * @property int $endpoint_id
 * @property string $nama
 * @property string|null $alamat_fisik
 * @property int $if_index
 * @property CarbonImmutable|null $if_index_berubah_pada
 * @property string|null $deskripsi
 * @property string|null $alias
 * @property int|null $tipe
 * @property int|null $kecepatan_bps
 * @property int|null $status_admin
 * @property int|null $status_operasional
 * @property bool $uplink
 * @property string|null $oktet_masuk
 * @property string|null $oktet_keluar
 * @property bool|null $penghitung_64_bit
 * @property string|null $galat_masuk
 * @property string|null $galat_keluar
 * @property string|null $buang_masuk
 * @property string|null $buang_keluar
 * @property int|null $diskontinuitas_tiktik
 * @property int|null $uptime_tiktik_saat_sampel
 * @property CarbonImmutable|null $sampel_pada
 * @property string|null $bps_masuk
 * @property string|null $bps_keluar
 * @property string|null $alasan_laju_kosong
 * @property string|null $sfp_rx_dbm
 * @property string|null $sfp_tx_dbm
 * @property string|null $sfp_suhu_c
 * @property string|null $sfp_sumber
 * @property CarbonImmutable $terlihat_terakhir_pada
 * @property bool $simulasi
 */
class InterfaceSnmp extends Model
{
    /** Nilai ifAdminStatus / ifOperStatus (IF-MIB). */
    public const array STATUS = [1 => 'up', 2 => 'down', 3 => 'testing', 4 => 'unknown', 5 => 'dormant', 6 => 'notPresent', 7 => 'lowerLayerDown'];

    protected $table = 'interface_snmp';

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
            'if_index' => 'integer',
            'if_index_berubah_pada' => 'immutable_datetime',
            'tipe' => 'integer',
            'kecepatan_bps' => 'integer',
            'status_admin' => 'integer',
            'status_operasional' => 'integer',
            'uplink' => 'boolean',
            'penghitung_64_bit' => 'boolean',
            'diskontinuitas_tiktik' => 'integer',
            'uptime_tiktik_saat_sampel' => 'integer',
            'sampel_pada' => 'immutable_datetime',
            'terlihat_terakhir_pada' => 'immutable_datetime',
            'simulasi' => 'boolean',
        ];
    }

    /**
     * @return HasMany<SampelTrafik, $this>
     */
    public function sampel(): HasMany
    {
        return $this->hasMany(SampelTrafik::class, 'interface_snmp_id');
    }

    public static function labelStatus(?int $status): string
    {
        return $status === null ? 'Tidak tersedia' : (self::STATUS[$status] ?? "tidak dikenal ({$status})");
    }
}
