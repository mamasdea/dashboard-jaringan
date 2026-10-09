<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\EndpointMonitoringFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Sasaran pemeriksaan. Dibuat dari perangkat yang monitoringnya diaktifkan.
 *
 * @property int $id
 * @property int|null $perangkat_id
 * @property string $nama
 * @property string $alamat
 * @property string $metode
 * @property int|null $profil_snmp_id
 * @property int $interval_detik
 * @property string|null $ambang_latency_ms
 * @property string|null $ambang_packet_loss_persen
 * @property bool $aktif
 * @property bool $pemeliharaan
 * @property CarbonImmutable|null $pemeliharaan_sampai
 * @property string|null $pemeliharaan_catatan
 * @property CarbonImmutable $berikutnya_pada
 * @property CarbonImmutable|null $sedang_diperiksa_sejak
 * @property string|null $klaim
 * @property string|null $port_uplink
 * @property string|null $skenario_simulasi
 * @property bool $simulasi
 * @property-read StatusTerkini|null $status
 * @property-read Perangkat|null $perangkat
 * @property-read ProfilSnmp|null $profilSnmp
 */
#[Fillable([
    'perangkat_id', 'nama', 'alamat', 'metode', 'profil_snmp_id', 'interval_detik',
    'ambang_latency_ms', 'ambang_packet_loss_persen', 'aktif', 'port_uplink', 'skenario_simulasi', 'simulasi',
])]
class EndpointMonitoring extends Model
{
    /** @use HasFactory<EndpointMonitoringFactory> */
    use HasFactory;

    protected $table = 'endpoint_monitoring';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'metode' => 'icmp',
        'aktif' => true,
        'pemeliharaan' => false,
        'simulasi' => false,
    ];

    protected static function booted(): void
    {
        static::creating(function (self $endpoint): void {
            $endpoint->interval_detik ??= (int) config('monitoring.interval_detik');
            $endpoint->berikutnya_pada ??= CarbonImmutable::now();
        });

        // Setiap endpoint selalu punya satu baris status, bermula "Belum diketahui".
        static::created(function (self $endpoint): void {
            $endpoint->status()->create(['sejak' => now(), 'simulasi' => $endpoint->simulasi]);
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
            'interval_detik' => 'integer',
            'aktif' => 'boolean',
            'pemeliharaan' => 'boolean',
            'pemeliharaan_sampai' => 'immutable_datetime',
            'berikutnya_pada' => 'immutable_datetime',
            'sedang_diperiksa_sejak' => 'immutable_datetime',
            'simulasi' => 'boolean',
        ];
    }

    /**
     * Menyelaraskan endpoint dengan perangkatnya: ada dan aktif selama
     * monitoring perangkat diaktifkan dan IP manajemennya terisi.
     */
    public static function sinkronkanDari(Perangkat $perangkat): ?self
    {
        $endpoint = self::query()->where('perangkat_id', $perangkat->id)->first();
        $dipantau = $perangkat->monitoring_aktif && $perangkat->ip_manajemen !== null;

        if ($endpoint === null && ! $dipantau) {
            return null;
        }

        $endpoint ??= new self(['perangkat_id' => $perangkat->id]);

        $endpoint->nama = "{$perangkat->kode_aset} — {$perangkat->nama}";
        $endpoint->aktif = $dipantau;
        $endpoint->simulasi = $perangkat->simulasi;

        if ($perangkat->ip_manajemen !== null) {
            $endpoint->alamat = $perangkat->ip_manajemen;
        }

        $endpoint->save();

        return $endpoint;
    }

    public function ambangLatencyMs(): float
    {
        return (float) ($this->ambang_latency_ms ?? config('monitoring.ambang.latency_ms'));
    }

    public function ambangPacketLossPersen(): float
    {
        return (float) ($this->ambang_packet_loss_persen ?? config('monitoring.ambang.packet_loss_persen'));
    }

    /**
     * Hasil yang lebih tua dari ini tidak lagi menggambarkan keadaan sekarang.
     */
    public function kedaluwarsaDetik(): int
    {
        return $this->interval_detik * max(1, (int) config('monitoring.kedaluwarsa_kali_interval'));
    }

    public function dalamPemeliharaan(?CarbonInterface $pada = null): bool
    {
        return $this->pemeliharaan
            && ($this->pemeliharaan_sampai === null || $this->pemeliharaan_sampai->greaterThan($pada ?? now()));
    }

    /**
     * @return BelongsTo<Perangkat, $this>
     */
    public function perangkat(): BelongsTo
    {
        return $this->belongsTo(Perangkat::class, 'perangkat_id');
    }

    /**
     * @return BelongsTo<ProfilSnmp, $this>
     */
    public function profilSnmp(): BelongsTo
    {
        return $this->belongsTo(ProfilSnmp::class, 'profil_snmp_id');
    }

    /**
     * @return HasOne<StatusTerkini, $this>
     */
    public function status(): HasOne
    {
        return $this->hasOne(StatusTerkini::class, 'endpoint_id');
    }

    /**
     * @return HasOne<SnmpPerangkat, $this>
     */
    public function snmp(): HasOne
    {
        return $this->hasOne(SnmpPerangkat::class, 'endpoint_id');
    }

    /**
     * @return HasMany<InterfaceSnmp, $this>
     */
    public function interface(): HasMany
    {
        return $this->hasMany(InterfaceSnmp::class, 'endpoint_id')->orderBy('if_index');
    }

    /**
     * @return HasMany<HasilPemeriksaan, $this>
     */
    public function hasil(): HasMany
    {
        return $this->hasMany(HasilPemeriksaan::class, 'endpoint_id');
    }

    /**
     * @return HasMany<Insiden, $this>
     */
    public function insiden(): HasMany
    {
        return $this->hasMany(Insiden::class, 'endpoint_id');
    }

    /**
     * @return HasMany<RiwayatStatus, $this>
     */
    public function riwayatStatus(): HasMany
    {
        return $this->hasMany(RiwayatStatus::class, 'endpoint_id');
    }
}
