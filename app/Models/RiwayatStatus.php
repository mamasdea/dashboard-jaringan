<?php

namespace App\Models;

use App\Enums\AlasanStatus;
use App\Enums\StatusMonitoring;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Riwayat perubahan status endpoint. Hanya dapat ditambah.
 *
 * @property int $id
 * @property int $endpoint_id
 * @property StatusMonitoring $dari
 * @property StatusMonitoring $menjadi
 * @property AlasanStatus $alasan
 * @property CarbonImmutable $pada
 * @property int|null $hasil_pemeriksaan_id
 * @property int|null $insiden_id
 * @property bool $simulasi
 */
class RiwayatStatus extends Model
{
    protected $table = 'riwayat_status';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Riwayat status tidak dapat diubah.'));
        static::deleting(fn () => throw new LogicException('Riwayat status tidak dapat dihapus.'));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dari' => StatusMonitoring::class,
            'menjadi' => StatusMonitoring::class,
            'alasan' => AlasanStatus::class,
            'pada' => 'immutable_datetime',
            'simulasi' => 'boolean',
        ];
    }
}
