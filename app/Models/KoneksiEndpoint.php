<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hubungan port perangkat dengan port terminasi, atau langsung dengan ujung
 * core di sebuah titik. Dibuat dan dihapus lewat App\Fo\LayananHubungan.
 *
 * @property int $id
 * @property int $port_perangkat_id
 * @property int|null $port_terminasi_id
 * @property int|null $core_id
 * @property int|null $titik_jaringan_id
 * @property string|null $catatan
 * @property-read PortPerangkat $portPerangkat
 * @property-read PortTerminasi|null $portTerminasi
 * @property-read CoreKabel|null $core
 * @property-read TitikJaringan|null $titik
 */
#[Fillable(['port_perangkat_id', 'port_terminasi_id', 'core_id', 'titik_jaringan_id', 'catatan'])]
class KoneksiEndpoint extends Model
{
    protected $table = 'koneksi_endpoint';

    /**
     * @return BelongsTo<PortPerangkat, $this>
     */
    public function portPerangkat(): BelongsTo
    {
        return $this->belongsTo(PortPerangkat::class, 'port_perangkat_id');
    }

    /**
     * @return BelongsTo<PortTerminasi, $this>
     */
    public function portTerminasi(): BelongsTo
    {
        return $this->belongsTo(PortTerminasi::class, 'port_terminasi_id');
    }

    /**
     * @return BelongsTo<CoreKabel, $this>
     */
    public function core(): BelongsTo
    {
        return $this->belongsTo(CoreKabel::class, 'core_id');
    }

    /**
     * @return BelongsTo<TitikJaringan, $this>
     */
    public function titik(): BelongsTo
    {
        return $this->belongsTo(TitikJaringan::class, 'titik_jaringan_id');
    }
}
