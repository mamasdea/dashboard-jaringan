<?php

namespace App\Models;

use App\Enums\JenisCatatanTiket;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Satu entri linimasa tiket: tindakan, catatan (boleh berfoto), atau entri
 * sistem. Hanya dapat ditambah.
 *
 * @property int $id
 * @property int $tiket_id
 * @property int|null $pengguna_id
 * @property string|null $pengguna_nama
 * @property JenisCatatanTiket $jenis
 * @property string $isi
 * @property string|null $foto_path
 * @property CarbonImmutable $dibuat_pada
 * @property-read Tiket $tiket
 */
class CatatanTiket extends Model
{
    protected $table = 'catatan_tiket';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Catatan tiket tidak dapat diubah.'));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'jenis' => JenisCatatanTiket::class,
            'dibuat_pada' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Tiket, $this>
     */
    public function tiket(): BelongsTo
    {
        return $this->belongsTo(Tiket::class, 'tiket_id');
    }
}
