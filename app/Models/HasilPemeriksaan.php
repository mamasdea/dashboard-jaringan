<?php

namespace App\Models;

use App\Enums\KeluaranPemeriksaan;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu hasil pemeriksaan (deret waktu). Ditulis lewat
 * App\Monitoring\LayananStatus::terimaHasil().
 *
 * @property int $id
 * @property int $endpoint_id
 * @property string $id_pemeriksaan
 * @property CarbonImmutable $diperiksa_pada Jam kolektor saat pemeriksaan dimulai (UTC).
 * @property CarbonImmutable $diterima_pada Jam server saat hasil diterima (UTC).
 * @property KeluaranPemeriksaan $keluaran
 * @property string|null $latency_ms
 * @property string|null $packet_loss_persen
 * @property string|null $kode_galat
 * @property string|null $pesan
 * @property KeluaranPemeriksaan|null $keluaran_snmp
 * @property string|null $kode_galat_snmp
 * @property string|null $pesan_snmp
 * @property string $sumber
 * @property bool $diterapkan
 * @property string|null $alasan_tidak_diterapkan
 * @property bool $simulasi
 */
class HasilPemeriksaan extends Model
{
    public const string TERLAMBAT = 'terlambat';

    public const string PEMELIHARAAN = 'pemeliharaan';

    public const string WAKTU_DI_DEPAN = 'waktu_di_depan';

    protected $table = 'hasil_pemeriksaan';

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
            'diperiksa_pada' => 'immutable_datetime',
            'diterima_pada' => 'immutable_datetime',
            'keluaran' => KeluaranPemeriksaan::class,
            'keluaran_snmp' => KeluaranPemeriksaan::class,
            'diterapkan' => 'boolean',
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
}
