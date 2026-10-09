<?php

namespace App\Models;

use App\Concerns\BerlabelSimulasi;
use App\Support\BrankasKredensial;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Profil akses SNMP. Kredensialnya tersimpan tersegel dan terpisah dari tabel
 * perangkat; tidak pernah ikut diserialkan, dicatat ke log, atau ditampilkan.
 *
 * @property int $id
 * @property string $nama
 * @property string $versi
 * @property int $port
 * @property string $kredensial_tersegel
 * @property string|null $catatan
 * @property bool $simulasi
 */
#[Fillable(['nama', 'versi', 'port', 'catatan', 'simulasi'])]
#[Hidden(['kredensial_tersegel'])]
class ProfilSnmp extends Model
{
    use BerlabelSimulasi;

    /**
     * Kunci kredensial yang wajib ada per versi SNMP.
     */
    private const array KUNCI_WAJIB = [
        'v2c' => ['community'],
        'v3' => ['pengguna', 'tingkat_keamanan'],
    ];

    protected $table = 'profil_snmp';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'port' => 161,
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
            'port' => 'integer',
            'simulasi' => 'boolean',
        ];
    }

    /**
     * Menyegel kredensial. Cukup dengan kunci publik.
     *
     * v2c: ['community' => ...]
     * v3:  ['pengguna', 'tingkat_keamanan', 'protokol_auth', 'sandi_auth', 'protokol_priv', 'sandi_priv']
     *
     * @param  array<string, string|null>  $kredensial
     */
    public function aturKredensial(#[SensitiveParameter] array $kredensial): static
    {
        foreach (self::KUNCI_WAJIB[$this->versi] ?? throw new InvalidArgumentException('Versi SNMP belum diatur atau tidak dikenal.') as $kunci) {
            if (blank($kredensial[$kunci] ?? null)) {
                throw new InvalidArgumentException("Kredensial SNMP {$this->versi} membutuhkan '{$kunci}'.");
            }
        }

        $this->kredensial_tersegel = app(BrankasKredensial::class)->segel($kredensial);

        return $this;
    }

    /**
     * Membuka kredensial. Hanya berhasil di host yang memegang kunci privat
     * (kolektor); jangan dipanggil dari kode web.
     *
     * @return array<string, mixed>
     */
    public function kredensial(): array
    {
        return app(BrankasKredensial::class)->buka($this->kredensial_tersegel);
    }

    /**
     * @return HasMany<EndpointMonitoring, $this>
     */
    public function endpoint(): HasMany
    {
        return $this->hasMany(EndpointMonitoring::class, 'profil_snmp_id');
    }
}
