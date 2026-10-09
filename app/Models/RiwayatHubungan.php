<?php

namespace App\Models;

use App\Enums\AksiHubungan;
use App\Enums\JenisHubungan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Histori perubahan hubungan jaringan. Hanya dapat ditambah.
 *
 * @property int $id
 * @property Carbon $waktu
 * @property int|null $pelaku_id
 * @property string|null $pelaku_nama
 * @property JenisHubungan $jenis
 * @property AksiHubungan $aksi
 * @property int|null $titik_jaringan_id
 * @property int|null $segmen_kabel_id
 * @property string $ringkasan
 * @property array<string, mixed>|null $sebelum
 * @property array<string, mixed>|null $sesudah
 */
class RiwayatHubungan extends Model
{
    protected $table = 'riwayat_hubungan';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Riwayat hubungan tidak dapat diubah.'));
        static::deleting(fn () => throw new LogicException('Riwayat hubungan tidak dapat dihapus.'));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'waktu' => 'immutable_datetime',
            'jenis' => JenisHubungan::class,
            'aksi' => AksiHubungan::class,
            'sebelum' => 'array',
            'sesudah' => 'array',
        ];
    }
}
