<?php

namespace App\Models;

use App\Enums\StatusPemakaian;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $segmen_kabel_id
 * @property int $nomor
 * @property int $tube
 * @property string $warna
 * @property StatusPemakaian $status
 * @property string|null $catatan
 * @property-read SegmenKabel $segmen
 */
#[Fillable(['nomor', 'tube', 'warna', 'status', 'catatan'])]
class CoreKabel extends Model
{
    /**
     * Urutan warna serat TIA-598-C; berulang tiap 12 core (satu tube).
     */
    public const array WARNA = [
        'biru', 'oranye', 'hijau', 'cokelat', 'abu-abu', 'putih',
        'merah', 'hitam', 'kuning', 'ungu', 'merah muda', 'toska',
    ];

    protected $table = 'core_kabel';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'tersedia',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nomor' => 'integer',
            'tube' => 'integer',
            'status' => StatusPemakaian::class,
        ];
    }

    public static function warnaUntuk(int $nomor): string
    {
        return self::WARNA[($nomor - 1) % count(self::WARNA)];
    }

    public static function tubeUntuk(int $nomor): int
    {
        return intdiv($nomor - 1, count(self::WARNA)) + 1;
    }

    /**
     * @return BelongsTo<SegmenKabel, $this>
     */
    public function segmen(): BelongsTo
    {
        return $this->belongsTo(SegmenKabel::class, 'segmen_kabel_id');
    }

    public function label(): string
    {
        return "{$this->segmen->kode} core {$this->nomor} ({$this->warna})";
    }
}
