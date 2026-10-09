<?php

namespace App\Fo;

use App\Models\CoreKabel;
use App\Models\PortPerangkat;
use App\Models\TitikJaringan;

/**
 * Pengumpul langkah selama satu arah penelusuran. Dua jejak (dua arah dari
 * core yang sama) dapat berbagi daftar core yang sudah dilewati.
 *
 * @internal Dipakai PenelusuranJalur.
 */
final class JejakJalur
{
    /** @var list<array{jenis: string, label: string, rinci: string|null}> */
    private array $langkah = [];

    /** @var list<int> */
    private array $segmenIds = [];

    /** @var list<int> */
    private array $titikIds = [];

    /** @var list<string> */
    private array $keterbatasan = [];

    /** @var array<int, true> */
    private array $coreLewat = [];

    public function __construct(?self $bersama = null)
    {
        if ($bersama !== null) {
            $this->coreLewat = &$bersama->coreLewat;
        }
    }

    public function langkah(string $jenis, string $label, ?string $rinci = null): void
    {
        $this->langkah[] = ['jenis' => $jenis, 'label' => $label, 'rinci' => $rinci];
    }

    public function titik(TitikJaringan $titik): void
    {
        if (! in_array($titik->id, $this->titikIds, true)) {
            $this->titikIds[] = $titik->id;
        }

        $this->langkah(Jalur::TITIK, $titik->kode, $titik->jenis->label().($titik->nama === null ? '' : ' · '.$titik->nama));
    }

    /**
     * Menandai core sudah dilewati tanpa menambah langkah.
     */
    public function catatCore(CoreKabel $core): void
    {
        $this->coreLewat[$core->id] = true;

        if (! in_array($core->segmen_kabel_id, $this->segmenIds, true)) {
            $this->segmenIds[] = $core->segmen_kabel_id;
        }
    }

    public function core(CoreKabel $core): void
    {
        $this->catatCore($core);
        $this->langkah[] = self::langkahCore($core);
    }

    public function sudahLewat(CoreKabel $core): bool
    {
        return isset($this->coreLewat[$core->id]);
    }

    public function batas(string $pesan): void
    {
        $this->keterbatasan[] = $pesan;
    }

    public function jadi(?PortPerangkat $awal, ?PortPerangkat $akhir): Jalur
    {
        return new Jalur($this->langkah, $awal, $akhir, $this->segmenIds, $this->titikIds, array_keys($this->coreLewat), $this->keterbatasan);
    }

    /**
     * Menggabungkan jejak ini (arah awal, dibalik) dengan langkah core di
     * tengah dan jejak arah akhir.
     *
     * @param  array{jenis: string, label: string, rinci: string|null}  $tengah
     */
    public function gabung(array $tengah, self $akhir, ?PortPerangkat $ujungAwal, ?PortPerangkat $ujungAkhir): Jalur
    {
        return new Jalur(
            [...array_reverse($this->langkah), $tengah, ...$akhir->langkah],
            $ujungAwal,
            $ujungAkhir,
            array_values(array_unique([...$this->segmenIds, ...$akhir->segmenIds])),
            array_values(array_unique([...$this->titikIds, ...$akhir->titikIds])),
            array_keys($this->coreLewat),
            [...$this->keterbatasan, ...$akhir->keterbatasan],
        );
    }

    /**
     * @return array{jenis: string, label: string, rinci: string|null}
     */
    public static function langkahCore(CoreKabel $core): array
    {
        $segmen = $core->segmen;

        return [
            'jenis' => Jalur::KABEL,
            'label' => $core->label(),
            'rinci' => trim(($segmen->klasifikasi?->label() ?? 'Klasifikasi belum ditentukan').($segmen->nama === null ? '' : ' · '.$segmen->nama)),
        ];
    }
}
