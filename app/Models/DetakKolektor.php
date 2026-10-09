<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Tanda hidup satu proses kolektor.
 *
 * @property int $id
 * @property string $nama
 * @property string $adapter
 * @property bool $simulasi
 * @property CarbonImmutable $mulai_pada
 * @property CarbonImmutable $detak_pada
 * @property int $putaran
 * @property int $diperiksa_putaran_terakhir
 */
class DetakKolektor extends Model
{
    protected $table = 'detak_kolektor';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'simulasi' => 'boolean',
            'mulai_pada' => 'immutable_datetime',
            'detak_pada' => 'immutable_datetime',
            'putaran' => 'integer',
            'diperiksa_putaran_terakhir' => 'integer',
        ];
    }
}
