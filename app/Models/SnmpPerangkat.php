<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Identitas dan uptime perangkat menurut SNMP. Nilai null berarti perangkat
 * tidak menyediakannya ("Tidak tersedia").
 *
 * @property int $id
 * @property int $endpoint_id
 * @property string|null $sys_descr
 * @property string|null $sys_object_id
 * @property string|null $sys_name
 * @property int|null $uptime_tiktik
 * @property CarbonImmutable|null $restart_terdeteksi_pada
 * @property CarbonImmutable $diperbarui_pada
 * @property bool $simulasi
 */
class SnmpPerangkat extends Model
{
    protected $table = 'snmp_perangkat';

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
            'uptime_tiktik' => 'integer',
            'restart_terdeteksi_pada' => 'immutable_datetime',
            'diperbarui_pada' => 'immutable_datetime',
            'simulasi' => 'boolean',
        ];
    }

    public function uptimeDetik(): ?int
    {
        return $this->uptime_tiktik === null ? null : intdiv($this->uptime_tiktik, 100);
    }
}
