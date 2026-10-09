<?php

namespace App\Models;

use App\Concerns\BerlabelSimulasi;
use App\Concerns\DicatatAktivitasnya;
use App\Contracts\SubjekAktivitas;
use App\Enums\AksiAktivitas;
use App\Enums\JenisPerangkat;
use App\Enums\PenempatanPerangkat;
use Database\Factories\PerangkatFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * Perangkat jaringan aktif. Kredensial SNMP tidak disimpan di sini.
 *
 * @property int $id
 * @property int $opd_id
 * @property PenempatanPerangkat $penempatan
 * @property string|null $lokasi
 * @property string $kode_aset
 * @property string $nama
 * @property JenisPerangkat $jenis
 * @property string|null $merek
 * @property string|null $model
 * @property string|null $nomor_seri
 * @property string|null $ip_manajemen
 * @property string|null $port_uplink
 * @property bool $monitoring_aktif
 * @property bool $switch_utama
 * @property string|null $foto_path
 * @property string|null $catatan
 * @property bool $simulasi
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Opd $opd
 * @property-read EndpointMonitoring|null $endpoint
 */
#[Fillable([
    'opd_id', 'penempatan', 'lokasi', 'kode_aset', 'nama', 'jenis', 'merek', 'model', 'nomor_seri',
    'ip_manajemen', 'port_uplink', 'monitoring_aktif', 'switch_utama', 'catatan', 'simulasi',
])]
class Perangkat extends Model implements SubjekAktivitas
{
    /** @use HasFactory<PerangkatFactory> */
    use BerlabelSimulasi, DicatatAktivitasnya, HasFactory;

    public const string DISK_FOTO = 'local';

    public const string FOLDER_FOTO = 'foto-perangkat';

    protected $table = 'perangkat';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'penempatan' => 'opd',
        'monitoring_aktif' => false,
        'switch_utama' => false,
        'simulasi' => false,
    ];

    protected static function booted(): void
    {
        // Endpoint monitoring ada dan aktif selama monitoring perangkat diaktifkan.
        static::saved(function (self $perangkat): void {
            if ($perangkat->wasRecentlyCreated || $perangkat->wasChanged(['monitoring_aktif', 'ip_manajemen', 'kode_aset', 'nama', 'simulasi'])) {
                EndpointMonitoring::sinkronkanDari($perangkat);
            }
        });

        // Riwayat monitoringnya dipertahankan; endpoint-nya berhenti diperiksa.
        static::deleting(function (self $perangkat): void {
            EndpointMonitoring::query()->where('perangkat_id', $perangkat->id)->update(['aktif' => false]);
        });

        static::deleted(function (self $perangkat): void {
            if ($perangkat->foto_path !== null) {
                Storage::disk(self::DISK_FOTO)->delete($perangkat->foto_path);
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'penempatan' => PenempatanPerangkat::class,
            'jenis' => JenisPerangkat::class,
            'monitoring_aktif' => 'boolean',
            'switch_utama' => 'boolean',
            'simulasi' => 'boolean',
        ];
    }

    public static function aksiAktivitas(): array
    {
        return [
            'dibuat' => AksiAktivitas::PerangkatDibuat,
            'diubah' => AksiAktivitas::PerangkatDiubah,
            'dihapus' => AksiAktivitas::PerangkatDihapus,
        ];
    }

    public function labelAktivitas(): string
    {
        return "{$this->kode_aset} — {$this->nama}";
    }

    /**
     * @return BelongsTo<Opd, $this>
     */
    public function opd(): BelongsTo
    {
        return $this->belongsTo(Opd::class, 'opd_id');
    }

    /**
     * @return HasOne<EndpointMonitoring, $this>
     */
    public function endpoint(): HasOne
    {
        return $this->hasOne(EndpointMonitoring::class, 'perangkat_id');
    }

    /**
     * @return HasMany<PortPerangkat, $this>
     */
    public function port(): HasMany
    {
        return $this->hasMany(PortPerangkat::class, 'perangkat_id')->orderBy('nama');
    }

    /**
     * Melepas penanda switch utama dari perangkat lain di OPD yang sama.
     */
    public function lepasSwitchUtamaLain(): void
    {
        self::query()
            ->where('opd_id', $this->opd_id)
            ->where('switch_utama', true)
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
            ->get()
            ->each(fn (self $lain) => $lain->update(['switch_utama' => false]));
    }
}
