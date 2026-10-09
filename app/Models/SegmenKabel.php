<?php

namespace App\Models;

use App\Concerns\BerlabelSimulasi;
use App\Concerns\DicatatAktivitasnya;
use App\Concerns\MemilikiStatusData;
use App\Contracts\SubjekAktivitas;
use App\Enums\AksiAktivitas;
use App\Enums\KlasifikasiKabel;
use App\Enums\StatusData;
use App\Support\Geo;
use Database\Factories\SegmenKabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Satu bentang kabel antara dua titik jaringan. Backbone atau distribusi
 * adalah klasifikasinya, bukan jenis objek yang berbeda.
 *
 * @property int $id
 * @property string $kode
 * @property string|null $nama
 * @property KlasifikasiKabel|null $klasifikasi
 * @property int|null $jumlah_core
 * @property int|null $titik_awal_id
 * @property int|null $titik_akhir_id
 * @property string|null $panjang_ukur_m Hasil pengukuran lapangan.
 * @property string|null $panjang_peta_m Hasil perhitungan dari jalur_peta; tidak diisi manual.
 * @property list<array{0: float, 1: float}>|null $jalur_peta Titik [bujur, lintang].
 * @property string|null $catatan
 * @property StatusData $status_data
 * @property bool $simulasi
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read TitikJaringan|null $titikAwal
 * @property-read TitikJaringan|null $titikAkhir
 */
#[Fillable(['kode', 'nama', 'klasifikasi', 'jumlah_core', 'titik_awal_id', 'titik_akhir_id', 'panjang_ukur_m', 'jalur_peta', 'catatan', 'simulasi'])]
class SegmenKabel extends Model implements SubjekAktivitas
{
    /** @use HasFactory<SegmenKabelFactory> */
    use BerlabelSimulasi, DicatatAktivitasnya, HasFactory, MemilikiStatusData;

    protected $table = 'segmen_kabel';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'simulasi' => false,
    ];

    protected static function booted(): void
    {
        static::saving(function (self $segmen): void {
            if ($segmen->isDirty('jalur_peta')) {
                $segmen->panjang_peta_m = ($panjang = Geo::panjangJalurMeter($segmen->jalur_peta)) === null ? null : (string) $panjang;
            }
        });

        static::saved(function (self $segmen): void {
            if ($segmen->wasRecentlyCreated || $segmen->wasChanged('jumlah_core')) {
                $segmen->sinkronkanCore();
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
            'klasifikasi' => KlasifikasiKabel::class,
            'jumlah_core' => 'integer',
            'jalur_peta' => 'array',
            'status_data' => StatusData::class,
            'simulasi' => 'boolean',
        ];
    }

    public static function kolomLabelSimulasi(): string
    {
        return 'kode';
    }

    public static function aksiAktivitas(): array
    {
        return [
            'dibuat' => AksiAktivitas::SegmenKabelDibuat,
            'diubah' => AksiAktivitas::SegmenKabelDiubah,
            'dihapus' => AksiAktivitas::SegmenKabelDihapus,
        ];
    }

    public function labelAktivitas(): string
    {
        return $this->kode;
    }

    public function kekurangan(): array
    {
        return array_keys(array_filter([
            'klasifikasi' => $this->klasifikasi === null,
            'jumlah core' => $this->jumlah_core === null,
            'titik awal' => $this->titik_awal_id === null,
            'titik akhir' => $this->titik_akhir_id === null,
            'panjang hasil pengukuran' => $this->panjang_ukur_m === null,
            'jalur peta' => count($this->jalur_peta ?? []) < 2,
        ]));
    }

    /**
     * Menyamakan baris core dengan jumlah_core. Pengurangan yang mengenai core
     * terpakai sudah ditolak validasi; database menolaknya juga.
     */
    public function sinkronkanCore(): void
    {
        $jumlah = (int) $this->jumlah_core;
        $ada = $this->core()->pluck('nomor')->all();

        foreach (array_diff($jumlah > 0 ? range(1, $jumlah) : [], $ada) as $nomor) {
            $this->core()->create([
                'nomor' => $nomor,
                'tube' => CoreKabel::tubeUntuk($nomor),
                'warna' => CoreKabel::warnaUntuk($nomor),
            ]);
        }

        $this->core()->where('nomor', '>', $jumlah)->get()->each->delete();
    }

    /**
     * @return BelongsTo<TitikJaringan, $this>
     */
    public function titikAwal(): BelongsTo
    {
        return $this->belongsTo(TitikJaringan::class, 'titik_awal_id');
    }

    /**
     * @return BelongsTo<TitikJaringan, $this>
     */
    public function titikAkhir(): BelongsTo
    {
        return $this->belongsTo(TitikJaringan::class, 'titik_akhir_id');
    }

    /**
     * @return HasMany<CoreKabel, $this>
     */
    public function core(): HasMany
    {
        return $this->hasMany(CoreKabel::class, 'segmen_kabel_id')->orderBy('nomor');
    }

    /**
     * Tiang yang dilalui, berurutan dari titik awal ke titik akhir.
     *
     * @return BelongsToMany<Tiang, $this>
     */
    public function tiang(): BelongsToMany
    {
        return $this->belongsToMany(Tiang::class, 'tiang_segmen', 'segmen_kabel_id', 'tiang_id')
            ->withPivot('urutan')
            ->orderByPivot('urutan');
    }

    public function berujungDi(TitikJaringan|int $titik): bool
    {
        $id = $titik instanceof TitikJaringan ? $titik->id : $titik;

        return in_array($id, [$this->titik_awal_id, $this->titik_akhir_id], true);
    }
}
