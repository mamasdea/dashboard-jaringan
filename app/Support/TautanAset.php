<?php

namespace App\Support;

use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\SegmenKabel;
use App\Models\Tiang;
use App\Models\TitikJaringan;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Alamat halaman detail aset, yang juga menjadi isi QR pada label.
 *
 * Isinya hanya alamat aplikasi, jenis, dan kode aset. Tidak ada token,
 * kredensial, maupun IP perangkat: siapa pun yang memindai tetap harus masuk
 * dan memiliki hak akses untuk melihat detailnya.
 */
final class TautanAset
{
    /**
     * @var array<string, class-string<Model>>
     */
    public const array JENIS = [
        'opd' => Opd::class,
        'perangkat' => Perangkat::class,
        'tiang' => Tiang::class,
        'titik' => TitikJaringan::class,
        'kabel' => SegmenKabel::class,
    ];

    public const string POLA_KODE = '[A-Za-z0-9._-]+';

    public static function labelJenis(string $jenis): string
    {
        return match ($jenis) {
            'opd' => 'OPD',
            'perangkat' => 'Perangkat',
            'tiang' => 'Tiang',
            'titik' => 'Titik jaringan',
            'kabel' => 'Segmen kabel',
            default => throw new InvalidArgumentException("Jenis aset tidak dikenal: {$jenis}"),
        };
    }

    public static function kolomKode(string $jenis): string
    {
        return $jenis === 'perangkat' ? 'kode_aset' : 'kode';
    }

    public static function jenisDari(Model $aset): string
    {
        $jenis = array_search($aset::class, self::JENIS, true);

        if ($jenis === false) {
            throw new InvalidArgumentException('Model ini bukan aset yang memiliki halaman detail.');
        }

        return $jenis;
    }

    public static function kode(Model $aset): string
    {
        return (string) $aset->getAttribute(self::kolomKode(self::jenisDari($aset)));
    }

    public static function cari(string $jenis, string $kode): ?Model
    {
        $kelas = self::JENIS[$jenis] ?? null;

        return $kelas === null ? null : $kelas::query()->where(self::kolomKode($jenis), mb_strtoupper($kode))->first();
    }

    /**
     * Jalur relatif halaman detail.
     */
    public static function jalur(Model $aset): string
    {
        return route('aset.rinci', ['jenis' => self::jenisDari($aset), 'kode' => self::kode($aset)], absolute: false);
    }

    /**
     * Alamat lengkap untuk QR. Memakai APP_URL, bukan host request saat ini,
     * supaya label yang dicetak dari komputer mana pun menunjuk alamat yang sama.
     */
    public static function url(Model $aset): string
    {
        return rtrim((string) config('app.url'), '/').self::jalur($aset);
    }

    public static function urlPeta(Model $aset): string
    {
        return route('peta', ['fokus' => self::jenisDari($aset).':'.self::kode($aset)]);
    }
}
