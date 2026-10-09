<?php

namespace App\Models;

use App\Concerns\DicatatAktivitasnya;
use App\Contracts\SubjekAktivitas;
use App\Enums\AksiAktivitas;
use App\Enums\PrioritasTiket;
use App\Enums\StatusTiket;
use Carbon\CarbonImmutable;
use Database\Factories\TiketFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Tiket gangguan. Statusnya adalah status pekerjaan, bukan status monitoring.
 * Dibuat dan diubah lewat App\Tiket\LayananTiket.
 *
 * @property int $id
 * @property string $nomor
 * @property string $judul
 * @property int|null $opd_id
 * @property int|null $perangkat_id
 * @property int|null $segmen_kabel_id
 * @property int|null $titik_jaringan_id
 * @property int|null $tiang_id
 * @property int|null $endpoint_id
 * @property int|null $insiden_id
 * @property string $sumber
 * @property CarbonImmutable $waktu_mulai
 * @property CarbonImmutable|null $monitoring_pulih_pada
 * @property CarbonImmutable|null $waktu_pemulihan
 * @property PrioritasTiket $prioritas
 * @property StatusTiket $status
 * @property int|null $teknisi_id
 * @property int|null $dibuat_oleh
 * @property string|null $dugaan_penyebab Perkiraan awal; belum diperiksa di lapangan.
 * @property string|null $penyebab_terverifikasi Hasil pemeriksaan; hanya diisi manusia.
 * @property bool $simulasi
 * @property-read Opd|null $opd
 * @property-read Perangkat|null $perangkat
 * @property-read SegmenKabel|null $segmenKabel
 * @property-read TitikJaringan|null $titikJaringan
 * @property-read Tiang|null $tiang
 * @property-read Insiden|null $insiden
 * @property-read User|null $teknisi
 * @property-read EndpointMonitoring|null $endpoint
 */
#[Fillable([
    'judul', 'opd_id', 'perangkat_id', 'segmen_kabel_id', 'titik_jaringan_id', 'tiang_id', 'endpoint_id', 'insiden_id', 'sumber',
    'waktu_mulai', 'monitoring_pulih_pada', 'waktu_pemulihan', 'prioritas', 'status', 'teknisi_id', 'dibuat_oleh',
    'dugaan_penyebab', 'penyebab_terverifikasi', 'simulasi',
])]
class Tiket extends Model implements SubjekAktivitas
{
    /** @use HasFactory<TiketFactory> */
    use DicatatAktivitasnya, HasFactory;

    public const string OTOMATIS = 'otomatis';

    public const string MANUAL = 'manual';

    public const string DISK_FOTO = 'local';

    public const string FOLDER_FOTO = 'foto-tiket';

    protected $table = 'tiket';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'sumber' => 'manual',
        'prioritas' => 'sedang',
        'status' => 'baru',
        'simulasi' => false,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $tiket): void {
            if ($tiket->simulasi && ! str_starts_with($tiket->judul, '[SIMULASI]')) {
                $tiket->judul = '[SIMULASI] '.$tiket->judul;
            }
        });

        // Nomor memakai id, sehingga unik tanpa penghitung terpisah. Id diambil
        // dari sequence sebelum baris ditulis supaya nomor tidak pernah kosong.
        static::creating(function (self $tiket): void {
            $tiket->id ??= (int) DB::scalar("SELECT nextval(pg_get_serial_sequence('tiket', 'id'))");
            $tiket->nomor = sprintf('TKT-%s-%05d', $tiket->waktu_mulai->timezone((string) config('app.display_timezone'))->format('Y'), $tiket->id);
        });

        static::deleting(function (self $tiket): void {
            $foto = $tiket->catatan()->whereNotNull('foto_path')->pluck('foto_path')->all();

            if ($foto !== []) {
                Storage::disk(self::DISK_FOTO)->delete($foto);
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
            'waktu_mulai' => 'immutable_datetime',
            'monitoring_pulih_pada' => 'immutable_datetime',
            'waktu_pemulihan' => 'immutable_datetime',
            'prioritas' => PrioritasTiket::class,
            'status' => StatusTiket::class,
            'simulasi' => 'boolean',
        ];
    }

    public static function aksiAktivitas(): array
    {
        return [
            'dibuat' => AksiAktivitas::TiketDibuat,
            'diubah' => AksiAktivitas::TiketDiubah,
            'dihapus' => AksiAktivitas::TiketDihapus,
        ];
    }

    public function labelAktivitas(): string
    {
        return $this->nomor.' — '.$this->judul;
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeTerbuka(Builder $query): void
    {
        $query->whereIn($this->qualifyColumn('status'), StatusTiket::nilaiTerbuka());
    }

    /**
     * Aset FO yang dikaitkan (paling banyak satu).
     */
    public function asetFo(): SegmenKabel|TitikJaringan|Tiang|null
    {
        return $this->segmenKabel ?? $this->titikJaringan ?? $this->tiang;
    }

    /**
     * @return BelongsTo<Opd, $this>
     */
    public function opd(): BelongsTo
    {
        return $this->belongsTo(Opd::class, 'opd_id');
    }

    /**
     * @return BelongsTo<Perangkat, $this>
     */
    public function perangkat(): BelongsTo
    {
        return $this->belongsTo(Perangkat::class, 'perangkat_id');
    }

    /**
     * @return BelongsTo<SegmenKabel, $this>
     */
    public function segmenKabel(): BelongsTo
    {
        return $this->belongsTo(SegmenKabel::class, 'segmen_kabel_id');
    }

    /**
     * @return BelongsTo<TitikJaringan, $this>
     */
    public function titikJaringan(): BelongsTo
    {
        return $this->belongsTo(TitikJaringan::class, 'titik_jaringan_id');
    }

    /**
     * @return BelongsTo<Tiang, $this>
     */
    public function tiang(): BelongsTo
    {
        return $this->belongsTo(Tiang::class, 'tiang_id');
    }

    /**
     * @return BelongsTo<Insiden, $this>
     */
    public function insiden(): BelongsTo
    {
        return $this->belongsTo(Insiden::class, 'insiden_id');
    }

    /**
     * @return BelongsTo<EndpointMonitoring, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(EndpointMonitoring::class, 'endpoint_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function teknisi(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teknisi_id');
    }

    /**
     * @return HasMany<CatatanTiket, $this>
     */
    public function catatan(): HasMany
    {
        return $this->hasMany(CatatanTiket::class, 'tiket_id')->orderByDesc('id');
    }
}
