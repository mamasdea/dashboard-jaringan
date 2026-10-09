<?php

namespace App\Models;

use App\Enums\StatusPemakaian;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Port pada ODC, ODP, atau OTB. Sisi belakang menerima satu core
 * (`core_id`); sisi depan dihubungkan ke port perangkat lewat koneksi endpoint.
 *
 * @property int $id
 * @property int $titik_jaringan_id
 * @property int $nomor
 * @property StatusPemakaian $status
 * @property int|null $core_id
 * @property string|null $catatan
 * @property-read TitikJaringan $titik
 * @property-read CoreKabel|null $core
 * @property-read KoneksiEndpoint|null $koneksiEndpoint
 */
#[Fillable(['nomor', 'status', 'catatan'])]
class PortTerminasi extends Model
{
    protected $table = 'port_terminasi';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'tersedia',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nomor' => 'integer',
            'status' => StatusPemakaian::class,
        ];
    }

    /**
     * @return BelongsTo<TitikJaringan, $this>
     */
    public function titik(): BelongsTo
    {
        return $this->belongsTo(TitikJaringan::class, 'titik_jaringan_id');
    }

    /**
     * @return BelongsTo<CoreKabel, $this>
     */
    public function core(): BelongsTo
    {
        return $this->belongsTo(CoreKabel::class, 'core_id');
    }

    /**
     * @return HasOne<KoneksiEndpoint, $this>
     */
    public function koneksiEndpoint(): HasOne
    {
        return $this->hasOne(KoneksiEndpoint::class, 'port_terminasi_id');
    }

    public function label(): string
    {
        return "{$this->titik->kode} port {$this->nomor}";
    }
}
