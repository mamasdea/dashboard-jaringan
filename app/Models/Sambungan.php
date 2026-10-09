<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sambungan (splice) antara core masuk dan core keluar di sebuah titik.
 * Dibuat dan dihapus lewat App\Fo\LayananHubungan agar historinya tercatat.
 *
 * @property int $id
 * @property int $titik_jaringan_id
 * @property int $core_masuk_id
 * @property int $core_keluar_id
 * @property string|null $catatan
 * @property-read TitikJaringan $titik
 * @property-read CoreKabel $coreMasuk
 * @property-read CoreKabel $coreKeluar
 */
#[Fillable(['titik_jaringan_id', 'core_masuk_id', 'core_keluar_id', 'catatan'])]
class Sambungan extends Model
{
    protected $table = 'sambungan';

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
    public function coreMasuk(): BelongsTo
    {
        return $this->belongsTo(CoreKabel::class, 'core_masuk_id');
    }

    /**
     * @return BelongsTo<CoreKabel, $this>
     */
    public function coreKeluar(): BelongsTo
    {
        return $this->belongsTo(CoreKabel::class, 'core_keluar_id');
    }
}
