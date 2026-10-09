<?php

namespace App\Models;

use App\Enums\AksiAktivitas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Catatan aktivitas penting. Hanya dapat ditambah; tidak diubah atau dihapus.
 *
 * @property int $id
 * @property Carbon $waktu
 * @property int|null $pelaku_id
 * @property string|null $pelaku_nama
 * @property string|null $pelaku_peran
 * @property AksiAktivitas $aksi
 * @property string|null $subjek_tipe
 * @property int|null $subjek_id
 * @property string|null $subjek_label
 * @property array<string, mixed>|null $rincian
 * @property string|null $alamat_ip
 * @property string|null $agen_pengguna
 */
class LogAktivitas extends Model
{
    protected $table = 'log_aktivitas';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Log aktivitas tidak dapat diubah.'));
        static::deleting(fn () => throw new LogicException('Log aktivitas tidak dapat dihapus.'));
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
            'aksi' => AksiAktivitas::class,
            'rincian' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function pelaku(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pelaku_id');
    }
}
