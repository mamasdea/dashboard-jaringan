<?php

namespace App\Laporan;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Validation\ValidationException;

/**
 * Rentang laporan [mulai, akhir) dalam UTC. Diisi sebagai tanggal menurut
 * zona tampilan: "sampai" ikut terhitung penuh sampai tengah malam berikutnya.
 */
final readonly class Periode
{
    public const int HARI_BAWAAN = 30;

    public const int HARI_MAKSIMUM = 366;

    private const string FORMAT = 'Y-m-d';

    private function __construct(
        public CarbonImmutable $mulai,
        public CarbonImmutable $akhir,
        public string $dari,
        public string $sampai,
    ) {}

    /**
     * @param  string|null  $dari  Tanggal Y-m-d (zona tampilan); kosong = 30 hari terakhir.
     * @param  string|null  $sampai  Tanggal Y-m-d (zona tampilan); kosong = hari ini.
     */
    public static function dariIsian(?string $dari, ?string $sampai): self
    {
        $zona = (string) config('app.display_timezone');
        $hariIni = CarbonImmutable::now($zona)->startOfDay();

        $akhir = self::tanggal($sampai, $zona, 'sampai') ?? $hariIni;
        $mulai = self::tanggal($dari, $zona, 'dari') ?? $akhir->subDays(self::HARI_BAWAAN - 1);

        if ($mulai->greaterThan($akhir)) {
            throw ValidationException::withMessages(['dari' => 'Tanggal awal tidak boleh setelah tanggal akhir.']);
        }

        if ($mulai->diffInDays($akhir) >= self::HARI_MAKSIMUM) {
            throw ValidationException::withMessages(['sampai' => 'Rentang laporan paling panjang '.self::HARI_MAKSIMUM.' hari.']);
        }

        return new self($mulai->utc(), $akhir->addDay()->utc(), $mulai->format(self::FORMAT), $akhir->format(self::FORMAT));
    }

    /**
     * Akhir yang benar-benar dihitung: waktu yang belum terjadi tidak dinilai.
     */
    public function akhirEfektif(?CarbonInterface $kini = null): CarbonImmutable
    {
        return $this->akhir->min(CarbonImmutable::instance($kini ?? now())->utc())->max($this->mulai);
    }

    public function detik(?CarbonInterface $kini = null): int
    {
        return (int) $this->mulai->diffInSeconds($this->akhirEfektif($kini));
    }

    public function label(): string
    {
        $zona = (string) config('app.display_timezone');

        return $this->mulai->timezone($zona)->format('d-m-Y').' s.d. '.$this->akhir->subSecond()->timezone($zona)->format('d-m-Y');
    }

    private static function tanggal(?string $nilai, string $zona, string $kolom): ?CarbonImmutable
    {
        if ($nilai === null || trim($nilai) === '') {
            return null;
        }

        try {
            $tanggal = CarbonImmutable::createFromFormat('!'.self::FORMAT, trim($nilai), $zona);
        } catch (InvalidFormatException) {
            $tanggal = null;
        }

        // Tanggal seperti 31 Februari diurai menjadi tanggal lain; ditolak.
        if ($tanggal === null || $tanggal->format(self::FORMAT) !== trim($nilai)) {
            throw ValidationException::withMessages([$kolom => 'Tanggal harus berformat TTTT-BB-HH.']);
        }

        return $tanggal;
    }
}
