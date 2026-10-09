<?php

namespace Tests\Feature\Monitoring;

use App\Enums\KeluaranPemeriksaan;
use App\Models\EndpointMonitoring;
use App\Models\InterfaceSnmp;
use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\SampelTrafik;
use App\Models\SnmpPerangkat;
use App\Monitoring\HasilAdapter;
use App\Monitoring\LayananStatus;
use App\Monitoring\SasaranPemeriksaan;
use App\Monitoring\Sfp\SfpMikrotik;
use App\Monitoring\Snmp\DataInterface;
use App\Monitoring\Snmp\GalatSnmp;
use App\Monitoring\Snmp\HasilSnmp;
use App\Monitoring\Snmp\PemeriksaSnmp;
use App\Monitoring\Snmp\PenghitungLaju;
use App\Support\Tampilan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\KlienSnmpTiruan;
use Tests\TestCase;

class SnmpTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $awal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->awal = CarbonImmutable::parse('2026-10-09 03:00:00', 'UTC');
        $this->travelTo($this->awal);
    }

    /**
     * @param  array<string, mixed>|null  $kredensial
     */
    private function sasaran(?array $kredensial = ['community' => 'community-sangat-rahasia'], string $versi = 'v2c'): SasaranPemeriksaan
    {
        return new SasaranPemeriksaan(1, '198.51.100.10', 'snmp', 10, portSnmp: 161, versiSnmp: $versi, kredensialSnmp: $kredensial === null ? null : fn () => $kredensial);
    }

    private function baca(KlienSnmpTiruan $klien, bool $denganSfp = false): HasilSnmp
    {
        return (new PemeriksaSnmp($klien, $denganSfp ? [new SfpMikrotik] : []))->periksa($this->sasaran());
    }

    /**
     * @param  list<DataInterface>  $daftar
     */
    private function interface(array $daftar, string $nama): DataInterface
    {
        return collect($daftar)->firstOrFail(fn (DataInterface $i) => $i->nama === $nama);
    }

    public function test_identitas_uptime_dan_interface_dibaca(): void
    {
        $klien = KlienSnmpTiruan::switch([
            1 => ['descr' => 'GigabitEthernet0/1', 'nama' => 'Gi0/1', 'alias' => 'Uplink ke Diskominfo', 'tipe' => 6, 'kecepatan' => 1000000000, 'mbps' => 1000,
                'mac' => '0:1a:2b:3c:4d:5e', 'admin' => 1, 'oper' => 1, 'masuk32' => 111, 'keluar32' => 222, 'masuk64' => 5000000000, 'keluar64' => 7000000000,
                'galat_masuk' => 0, 'galat_keluar' => 3, 'buang_masuk' => 0, 'buang_keluar' => 12, 'diskontinuitas' => 0],
            2 => ['descr' => 'GigabitEthernet0/2', 'nama' => 'Gi0/2', 'tipe' => 6, 'mbps' => 1000, 'mac' => "\x00\x1a\x2b\x3c\x4d\x5f", 'admin' => 2, 'oper' => 2],
        ], uptimeTiktik: 123456);

        $hasil = $this->baca($klien);

        $this->assertSame(KeluaranPemeriksaan::Berhasil, $hasil->keluaran);
        $this->assertSame('sw-uji-01', $hasil->sysName);
        $this->assertSame('Switch Uji 24G, firmware 1.2.3', $hasil->sysDescr);
        $this->assertSame('1.3.6.1.4.1.99999.1', $hasil->sysObjectId);
        $this->assertSame(123456, $hasil->uptimeTiktik);
        $this->assertCount(2, $hasil->interface);

        $uplink = $this->interface($hasil->interface, 'Gi0/1');
        $this->assertSame(1, $uplink->ifIndex);
        $this->assertSame('GigabitEthernet0/1', $uplink->deskripsi);
        $this->assertSame('Uplink ke Diskominfo', $uplink->alias);
        $this->assertSame('001a2b3c4d5e', $uplink->alamatFisik);
        $this->assertSame(1_000_000_000, $uplink->kecepatanBps);
        $this->assertSame([1, 1], [$uplink->statusAdmin, $uplink->statusOperasional]);
        // Penghitung 64-bit diutamakan dari yang 32-bit.
        $this->assertTrue($uplink->penghitung64Bit);
        $this->assertSame([5_000_000_000, 7_000_000_000], [$uplink->oktetMasuk, $uplink->oktetKeluar]);
        $this->assertSame([0, 3, 0, 12], [$uplink->galatMasuk, $uplink->galatKeluar, $uplink->buangMasuk, $uplink->buangKeluar]);

        // Alamat fisik dalam bentuk biner dibaca sama.
        $this->assertSame('001a2b3c4d5f', $this->interface($hasil->interface, 'Gi0/2')->alamatFisik);

        // Sesi dibuat dengan batas waktu dan percobaan ulang dari konfigurasi.
        $this->assertSame(['198.51.100.10', 161, 'v2c', 1500, 1], [
            $klien->sesi[0]['alamat'], $klien->sesi[0]['port'], $klien->sesi[0]['versi'], $klien->sesi[0]['batasWaktuMs'], $klien->sesi[0]['percobaanUlang'],
        ]);
    }

    public function test_metrik_yang_tidak_didukung_bernilai_null_bukan_nol(): void
    {
        // Perangkat lama: tanpa ifXTable, tanpa penghitung galat/buang, tanpa sysName.
        $klien = KlienSnmpTiruan::switch([
            7 => ['descr' => 'FastEthernet0/7', 'kecepatan' => 100000000, 'admin' => 1, 'oper' => 1, 'masuk32' => 4000, 'keluar32' => 9000],
            8 => ['descr' => 'FastEthernet0/8', 'admin' => 1, 'oper' => 2],
        ]);
        $klien->skalar['1.3.6.1.2.1.1.5.0'] = null;
        $klien->skalar['1.3.6.1.2.1.1.3.0'] = '(4242) 0:00:42.42';

        $hasil = $this->baca($klien);

        $this->assertNull($hasil->sysName);
        $this->assertSame(4242, $hasil->uptimeTiktik);
        $this->assertNull($hasil->sumberSfp);

        // Tanpa ifName, ifDescr menjadi identitasnya.
        $fa7 = $this->interface($hasil->interface, 'FastEthernet0/7');
        $this->assertFalse($fa7->penghitung64Bit);
        $this->assertSame([4000, 9000], [$fa7->oktetMasuk, $fa7->oktetKeluar]);
        $this->assertSame(100_000_000, $fa7->kecepatanBps);

        foreach (['alias', 'alamatFisik', 'galatMasuk', 'galatKeluar', 'buangMasuk', 'buangKeluar', 'diskontinuitasTiktik', 'sfpRxDbm', 'sfpTxDbm', 'sfpSuhuC'] as $metrik) {
            $this->assertNull($fa7->{$metrik}, "{$metrik} seharusnya null (tidak tersedia), bukan nol.");
        }

        $fa8 = $this->interface($hasil->interface, 'FastEthernet0/8');
        $this->assertNull($fa8->oktetMasuk);
        $this->assertNull($fa8->penghitung64Bit);
        $this->assertNull($fa8->kecepatanBps);

        // Ditampilkan sebagai "Tidak tersedia".
        $this->assertSame('Tidak tersedia', Tampilan::atauTidakTersedia($fa7->galatMasuk));
        $this->assertSame('Tidak tersedia', Tampilan::bps($fa8->kecepatanBps));
        $this->assertSame('Tidak tersedia', Tampilan::durasi(null));
        // Nol yang benar-benar dilaporkan perangkat tetap nol.
        $this->assertSame('0', Tampilan::atauTidakTersedia(0));
        $this->assertSame('0 bps', Tampilan::bps(0));
        $this->assertSame('1,50 Mbps', Tampilan::bps(1_500_000));
    }

    public function test_interface_tanpa_nama_atau_bernama_ganda_dilewati(): void
    {
        $klien = KlienSnmpTiruan::switch([
            1 => ['descr' => 'Port', 'nama' => 'eth1'],
            2 => ['descr' => 'Port', 'nama' => 'ETH1'],
            3 => ['descr' => '', 'nama' => ''],
            4 => ['descr' => 'Vlan10'],
        ]);

        $hasil = $this->baca($klien);

        $this->assertSame(['eth1', 'Vlan10'], array_map(fn (DataInterface $i) => $i->nama, $hasil->interface));
        $this->assertSame(2, $hasil->interfaceTanpaIdentitas);
    }

    public function test_jumlah_interface_dibatasi(): void
    {
        config(['monitoring.snmp.maks_interface' => 3]);

        $klien = KlienSnmpTiruan::switch(array_map(fn (int $i) => ['nama' => "eth{$i}", 'descr' => "eth{$i}"], array_combine(range(1, 10), range(1, 10))));

        $this->assertCount(3, $this->baca($klien)->interface);
    }

    public function test_kegagalan_snmp_dibedakan_dan_rahasia_tidak_ikut_tersimpan(): void
    {
        $klien = new KlienSnmpTiruan;

        // Perangkat tidak menjawab: kegagalan perangkat.
        $klien->galatSesi = GalatSnmp::perangkat('snmp_tanpa_jawaban', 'Perangkat tidak menjawab permintaan SNMP.');
        $hasil = $this->baca($klien);
        $this->assertSame(KeluaranPemeriksaan::PerangkatGagal, $hasil->keluaran);
        $this->assertSame('snmp_tanpa_jawaban', $hasil->kodeGalat);

        // Kolektor tidak dapat bekerja: galat kolektor.
        $klien->galatSesi = GalatSnmp::kolektor('ekstensi_snmp_tidak_ada', 'Ekstensi PHP snmp tidak dimuat.');
        $this->assertSame(KeluaranPemeriksaan::GalatKolektor, $this->baca($klien)->keluaran);

        // Pustaka yang mengulang kredensial di pesan galatnya: disamarkan.
        $klien->galatSesi = GalatSnmp::perangkat('snmp_ditolak', 'Authentication failure for community-sangat-rahasia at 198.51.100.10');
        $hasil = $this->baca($klien);
        $this->assertStringNotContainsString('community-sangat-rahasia', (string) $hasil->pesan);
        $this->assertStringContainsString('[disamarkan]', (string) $hasil->pesan);

        // Kredensial yang tidak dapat dibuka (kunci privat tidak ada) adalah galat kolektor.
        $tanpaKunci = new SasaranPemeriksaan(1, '198.51.100.10', 'snmp', 10, versiSnmp: 'v3', kredensialSnmp: fn () => throw new RuntimeException('MONITORING_KUNCI_PRIVAT belum diatur'));
        $hasil = (new PemeriksaSnmp(new KlienSnmpTiruan))->periksa($tanpaKunci);
        $this->assertSame(KeluaranPemeriksaan::GalatKolektor, $hasil->keluaran);
        $this->assertSame('galat_snmp', $hasil->kodeGalat);

        // Endpoint tanpa profil.
        $this->assertSame('profil_snmp_tidak_ada', (new PemeriksaSnmp(new KlienSnmpTiruan))->periksa($this->sasaran(null))->kodeGalat);
    }

    public function test_optik_sfp_hanya_lewat_adapter_vendor_yang_mengenali_perangkat(): void
    {
        $antarmuka = [1 => ['nama' => 'sfp-sfpplus1', 'descr' => 'sfp-sfpplus1'], 2 => ['nama' => 'ether1', 'descr' => 'ether1']];
        $tabelOptik = [
            '1.3.6.1.4.1.14988.1.1.19.1.1.2' => ['1' => 'sfp-sfpplus1'],
            '1.3.6.1.4.1.14988.1.1.19.1.1.6' => ['1' => '41'],
            '1.3.6.1.4.1.14988.1.1.19.1.1.9' => ['1' => '-2133'],
            '1.3.6.1.4.1.14988.1.1.19.1.1.10' => ['1' => '-7890'],
        ];

        $mikrotik = KlienSnmpTiruan::switch($antarmuka, sysObjectId: '1.3.6.1.4.1.14988.1');
        $mikrotik->tabel += $tabelOptik;

        $hasil = $this->baca($mikrotik, denganSfp: true);
        $sfp = $this->interface($hasil->interface, 'sfp-sfpplus1');

        $this->assertSame('mikrotik', $hasil->sumberSfp);
        $this->assertSame([-7.89, -2.133, 41.0], [$sfp->sfpRxDbm, $sfp->sfpTxDbm, $sfp->sfpSuhuC]);
        // Port tembaga pada perangkat yang sama: tidak tersedia.
        $this->assertNull($this->interface($hasil->interface, 'ether1')->sfpRxDbm);

        // Vendor lain dengan tabel yang kebetulan sama: tidak dibaca, karena tidak ada adapter yang mengenalinya.
        $lain = KlienSnmpTiruan::switch($antarmuka, sysObjectId: '1.3.6.1.4.1.9.1.1208');
        $lain->tabel += $tabelOptik;

        $hasil = $this->baca($lain, denganSfp: true);
        $this->assertNull($hasil->sumberSfp);
        $this->assertNull($this->interface($hasil->interface, 'sfp-sfpplus1')->sfpRxDbm);

        $adapter = new SfpMikrotik;
        $this->assertTrue($adapter->mendukung('.1.3.6.1.4.1.14988.1'));
        $this->assertFalse($adapter->mendukung('1.3.6.1.4.1.149881'));
        $this->assertFalse($adapter->mendukung(null));
    }

    /**
     * @return array<string, array{array<string, mixed>, float|null, string|null}>
     */
    public static function kasusLaju(): array
    {
        $dasar = ['sebelum' => 1_000_000, 'sesudah' => 1_750_000, 'selang' => 60.0, 'maks' => 180.0, 'b64' => true,
            'uptime' => [1000, 7000], 'diskontinuitas' => [0, 0], 'kecepatan' => 1_000_000_000];

        return [
            // 750.000 oktet dalam 60 detik = 100.000 bit per detik.
            'normal' => [$dasar, 100000.0, null],
            'selang dipakai apa adanya' => [['selang' => 30.0] + $dasar, 200000.0, null],
            'sampel pertama' => [['sebelum' => null] + $dasar, null, PenghitungLaju::SAMPEL_PERTAMA],
            'perangkat tidak punya penghitung' => [['sesudah' => null] + $dasar, null, PenghitungLaju::TIDAK_DIDUKUNG],
            'selang nol' => [['selang' => 0.0] + $dasar, null, PenghitungLaju::SELANG_TIDAK_VALID],
            'selang negatif' => [['selang' => -5.0] + $dasar, null, PenghitungLaju::SELANG_TIDAK_VALID],
            'pemeriksaan terlewat' => [['selang' => 600.0] + $dasar, null, PenghitungLaju::SELANG_TIDAK_VALID],
            'perangkat restart' => [['uptime' => [900000, 3000], 'sesudah' => 5000] + $dasar, null, PenghitungLaju::PERANGKAT_RESTART],
            'penghitung interface terputus' => [['diskontinuitas' => [0, 6500]] + $dasar, null, PenghitungLaju::PENGHITUNG_RESET],
            'penghitung 64-bit turun' => [['sesudah' => 500] + $dasar, null, PenghitungLaju::PENGHITUNG_RESET],
            // 32-bit berputar: (4294967296 - 4294000000) + 783000 = 1.750.296 oktet.
            'penghitung 32-bit berputar' => [['b64' => false, 'sebelum' => 4_294_000_000, 'sesudah' => 783_000] + $dasar, 233372.8, null],
            // Reset yang tampak seperti putaran menghasilkan laju melebihi kapasitas port 10 Mbps.
            'reset 32-bit pada port lambat' => [['b64' => false, 'sebelum' => 3_000_000_000, 'sesudah' => 100, 'kecepatan' => 10_000_000] + $dasar, null, PenghitungLaju::TIDAK_MASUK_AKAL],
            'tanpa data uptime dan diskontinuitas' => [['uptime' => [null, null], 'diskontinuitas' => [null, null], 'kecepatan' => null] + $dasar, 100000.0, null],
            'tidak ada trafik' => [['sesudah' => 1_000_000] + $dasar, 0.0, null],
        ];
    }

    /**
     * @param  array<string, mixed>  $k
     */
    #[DataProvider('kasusLaju')]
    public function test_perhitungan_laju(array $k, ?float $bps, ?string $alasan): void
    {
        $hasil = PenghitungLaju::hitung(
            $k['sebelum'], $k['sesudah'], $k['selang'], $k['maks'], $k['b64'],
            $k['uptime'][0], $k['uptime'][1], $k['diskontinuitas'][0], $k['diskontinuitas'][1], $k['kecepatan'],
        );

        $this->assertSame(['bps' => $bps, 'alasan' => $alasan], $hasil);
    }

    /**
     * Menyimpan satu hasil SNMP lewat jalur yang sama dengan kolektor.
     */
    private function simpan(EndpointMonitoring $endpoint, KlienSnmpTiruan $klien, int $detik): void
    {
        $pada = $this->awal->addSeconds($detik);
        $this->travelTo($pada);

        app(LayananStatus::class)->terimaHasil(
            $endpoint->refresh(),
            HasilAdapter::berhasil(5)->denganSnmp($this->baca($klien)),
            $pada,
            (string) Str::uuid(),
            'uji',
            false,
        );
    }

    private function endpointSnmp(string $portUplink = 'Gi0/1'): EndpointMonitoring
    {
        $perangkat = Perangkat::factory()->dipantau()->create(['opd_id' => Opd::factory()->create()->id, 'port_uplink' => $portUplink]);

        return EndpointMonitoring::query()->where('perangkat_id', $perangkat->id)->sole();
    }

    /**
     * @param  array<string, mixed>  $ubah
     * @return array<string, mixed>
     */
    private function gi(int $masuk, int $keluar, array $ubah = []): array
    {
        return $ubah + ['descr' => 'GigabitEthernet0/1', 'nama' => 'Gi0/1', 'mbps' => 1000, 'mac' => '0:1a:2b:3c:4d:5e', 'admin' => 1, 'oper' => 1,
            'masuk64' => $masuk, 'keluar64' => $keluar, 'galat_masuk' => 10, 'galat_keluar' => 0, 'buang_masuk' => 0, 'buang_keluar' => 5, 'diskontinuitas' => 0];
    }

    public function test_laju_dihitung_dari_dua_sampel_dan_hanya_uplink_yang_berderet_waktu(): void
    {
        $endpoint = $this->endpointSnmp();
        $lain = ['descr' => 'GigabitEthernet0/2', 'nama' => 'Gi0/2', 'mbps' => 1000, 'admin' => 1, 'oper' => 1, 'masuk64' => 100, 'keluar64' => 100];

        $this->simpan($endpoint, KlienSnmpTiruan::switch([1 => $this->gi(1_000_000, 2_000_000), 2 => $lain], uptimeTiktik: 100000), 0);

        $uplink = InterfaceSnmp::query()->where('nama', 'Gi0/1')->sole();
        $this->assertTrue($uplink->uplink);
        $this->assertNull($uplink->bps_masuk);
        $this->assertSame(PenghitungLaju::SAMPEL_PERTAMA, $uplink->alasan_laju_kosong);
        $this->assertFalse(InterfaceSnmp::query()->where('nama', 'Gi0/2')->sole()->uplink);

        // 60 detik kemudian: +750.000 oktet masuk, +1.500.000 keluar, +2 galat masuk, +1 buang keluar.
        $this->simpan($endpoint, KlienSnmpTiruan::switch([
            1 => $this->gi(1_750_000, 3_500_000, ['galat_masuk' => 12, 'buang_keluar' => 6]),
            2 => ['masuk64' => 100 + 75_000] + $lain,
        ], uptimeTiktik: 106000), 60);

        $uplink->refresh();
        $this->assertSame(['100000.00', '200000.00', null], [$uplink->bps_masuk, $uplink->bps_keluar, $uplink->alasan_laju_kosong]);
        $this->assertSame('10000.00', InterfaceSnmp::query()->where('nama', 'Gi0/2')->sole()->bps_masuk);

        // Deret waktu hanya untuk uplink.
        $sampel = SampelTrafik::query()->orderBy('id')->get();
        $this->assertCount(2, $sampel);
        $this->assertTrue($sampel->every(fn (SampelTrafik $s) => $s->interface_snmp_id === $uplink->id));
        $this->assertNull($sampel[0]->bps_masuk);
        $this->assertNull($sampel[0]->galat_masuk, 'Sampel pertama: selisih galat belum diketahui, bukan nol.');
        $this->assertSame(['60.00', '100000.00', '2', '0', '0', '1'], [
            $sampel[1]->selang_detik, $sampel[1]->bps_masuk, $sampel[1]->galat_masuk, $sampel[1]->galat_keluar, $sampel[1]->buang_masuk, $sampel[1]->buang_keluar,
        ]);

        $snmp = SnmpPerangkat::query()->sole();
        $this->assertSame(['sw-uji-01', 1060], [$snmp->sys_name, $snmp->uptimeDetik()]);
        $this->assertNull($snmp->restart_terdeteksi_pada);
    }

    public function test_interface_dikenali_dari_nama_meski_ifindex_berubah(): void
    {
        $endpoint = $this->endpointSnmp();

        $this->simpan($endpoint, KlienSnmpTiruan::switch([1 => $this->gi(1_000_000, 1_000_000), 2 => ['descr' => 'Vlan10', 'nama' => 'Vl10']], uptimeTiktik: 100000), 0);
        $idAwal = InterfaceSnmp::query()->where('nama', 'Gi0/1')->sole()->id;

        // Modul ditambahkan: Gi0/1 kini ber-ifIndex 5, dan ifIndex 1 dipakai interface lain.
        $this->simpan($endpoint, KlienSnmpTiruan::switch([
            1 => ['descr' => 'TenGigabitEthernet1/1', 'nama' => 'Te1/1', 'masuk64' => 999_999_999_999, 'keluar64' => 1],
            5 => $this->gi(1_750_000, 1_000_000),
        ], uptimeTiktik: 106000), 60);

        $gi = InterfaceSnmp::query()->where('nama', 'Gi0/1')->sole();

        $this->assertSame($idAwal, $gi->id, 'Baris yang sama, bukan interface baru.');
        $this->assertSame(5, $gi->if_index);
        $this->assertTrue($gi->if_index_berubah_pada?->equalTo($this->awal->addSeconds(60)));
        // Penghitung dibandingkan dengan milik Gi0/1 sendiri, bukan dengan interface yang kini ber-ifIndex 1.
        $this->assertSame('100000.00', $gi->bps_masuk);
        $this->assertTrue($gi->uplink);

        $te = InterfaceSnmp::query()->where('nama', 'Te1/1')->sole();
        $this->assertSame(1, $te->if_index);
        $this->assertSame(PenghitungLaju::SAMPEL_PERTAMA, $te->alasan_laju_kosong);
        $this->assertSame(3, InterfaceSnmp::query()->count());
    }

    public function test_alamat_fisik_berbeda_di_bawah_nama_yang_sama_tidak_menyambung_penghitung(): void
    {
        $endpoint = $this->endpointSnmp();

        $this->simpan($endpoint, KlienSnmpTiruan::switch([1 => $this->gi(9_000_000_000, 9_000_000_000)], uptimeTiktik: 100000), 0);
        // Kartu jaringan diganti: nama sama, alamat fisik berbeda, penghitung mulai dari awal.
        $this->simpan($endpoint, KlienSnmpTiruan::switch([1 => $this->gi(5_000, 5_000, ['mac' => '0:1a:2b:aa:bb:cc'])], uptimeTiktik: 106000), 60);

        $gi = InterfaceSnmp::query()->sole();
        $this->assertSame('001a2baabbcc', $gi->alamat_fisik);
        $this->assertNull($gi->bps_masuk);
        $this->assertSame(PenghitungLaju::IDENTITAS_BERUBAH, $gi->alasan_laju_kosong);
        $this->assertNull(SampelTrafik::query()->latest('id')->firstOrFail()->galat_masuk);

        // Sampel berikutnya dihitung dari garis dasar yang baru.
        $this->simpan($endpoint, KlienSnmpTiruan::switch([1 => $this->gi(755_000, 5_000, ['mac' => '0:1a:2b:aa:bb:cc'])], uptimeTiktik: 112000), 120);
        $this->assertSame('100000.00', $gi->refresh()->bps_masuk);
    }

    public function test_restart_perangkat_dan_pemeriksaan_terlewat_tidak_menghasilkan_laju_palsu(): void
    {
        $endpoint = $this->endpointSnmp();

        $this->simpan($endpoint, KlienSnmpTiruan::switch([1 => $this->gi(8_000_000_000, 8_000_000_000)], uptimeTiktik: 50_000_000), 0);
        // Perangkat restart: uptime kembali kecil dan penghitung mulai dari nol.
        $this->simpan($endpoint, KlienSnmpTiruan::switch([1 => $this->gi(40_000, 40_000)], uptimeTiktik: 3000), 60);

        $gi = InterfaceSnmp::query()->sole();
        $this->assertNull($gi->bps_masuk);
        $this->assertSame(PenghitungLaju::PERANGKAT_RESTART, $gi->alasan_laju_kosong);
        $this->assertTrue(SnmpPerangkat::query()->sole()->restart_terdeteksi_pada?->equalTo($this->awal->addSeconds(60)));
        $this->assertNull(SampelTrafik::query()->latest('id')->firstOrFail()->buang_keluar);

        // Pulih normal pada sampel berikutnya.
        $this->simpan($endpoint, KlienSnmpTiruan::switch([1 => $this->gi(790_000, 40_000)], uptimeTiktik: 9000), 120);
        $this->assertSame('100000.00', $gi->refresh()->bps_masuk);

        // Kolektor mati 20 menit: selang terlalu panjang untuk dipercaya.
        $this->simpan($endpoint, KlienSnmpTiruan::switch([1 => $this->gi(900_000_000, 40_000)], uptimeTiktik: 129000), 1320);
        $this->assertNull($gi->refresh()->bps_masuk);
        $this->assertSame(PenghitungLaju::SELANG_TIDAK_VALID, $gi->alasan_laju_kosong);
    }

    public function test_metrik_tidak_tersedia_tersimpan_sebagai_null(): void
    {
        $endpoint = $this->endpointSnmp('FastEthernet0/7');

        $this->simpan($endpoint, KlienSnmpTiruan::switch([7 => ['descr' => 'FastEthernet0/7', 'admin' => 1, 'oper' => 1]]), 0);
        $this->simpan($endpoint, KlienSnmpTiruan::switch([7 => ['descr' => 'FastEthernet0/7', 'admin' => 1, 'oper' => 1]]), 60);

        $fa = InterfaceSnmp::query()->sole();

        foreach (['alamat_fisik', 'alias', 'kecepatan_bps', 'oktet_masuk', 'oktet_keluar', 'penghitung_64_bit', 'galat_masuk', 'buang_keluar', 'bps_masuk', 'bps_keluar', 'sfp_rx_dbm', 'sfp_sumber'] as $kolom) {
            $this->assertNull($fa->getAttribute($kolom), "{$kolom} seharusnya NULL.");
        }

        $this->assertSame(PenghitungLaju::TIDAK_DIDUKUNG, $fa->alasan_laju_kosong);
        $this->assertTrue($fa->uplink, 'Uplink dikenali dari ifDescr.');
        $this->assertSame('up', InterfaceSnmp::labelStatus($fa->status_operasional));
        $this->assertSame('Tidak tersedia', InterfaceSnmp::labelStatus(null));

        $sampel = SampelTrafik::query()->latest('id')->firstOrFail();
        $this->assertNull($sampel->bps_masuk);
        $this->assertNull($sampel->galat_masuk);
    }
}
