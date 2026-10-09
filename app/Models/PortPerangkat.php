<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property int $perangkat_id
 * @property string $nama
 * @property string|null $catatan
 * @property-read Perangkat $perangkat
 * @property-read KoneksiEndpoint|null $koneksiEndpoint
 */
#[Fillable(['perangkat_id', 'nama', 'catatan'])]
class PortPerangkat extends Model
{
    protected $table = 'port_perangkat';

    /**
     * @return BelongsTo<Perangkat, $this>
     */
    public function perangkat(): BelongsTo
    {
        return $this->belongsTo(Perangkat::class, 'perangkat_id');
    }

    /**
     * @return HasOne<KoneksiEndpoint, $this>
     */
    public function koneksiEndpoint(): HasOne
    {
        return $this->hasOne(KoneksiEndpoint::class, 'port_perangkat_id');
    }

    public function label(): string
    {
        return "{$this->perangkat->kode_aset} port {$this->nama}";
    }
}
