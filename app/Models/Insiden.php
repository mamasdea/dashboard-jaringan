<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Insiden gangguan: rentang waktu sebuah endpoint tidak terjangkau.
 * Paling banyak satu yang terbuka per endpoint.
 *
 * @property int $id
 * @property int $endpoint_id
 * @property CarbonImmutable $mulai_pada
 * @property CarbonImmutable|null $selesai_pada
 * @property string|null $ditutup_karena
 * @property bool $simulasi
 */
class Insiden extends Model
{
    public const string PULIH = 'pulih';

    public const string PEMELIHARAAN = 'pemeliharaan';

    protected $table = 'insiden';

    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mulai_pada' => 'immutable_datetime',
            'selesai_pada' => 'immutable_datetime',
            'simulasi' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<EndpointMonitoring, $this>
     */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(EndpointMonitoring::class, 'endpoint_id');
    }

    public function terbuka(): bool
    {
        return $this->selesai_pada === null;
    }
}
