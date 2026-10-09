<?php

namespace App\Models;

use App\Enums\KeluaranPemeriksaan;
use App\Enums\StatusMonitoring;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu baris per endpoint: hasil evaluasi terakhir beserta penghitungnya.
 *
 * Kolom `status` adalah status hasil evaluasi. Yang ditampilkan kepada orang
 * adalah statusTampil(), yang juga memperhitungkan umur data dan pemeliharaan.
 *
 * @property int $id
 * @property int $endpoint_id
 * @property StatusMonitoring $status
 * @property CarbonImmutable $sejak
 * @property int $gagal_berturut
 * @property int $berhasil_berturut
 * @property int $galat_kolektor_berturut
 * @property CarbonImmutable|null $diperiksa_terakhir_pada
 * @property CarbonImmutable|null $hasil_valid_terakhir_pada
 * @property CarbonImmutable|null $diterima_terakhir_pada
 * @property string|null $latency_ms
 * @property string|null $packet_loss_persen
 * @property KeluaranPemeriksaan|null $snmp_keluaran
 * @property string|null $snmp_kode_galat
 * @property int $snmp_gagal_berturut
 * @property CarbonImmutable|null $snmp_berhasil_terakhir_pada
 * @property bool $simulasi
 * @property-read EndpointMonitoring $endpoint
 */
class StatusTerkini extends Model
{
    protected $table = 'status_terkini';

    protected $guarded = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'belum_diketahui',
        'gagal_berturut' => 0,
        'berhasil_berturut' => 0,
        'galat_kolektor_berturut' => 0,
        'snmp_gagal_berturut' => 0,
        'simulasi' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => StatusMonitoring::class,
            'sejak' => 'immutable_datetime',
            'gagal_berturut' => 'integer',
            'berhasil_berturut' => 'integer',
            'galat_kolektor_berturut' => 'integer',
            'diperiksa_terakhir_pada' => 'immutable_datetime',
            'hasil_valid_terakhir_pada' => 'immutable_datetime',
            'diterima_terakhir_pada' => 'immutable_datetime',
            'snmp_keluaran' => KeluaranPemeriksaan::class,
            'snmp_gagal_berturut' => 'integer',
            'snmp_berhasil_terakhir_pada' => 'immutable_datetime',
            'simulasi' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<EndpointMonitoring, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(EndpointMonitoring::class, 'endpoint_id');
    }

    public function kedaluwarsa(?CarbonInterface $pada = null): bool
    {
        return $this->hasil_valid_terakhir_pada === null
            || $this->hasil_valid_terakhir_pada->diffInSeconds($pada ?? now(), absolute: false) > $this->endpoint->kedaluwarsaDetik();
    }

    /**
     * Status yang boleh ditampilkan. Dihitung saat dibaca, sehingga kolektor
     * yang mati tidak pernah membuat status lama tampak masih berlaku.
     */
    public function statusTampil(?CarbonInterface $pada = null): StatusMonitoring
    {
        return match (true) {
            $this->endpoint->dalamPemeliharaan($pada) => StatusMonitoring::Pemeliharaan,
            ! $this->endpoint->aktif, $this->kedaluwarsa($pada) => StatusMonitoring::BelumDiketahui,
            // Pemeliharaan yang masanya habis tetapi belum disapu.
            $this->status === StatusMonitoring::Pemeliharaan => StatusMonitoring::BelumDiketahui,
            default => $this->status,
        };
    }
}
