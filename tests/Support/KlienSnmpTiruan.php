<?php

namespace Tests\Support;

use App\Monitoring\Snmp\GalatSnmp;
use App\Monitoring\Snmp\KlienSnmp;
use App\Monitoring\Snmp\PabrikKlienSnmp;
use SensitiveParameter;

/**
 * Perangkat SNMP tiruan untuk pengujian: tidak ada paket yang dikirim.
 */
class KlienSnmpTiruan implements KlienSnmp, PabrikKlienSnmp
{
    public const string IF = '1.3.6.1.2.1.2.2.1';

    public const string IFX = '1.3.6.1.2.1.31.1.1.1';

    /** @var list<array{alamat: string, port: int, versi: string, kredensial: array<string, mixed>, batasWaktuMs: int, percobaanUlang: int}> */
    public array $sesi = [];

    public ?GalatSnmp $galatSesi = null;

    /**
     * @param  array<string, string|null>  $skalar  OID => nilai.
     * @param  array<string, array<string, string>>  $tabel  OID kolom => [indeks => nilai].
     */
    public function __construct(public array $skalar = [], public array $tabel = []) {}

    /**
     * Switch dengan ifXTable lengkap (penghitung 64-bit).
     *
     * @param  array<int, array<string, mixed>>  $interface  ifIndex => kolom.
     */
    public static function switch(array $interface, int $uptimeTiktik = 8_640_000, string $sysObjectId = '1.3.6.1.4.1.99999.1'): self
    {
        $klien = new self([
            '1.3.6.1.2.1.1.1.0' => 'Switch Uji 24G, firmware 1.2.3',
            '1.3.6.1.2.1.1.2.0' => '.'.$sysObjectId,
            '1.3.6.1.2.1.1.3.0' => (string) $uptimeTiktik,
            '1.3.6.1.2.1.1.5.0' => 'sw-uji-01',
        ]);

        $peta = [
            'descr' => self::IF.'.2', 'tipe' => self::IF.'.3', 'kecepatan' => self::IF.'.5', 'mac' => self::IF.'.6',
            'admin' => self::IF.'.7', 'oper' => self::IF.'.8', 'masuk32' => self::IF.'.10', 'buang_masuk' => self::IF.'.13',
            'galat_masuk' => self::IF.'.14', 'keluar32' => self::IF.'.16', 'buang_keluar' => self::IF.'.19', 'galat_keluar' => self::IF.'.20',
            'nama' => self::IFX.'.1', 'masuk64' => self::IFX.'.6', 'keluar64' => self::IFX.'.10', 'mbps' => self::IFX.'.15',
            'alias' => self::IFX.'.18', 'diskontinuitas' => self::IFX.'.19',
        ];

        foreach ($interface as $indeks => $kolom) {
            foreach ($kolom as $kunci => $nilai) {
                if ($nilai !== null) {
                    $klien->tabel[$peta[$kunci]][(string) $indeks] = (string) $nilai;
                }
            }
        }

        return $klien;
    }

    public function buat(string $alamat, int $port, string $versi, #[SensitiveParameter] array $kredensial, int $batasWaktuMs, int $percobaanUlang): KlienSnmp
    {
        $this->sesi[] = compact('alamat', 'port', 'versi', 'kredensial', 'batasWaktuMs', 'percobaanUlang');

        if ($this->galatSesi !== null) {
            throw $this->galatSesi;
        }

        return $this;
    }

    public function ambil(array $oid): array
    {
        return array_combine($oid, array_map(fn (string $o) => $this->skalar[$o] ?? null, $oid));
    }

    public function jelajah(string $oidAkar, int $batas): array
    {
        return array_slice($this->tabel[$oidAkar] ?? [], 0, $batas, true);
    }
}
