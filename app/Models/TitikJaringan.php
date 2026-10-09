<?php

namespace App\Models;

use App\Concerns\BerlabelSimulasi;
use App\Concerns\DicatatAktivitasnya;
use App\Concerns\MemilikiStatusData;
use App\Contracts\SubjekAktivitas;
use App\Enums\AksiAktivitas;
use App\Enums\JenisTitikJaringan;
use App\Enums\StatusData;
use Database\Factories\TitikJaringanFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * ODC, ODP, closure, atau terminasi/OTB: tempat segmen kabel berujung.
 *
 * @property int $id
 * @property string $kode
 * @property string|null $nama
 * @property JenisTitikJaringan $jenis
 * @property string|null $lintang
 * @property string|null $bujur
 * @property int|null $tiang_id
 * @property int|null $opd_id
 * @property string|null $lokasi
 * @property int|null $kapasitas_port
 * @property string|null $catatan
 * @property StatusData $status_data
 * @property bool $simulasi
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Tiang|null $tiang
 * @property-read Opd|null $opd
 */
#[Fillable(['kode', 'nama', 'jenis', 'lintang', 'bujur', 'tiang_id', 'opd_id', 'lokasi', 'kapasitas_port', 'catatan', 'simulasi'])]
class TitikJaringan extends Model implements SubjekAktivitas
{
    /** @use HasFactory<TitikJaringanFactory> */
    use BerlabelSimulasi, DicatatAktivitasnya, HasFactory, MemilikiStatusData;

    protected $table = 'titik_jaringan';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'simulasi' => false,
    ];

    protected static function booted(): void
    {
        static::saved(function (self $titik): void {
            if ($titik->wasRecentlyCreated || $titik->wasChanged('kapasitas_port')) {
                $titik->sinkronkanPort();
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
            'jenis' => JenisTitikJaringan::class,
            'kapasitas_port' => 'integer',
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
            'dibuat' => AksiAktivitas::TitikJaringanDibuat,
            'diubah' => AksiAktivitas::TitikJaringanDiubah,
            'dihapus' => AksiAktivitas::TitikJaringanDihapus,
        ];
    }

    public function labelAktivitas(): string
    {
        return "{$this->kode} ({$this->jenis->label()})";
    }

    public function kekurangan(): array
    {
        return array_keys(array_filter([
            'lokasi (koordinat, tiang, atau OPD)' => ($this->lintang === null || $this->bujur === null)
                && $this->tiang_id === null
                && $this->opd_id === null,
            'kapasitas port' => $this->jenis->berPort() && $this->kapasitas_port === null,
        ]));
    }

    /**
     * Menyamakan baris port terminasi dengan kapasitas. Pengurangan yang
     * mengenai port terpakai sudah ditolak validasi; database menolaknya juga.
     */
    public function sinkronkanPort(): void
    {
        $kapasitas = (int) $this->kapasitas_port;
        $ada = $this->port()->pluck('nomor')->all();

        foreach (array_diff($kapasitas > 0 ? range(1, $kapasitas) : [], $ada) as $nomor) {
            $this->port()->create(['nomor' => $nomor]);
        }

        $this->port()->where('nomor', '>', $kapasitas)->get()->each->delete();
    }

    /**
     * @return BelongsTo<Tiang, $this>
     */
    public function tiang(): BelongsTo
    {
        return $this->belongsTo(Tiang::class, 'tiang_id');
    }

    /**
     * @return BelongsTo<Opd, $this>
     */
    public function opd(): BelongsTo
    {
        return $this->belongsTo(Opd::class, 'opd_id');
    }

    /**
     * @return HasMany<PortTerminasi, $this>
     */
    public function port(): HasMany
    {
        return $this->hasMany(PortTerminasi::class, 'titik_jaringan_id')->orderBy('nomor');
    }

    /**
     * @return HasMany<Sambungan, $this>
     */
    public function sambungan(): HasMany
    {
        return $this->hasMany(Sambungan::class, 'titik_jaringan_id');
    }

    /**
     * Segmen kabel yang berujung di titik ini (sebagai awal maupun akhir).
     *
     * @return Builder<SegmenKabel>
     */
    public function segmen(): Builder
    {
        return SegmenKabel::query()
            ->where(fn (Builder $query) => $query->where('titik_awal_id', $this->id)->orWhere('titik_akhir_id', $this->id));
    }
}
