<?php

namespace App\Models;

use App\Concerns\BerlabelSimulasi;
use App\Concerns\DicatatAktivitasnya;
use App\Concerns\MemilikiStatusData;
use App\Contracts\SubjekAktivitas;
use App\Enums\AksiAktivitas;
use App\Enums\KondisiTiang;
use App\Enums\PemilikTiang;
use App\Enums\StatusData;
use Database\Factories\TiangFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property string $kode
 * @property string|null $lintang
 * @property string|null $bujur
 * @property PemilikTiang|null $pemilik
 * @property KondisiTiang|null $kondisi
 * @property string|null $foto_path
 * @property string|null $catatan
 * @property StatusData $status_data
 * @property bool $simulasi
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['kode', 'lintang', 'bujur', 'pemilik', 'kondisi', 'catatan', 'simulasi'])]
class Tiang extends Model implements SubjekAktivitas
{
    /** @use HasFactory<TiangFactory> */
    use BerlabelSimulasi, DicatatAktivitasnya, HasFactory, MemilikiStatusData;

    public const string DISK_FOTO = 'local';

    public const string FOLDER_FOTO = 'foto-tiang';

    protected $table = 'tiang';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'simulasi' => false,
    ];

    protected static function booted(): void
    {
        static::deleted(function (self $tiang): void {
            if ($tiang->foto_path !== null) {
                Storage::disk(self::DISK_FOTO)->delete($tiang->foto_path);
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
            'pemilik' => PemilikTiang::class,
            'kondisi' => KondisiTiang::class,
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
            'dibuat' => AksiAktivitas::TiangDibuat,
            'diubah' => AksiAktivitas::TiangDiubah,
            'dihapus' => AksiAktivitas::TiangDihapus,
        ];
    }

    public function labelAktivitas(): string
    {
        return $this->kode;
    }

    public function kekurangan(): array
    {
        return array_keys(array_filter([
            'koordinat' => $this->lintang === null || $this->bujur === null,
            'pemilik' => $this->pemilik === null,
            'kondisi' => $this->kondisi === null,
        ]));
    }

    /**
     * Segmen kabel yang melewati tiang ini.
     *
     * @return BelongsToMany<SegmenKabel, $this>
     */
    public function segmen(): BelongsToMany
    {
        return $this->belongsToMany(SegmenKabel::class, 'tiang_segmen', 'tiang_id', 'segmen_kabel_id')->withPivot('urutan');
    }

    /**
     * @return HasMany<TitikJaringan, $this>
     */
    public function titikJaringan(): HasMany
    {
        return $this->hasMany(TitikJaringan::class, 'tiang_id');
    }
}
