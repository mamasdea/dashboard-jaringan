<?php

namespace App\Monitoring;

use App\Enums\KeluaranPemeriksaan;
use App\Monitoring\Snmp\HasilSnmp;
use Illuminate\Support\Str;

/**
 * Hasil satu pemeriksaan, sebelum disimpan dan dinilai.
 */
final readonly class HasilAdapter
{
    private function __construct(
        public KeluaranPemeriksaan $keluaran,
        public ?float $latencyMs = null,
        public ?float $packetLossPersen = null,
        public ?string $kodeGalat = null,
        public ?string $pesan = null,
        /** Bagian SNMP; null bila SNMP tidak dicoba. Tidak memengaruhi status keterjangkauan. */
        public ?HasilSnmp $snmp = null,
    ) {}

    public function denganSnmp(HasilSnmp $snmp): self
    {
        return new self($this->keluaran, $this->latencyMs, $this->packetLossPersen, $this->kodeGalat, $this->pesan, $snmp);
    }

    /**
     * Endpoint menjawab. Packet loss di bawah 100% tetap berhasil; nilainya
     * dibandingkan dengan ambang untuk menentukan "Koneksi menurun".
     */
    public static function berhasil(float $latencyMs, float $packetLossPersen = 0.0): self
    {
        return new self(KeluaranPemeriksaan::Berhasil, max(0.0, $latencyMs), min(100.0, max(0.0, $packetLossPersen)));
    }

    /**
     * Pemeriksaan selesai dan endpoint tidak menjawab.
     */
    public static function gagalPerangkat(string $kodeGalat = 'tanpa_jawaban', ?string $pesan = null): self
    {
        return new self(KeluaranPemeriksaan::PerangkatGagal, null, 100.0, $kodeGalat, self::ringkas($pesan));
    }

    /**
     * Pemeriksaan tidak dapat diselesaikan. Bukan bukti apa pun tentang endpoint.
     */
    public static function galatKolektor(string $kodeGalat, ?string $pesan = null): self
    {
        return new self(KeluaranPemeriksaan::GalatKolektor, null, null, $kodeGalat, self::ringkas($pesan));
    }

    private static function ringkas(?string $pesan): ?string
    {
        return $pesan === null ? null : Str::limit($pesan, 480, '…');
    }
}
