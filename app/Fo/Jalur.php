<?php

namespace App\Fo;

use App\Models\Perangkat;
use App\Models\PortPerangkat;

/**
 * Satu jalur serat yang tercatat di inventaris, dari ujung ke ujung sejauh
 * datanya ada. Bukan hasil pengukuran: jalur hanya sebenar data inventarisnya.
 */
final readonly class Jalur
{
    public const string PERANGKAT = 'perangkat';

    public const string TITIK = 'titik';

    public const string PORT = 'port';

    public const string KABEL = 'kabel';

    public const string SAMBUNGAN = 'sambungan';

    /**
     * @param  list<array{jenis: string, label: string, rinci: string|null}>  $langkah  Berurutan dari ujung awal.
     * @param  list<int>  $segmenIds
     * @param  list<int>  $titikIds
     * @param  list<int>  $coreIds
     * @param  list<string>  $keterbatasan  Tempat data inventaris berhenti atau tidak konsisten.
     */
    public function __construct(
        public array $langkah,
        public ?PortPerangkat $ujungAwal,
        public ?PortPerangkat $ujungAkhir,
        public array $segmenIds,
        public array $titikIds,
        public array $coreIds,
        public array $keterbatasan,
    ) {}

    /**
     * Tertelusur dari perangkat ke perangkat tanpa bagian yang hilang.
     */
    public function lengkap(): bool
    {
        return $this->ujungAwal !== null && $this->ujungAkhir !== null && $this->keterbatasan === [];
    }

    /**
     * @return list<Perangkat>
     */
    public function perangkat(): array
    {
        $daftar = [];

        foreach ([$this->ujungAwal, $this->ujungAkhir] as $port) {
            if ($port !== null) {
                $daftar[$port->perangkat_id] = $port->perangkat;
            }
        }

        return array_values($daftar);
    }

    /**
     * Sama untuk jalur yang sama, dari arah mana pun ditelusuri.
     */
    public function kunci(): string
    {
        if ($this->coreIds === []) {
            return 'port:'.implode(',', array_map(fn (?PortPerangkat $p) => $p?->id, [$this->ujungAwal, $this->ujungAkhir]));
        }

        $core = $this->coreIds;
        sort($core);

        return 'core:'.implode(',', $core);
    }
}
