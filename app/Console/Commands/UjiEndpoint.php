<?php

namespace App\Console\Commands;

use App\Enums\KeluaranPemeriksaan;
use App\Models\EndpointMonitoring;
use App\Models\InterfaceSnmp;
use App\Models\ProfilSnmp;
use App\Monitoring\Adapter\AdapterJaringan;
use App\Monitoring\DaftarIzinSasaran;
use App\Monitoring\HasilAdapter;
use App\Monitoring\LayananStatus;
use App\Monitoring\SasaranPemeriksaan;
use App\Monitoring\Snmp\DataInterface;
use App\Monitoring\Snmp\HasilSnmp;
use App\Monitoring\Snmp\PenghitungLaju;
use App\Support\Tampilan;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Signature('monitoring:uji-endpoint
    {--sampel=1 : Jumlah pemeriksaan; dua atau lebih untuk menghitung laju trafik (1-5)}
    {--jeda=10 : Jeda antar-pemeriksaan dalam detik (5-120)}
    {--semua : Tampilkan semua interface, bukan hanya uplink dan yang aktif}
    {--simpan : Daftarkan sebagai endpoint monitoring dan simpan hasilnya ke database}')]
#[Description('Memeriksa satu endpoint uji dari environment (ICMP dan SNMP), tanpa pemindaian')]
class UjiEndpoint extends Command
{
    public const string NAMA_ENDPOINT = '[UJI] Endpoint uji dari environment';

    public const string NAMA_PROFIL = '[UJI] Profil SNMP endpoint uji';

    /**
     * Execute the console command.
     */
    public function handle(AdapterJaringan $adapter, DaftarIzinSasaran $daftarIzin, LayananStatus $layanan): int
    {
        $uji = (array) config('monitoring.uji');
        $alamat = trim((string) ($uji['alamat'] ?? ''));
        $snmp = (array) ($uji['snmp'] ?? []);
        $versi = blank($snmp['versi'] ?? null) ? null : strtolower((string) $snmp['versi']);

        if ($alamat === '' || filter_var($alamat, FILTER_VALIDATE_IP) === false) {
            $this->error('MONITORING_UJI_ALAMAT belum diisi atau bukan alamat IP. Lihat docs/PANDUAN-UJI-SWITCH.md.');

            return self::FAILURE;
        }

        if (! $daftarIzin->mengizinkan($alamat)) {
            $this->error("Alamat {$alamat} tidak ada di MONITORING_SASARAN_DIIZINKAN. Tidak ada paket yang dikirim.");

            return self::FAILURE;
        }

        if ($versi !== null && ! in_array($versi, ['v2c', 'v3'], true)) {
            $this->error('MONITORING_UJI_SNMP_VERSI harus v3 atau v2c, atau dikosongkan untuk ICMP saja.');

            return self::FAILURE;
        }

        $this->info("Endpoint uji: {$alamat}");
        $this->line('Hanya alamat ini yang dihubungi; tidak ada pemindaian subnet maupun discovery.');
        $this->line('SNMP: '.match ($versi) {
            'v3' => 'v3, tingkat keamanan '.($snmp['tingkat_keamanan'] ?? 'authPriv'),
            'v2c' => 'v2c (hanya bila perangkat tidak mendukung v3; pastikan community-nya baca-saja)',
            default => 'tidak dikonfigurasi, hanya ICMP',
        });
        $this->newLine();

        $sasaran = new SasaranPemeriksaan(
            endpointId: 0,
            alamat: $alamat,
            metode: $versi === null ? 'icmp' : 'snmp',
            batasWaktuDetik: (float) config('monitoring.batas_waktu_adapter_detik'),
            portSnmp: (int) ($snmp['port'] ?? 161),
            versiSnmp: $versi,
            kredensialSnmp: $versi === null ? null : fn (): array => $this->kredensial($versi, $snmp),
        );

        $endpoint = $this->option('simpan') ? $this->daftarkan($alamat, $versi, $snmp, (string) ($uji['port_uplink'] ?? '')) : null;
        $jumlah = max(1, min(5, (int) $this->option('sampel')));
        $jeda = max(5, min(120, (int) $this->option('jeda')));
        $sebelum = null;
        $waktuSebelum = null;
        $hasil = null;

        for ($ke = 1; $ke <= $jumlah; $ke++) {
            if ($ke > 1) {
                $this->line("Menunggu {$jeda} detik sebelum pemeriksaan ke-{$ke}...");
                sleep($jeda);
            }

            $waktu = now()->utc();
            $hasil = $adapter->periksa($sasaran);

            $this->tampilkan($hasil, $ke, $jumlah, $sebelum, $waktuSebelum === null ? null : (float) $waktuSebelum->diffInSeconds($waktu, absolute: false), (string) ($uji['port_uplink'] ?? ''));

            if ($endpoint !== null) {
                $layanan->terimaHasil($endpoint, $hasil, $waktu, (string) Str::uuid(), $adapter->nama(), false);
            }

            $sebelum = $hasil->snmp;
            $waktuSebelum = $waktu;
        }

        if ($endpoint !== null) {
            $this->newLine();
            $this->info('Hasil disimpan. Endpoint "'.self::NAMA_ENDPOINT.'" kini ikut diperiksa kolektor (MONITORING_ADAPTER=jaringan).');
        }

        // Berhasil bila endpoint terjangkau; kegagalan SNMP dilaporkan tetapi tidak mengubah kode keluar ICMP.
        return $hasil->keluaran === KeluaranPemeriksaan::Berhasil
            && ($hasil->snmp === null || $hasil->snmp->keluaran === KeluaranPemeriksaan::Berhasil)
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function tampilkan(HasilAdapter $hasil, int $ke, int $jumlah, ?HasilSnmp $sebelum, ?float $selang, string $portUplink): void
    {
        $this->line("<options=bold>Pemeriksaan {$ke} dari {$jumlah}</>");

        $this->line('ICMP : '.$hasil->keluaran->label().($hasil->kodeGalat ? " [{$hasil->kodeGalat}]" : ''));

        if ($hasil->keluaran === KeluaranPemeriksaan::Berhasil) {
            $this->line('       latency '.Tampilan::atauTidakTersedia($hasil->latencyMs, 'ms').', packet loss '.Tampilan::atauTidakTersedia($hasil->packetLossPersen, '%'));
        } elseif ($hasil->pesan !== null) {
            $this->line('       '.$hasil->pesan);
        }

        $snmp = $hasil->snmp;

        if ($snmp === null) {
            $this->line('SNMP : tidak dicoba'.($hasil->keluaran === KeluaranPemeriksaan::Berhasil ? ' (tidak dikonfigurasi)' : ' (ICMP tidak berhasil)'));
            $this->newLine();

            return;
        }

        $this->line('SNMP : '.$snmp->keluaran->label().($snmp->kodeGalat ? " [{$snmp->kodeGalat}]" : ''));

        if ($snmp->keluaran !== KeluaranPemeriksaan::Berhasil) {
            $this->line('       '.Tampilan::atauTidakTersedia($snmp->pesan));
            $this->newLine();

            return;
        }

        $this->line('       sysName     : '.Tampilan::atauTidakTersedia($snmp->sysName));
        $this->line('       sysDescr    : '.Tampilan::atauTidakTersedia($snmp->sysDescr === null ? null : Str::limit($snmp->sysDescr, 100)));
        $this->line('       sysObjectID : '.Tampilan::atauTidakTersedia($snmp->sysObjectId));
        $this->line('       uptime      : '.Tampilan::durasi($snmp->uptimeTiktik === null ? null : intdiv($snmp->uptimeTiktik, 100)));
        $this->line('       optik SFP   : '.($snmp->sumberSfp === null ? Tampilan::TIDAK_TERSEDIA.' (tidak ada adapter vendor untuk perangkat ini)' : "adapter {$snmp->sumberSfp}"));

        if ($snmp->interfaceTanpaIdentitas > 0) {
            $this->warn("       {$snmp->interfaceTanpaIdentitas} interface dilewati karena tanpa nama atau bernama ganda.");
        }

        $lama = collect($sebelum !== null ? $sebelum->interface : [])->keyBy(fn (DataInterface $i) => mb_strtolower($i->nama));
        $uplink = mb_strtolower(trim($portUplink));
        $adalahUplink = fn (DataInterface $i): bool => $uplink !== '' && in_array($uplink, array_map(fn (?string $n) => mb_strtolower((string) $n), [$i->nama, $i->deskripsi, $i->alias]), true);

        $dipilih = collect($snmp->interface)->filter(fn (DataInterface $i) => $this->option('semua') || $adalahUplink($i) || $i->statusOperasional === 1);

        if ($uplink !== '' && ! collect($snmp->interface)->contains($adalahUplink)) {
            $this->warn("       Port uplink \"{$portUplink}\" tidak ditemukan pada ifName, ifDescr, maupun ifAlias perangkat.");
        }

        $this->table(
            ['', 'Interface', 'ifIndex', 'Alamat fisik', 'Admin/Oper', 'Kecepatan', 'Masuk', 'Keluar', 'Galat m/k', 'Buang m/k', 'SFP rx/tx'],
            $dipilih->take(60)->map(function (DataInterface $i) use ($lama, $selang, $sebelum, $snmp, $adalahUplink): array {
                $dulu = $lama[mb_strtolower($i->nama)] ?? null;
                $laju = fn (?int $a, ?int $b): string => $this->laju($a, $b, $selang, $i, $dulu, $sebelum, $snmp);

                return [
                    $adalahUplink($i) ? 'UPLINK' : '',
                    Str::limit($i->nama, 28),
                    $i->ifIndex,
                    Tampilan::alamatFisik($i->alamatFisik),
                    InterfaceSnmp::labelStatus($i->statusAdmin).'/'.InterfaceSnmp::labelStatus($i->statusOperasional),
                    Tampilan::bps($i->kecepatanBps),
                    $laju($dulu?->oktetMasuk, $i->oktetMasuk),
                    $laju($dulu?->oktetKeluar, $i->oktetKeluar),
                    Tampilan::atauTidakTersedia($i->galatMasuk).' / '.Tampilan::atauTidakTersedia($i->galatKeluar),
                    Tampilan::atauTidakTersedia($i->buangMasuk).' / '.Tampilan::atauTidakTersedia($i->buangKeluar),
                    $i->sfpRxDbm === null && $i->sfpTxDbm === null ? Tampilan::TIDAK_TERSEDIA : Tampilan::atauTidakTersedia($i->sfpRxDbm, 'dBm').' / '.Tampilan::atauTidakTersedia($i->sfpTxDbm, 'dBm'),
                ];
            })->values()->all(),
        );

        $this->line(sprintf('       %d interface terbaca, %d ditampilkan%s.', count($snmp->interface), min(60, $dipilih->count()), $this->option('semua') ? '' : ' (uplink dan yang aktif; --semua untuk seluruhnya)'));
        $this->line('       Galat dan buang adalah penghitung kumulatif sejak perangkat menyala.');
        $this->newLine();
    }

    /**
     * Laju antara pemeriksaan sebelumnya dan sekarang, atau alasan mengapa belum dapat dihitung.
     */
    private function laju(?int $sebelum, ?int $sesudah, ?float $selang, DataInterface $kini, ?DataInterface $dulu, ?HasilSnmp $hasilDulu, HasilSnmp $hasilKini): string
    {
        if ($sesudah === null) {
            return Tampilan::TIDAK_TERSEDIA;
        }

        $hitung = PenghitungLaju::hitung(
            $dulu === null ? null : $sebelum,
            $sesudah,
            $selang ?? 0.0,
            600,
            (bool) $kini->penghitung64Bit,
            $hasilDulu?->uptimeTiktik,
            $hasilKini->uptimeTiktik,
            $dulu?->diskontinuitasTiktik,
            $kini->diskontinuitasTiktik,
            $kini->kecepatanBps,
        );

        return $hitung['bps'] !== null
            ? Tampilan::bps($hitung['bps'])
            : match ($hitung['alasan']) {
                PenghitungLaju::SAMPEL_PERTAMA => 'butuh sampel ke-2',
                PenghitungLaju::PERANGKAT_RESTART => 'perangkat restart',
                PenghitungLaju::PENGHITUNG_RESET => 'penghitung reset',
                default => Tampilan::TIDAK_TERSEDIA,
            };
    }

    /**
     * Kredensial hanya dibaca dari environment dan tidak pernah ditampilkan.
     *
     * @param  array<string, mixed>  $snmp
     * @return array<string, mixed>
     */
    private function kredensial(string $versi, array $snmp): array
    {
        return $versi === 'v2c'
            ? ['community' => $snmp['community'] ?? null]
            : array_intersect_key($snmp, array_flip(['pengguna', 'tingkat_keamanan', 'protokol_auth', 'sandi_auth', 'protokol_priv', 'sandi_priv']));
    }

    /**
     * Mendaftarkan endpoint uji (dan profil SNMP tersegelnya) supaya ikut diperiksa kolektor.
     *
     * @param  array<string, mixed>  $snmp
     */
    private function daftarkan(string $alamat, ?string $versi, array $snmp, string $portUplink): EndpointMonitoring
    {
        return DB::transaction(function () use ($alamat, $versi, $snmp, $portUplink): EndpointMonitoring {
            $profil = null;

            if ($versi !== null) {
                $profil = ProfilSnmp::query()->firstOrNew(['nama' => self::NAMA_PROFIL]);
                $profil->versi = $versi;
                $profil->port = (int) ($snmp['port'] ?? 161);
                $profil->aturKredensial($this->kredensial($versi, $snmp))->save();
            }

            $endpoint = EndpointMonitoring::query()->firstOrNew(['nama' => self::NAMA_ENDPOINT]);
            $endpoint->fill([
                'alamat' => $alamat,
                'metode' => $versi === null ? 'icmp' : 'snmp',
                'profil_snmp_id' => $profil?->id,
                'port_uplink' => $portUplink === '' ? null : $portUplink,
                'aktif' => true,
                'skenario_simulasi' => null,
            ])->save();

            return $endpoint;
        });
    }
}
