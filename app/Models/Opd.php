<?php

namespace App\Models;

use App\Concerns\BerlabelSimulasi;
use App\Concerns\DicatatAktivitasnya;
use App\Contracts\SubjekAktivitas;
use App\Enums\AksiAktivitas;
use Database\Factories\OpdFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $kode
 * @property string $nama
 * @property string|null $alamat
 * @property string|null $lintang
 * @property string|null $bujur
 * @property string|null $kontak_teknis_nama
 * @property string|null $kontak_teknis_telepon
 * @property string|null $kontak_teknis_email
 * @property bool $aktif
 * @property bool $simulasi
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Perangkat|null $switchUtama
 */
#[Fillable([
    'kode', 'nama', 'alamat', 'lintang', 'bujur',
    'kontak_teknis_nama', 'kontak_teknis_telepon', 'kontak_teknis_email',
    'aktif', 'simulasi',
])]
class Opd extends Model implements SubjekAktivitas
{
    /** @use HasFactory<OpdFactory> */
    use BerlabelSimulasi, DicatatAktivitasnya, HasFactory;

    protected $table = 'opd';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'aktif' => true,
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
            'aktif' => 'boolean',
            'simulasi' => 'boolean',
        ];
    }

    public static function aksiAktivitas(): array
    {
        return [
            'dibuat' => AksiAktivitas::OpdDibuat,
            'diubah' => AksiAktivitas::OpdDiubah,
            'dihapus' => AksiAktivitas::OpdDihapus,
        ];
    }

    public function labelAktivitas(): string
    {
        return "{$this->kode} — {$this->nama}";
    }

    /**
     * @return HasMany<Perangkat, $this>
     */
    public function perangkat(): HasMany
    {
        return $this->hasMany(Perangkat::class, 'opd_id');
    }

    /**
     * @return HasOne<Perangkat, $this>
     */
    public function switchUtama(): HasOne
    {
        return $this->hasOne(Perangkat::class, 'opd_id')->where('switch_utama', true);
    }
}
