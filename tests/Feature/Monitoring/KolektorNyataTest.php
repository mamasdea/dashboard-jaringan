<?php

namespace Tests\Feature\Monitoring;

use App\Console\Commands\UjiEndpoint;
use App\Enums\KeluaranPemeriksaan;
use App\Enums\StatusMonitoring;
use App\Models\EndpointMonitoring;
use App\Models\HasilPemeriksaan;
use App\Models\InterfaceSnmp;
use App\Models\ProfilSnmp;
use App\Monitoring\Adapter\AdapterJaringan;
use App\Monitoring\BatasPemeriksaan;
use App\Monitoring\DaftarIzinSasaran;
use App\Monitoring\Icmp\PemeriksaIcmp;
use App\Monitoring\Kolektor;
use App\Monitoring\Kontrak\AdapterPemeriksa;
use App\Monitoring\Penyamar;
use App\Monitoring\Snmp\GalatSnmp;
use App\Monitoring\Snmp\PabrikKlienSnmp;
use App\Support\BrankasKredensial;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimedOut;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Support\KlienSnmpTiruan;
use Tests\TestCase;

/**
 * Kolektor nyata (ICMP dan SNMP), diuji tanpa menyentuh jaringan: utilitas
 * ping dan sesi SNMP digantikan tiruan.
 */
class KolektorNyataTest extends TestCase
{
    use RefreshDatabase;

    private const string PING_WINDOWS = <<<'TXT'

        Pinging 198.51.100.10 with 32 bytes of data:
        Reply from 198.51.100.10: bytes=32 time=12ms TTL=62
        Reply from 198.51.100.10: bytes=32 time<1ms TTL=62
        Request timed out.
        Reply from 198.51.100.10: bytes=32 time=18ms TTL=62

        Ping statistics for 198.51.100.10:
            Packets: Sent = 4, Received = 3, Lost = 1 (25% loss),
        TXT;

    private const string PING_LINUX = <<<'TXT'
        PING 198.51.100.10 (198.51.100.10) 56(84) bytes of data.
        64 bytes from 198.51.100.10: icmp_seq=1 ttl=62 time=0.512 ms
        64 bytes from 198.51.100.10: icmp_seq=2 ttl=62 time=0.488 ms
        64 bytes from 198.51.100.10: icmp_seq=3 ttl=62 time=0.530 ms
        64 bytes from 198.51.100.10: icmp_seq=4 ttl=62 time=0.470 ms

        --- 198.51.100.10 ping statistics ---
        4 packets transmitted, 4 received, 0% packet loss, time 3004ms
        TXT;

    // Windows menghitung baris ini sebagai "Received"; bukan balasan dari sasaran.
    private const string PING_TIDAK_TERJANGKAU = <<<'TXT'
        Pinging 198.51.100.10 with 32 bytes of data:
        Reply from 10.0.0.1: Destination host unreachable.
        Reply from 10.0.0.1: Destination host unreachable.

        Ping statistics for 198.51.100.10:
            Packets: Sent = 2, Received = 2, Lost = 0 (0% loss),
        TXT;

    private CarbonImmutable $awal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->awal = CarbonImmutable::parse('2026-10-09 03:00:00', 'UTC');
        $this->travelTo($this->awal);

        config(['monitoring.adapter' => 'jaringan', 'monitoring.sasaran_diizinkan' => ['198.51.100.0/28']]);

        $kunci = BrankasKredensial::buatPasanganKunci();
        config(['monitoring.kunci_publik' => $kunci['publik'], 'monitoring.kunci_privat' => $kunci['privat']]);
    }

    private function pasangSnmp(KlienSnmpTiruan $klien): KlienSnmpTiruan
    {
        $this->app->instance(PabrikKlienSnmp::class, $klien);

        return $klien;
    }

    private function endpointSnmp(string $alamat = '198.51.100.10'): EndpointMonitoring
    {
        $profil = new ProfilSnmp(['nama' => 'Profil uji', 'versi' => 'v3']);
        $profil->aturKredensial(['pengguna' => 'pemantau', 'tingkat_keamanan' => 'authPriv', 'sandi_auth' => 'sandi-auth-rahasia-1', 'sandi_priv' => 'sandi-priv-rahasia-2'])->save();

        return EndpointMonitoring::factory()->create(['alamat' => $alamat, 'metode' => 'snmp', 'profil_snmp_id' => $profil->id, 'port_uplink' => 'Gi0/1']);
    }

    public function test_daftar_izin_sasaran(): void
    {
        $daftar = new DaftarIzinSasaran(['198.51.100.10', '10.20.0.0/22', '2001:db8:10::/64', ' 192.0.2.7/32 ', 'bukan-alamat', '10.0.0.0/99']);

        foreach (['198.51.100.10', '10.20.0.1', '10.20.3.255', '2001:db8:10::1', '2001:db8:10:0:ffff::9', '192.0.2.7'] as $boleh) {
            $this->assertTrue($daftar->mengizinkan($boleh), "{$boleh} seharusnya diizinkan.");
        }

        foreach (['198.51.100.11', '10.20.4.0', '10.19.255.255', '2001:db8:11::1', '192.0.2.8', 'switch.example.test', 'localhost', '10.20.0.1; rm -rf /', '', '10.20.0', '999.1.1.1'] as $tolak) {
            $this->assertFalse($daftar->mengizinkan($tolak), "'{$tolak}' seharusnya ditolak.");
        }

        $this->assertSame(['bukan-alamat', '10.0.0.0/99'], $daftar->butirTidakValid());

        // Daftar kosong menolak semuanya; awalan /0 harus ditulis dengan sengaja.
        $this->assertFalse((new DaftarIzinSasaran([]))->mengizinkan('198.51.100.10'));
        $this->assertTrue((new DaftarIzinSasaran(['0.0.0.0/0']))->mengizinkan('198.51.100.10'));
        $this->assertFalse((new DaftarIzinSasaran(['0.0.0.0/0']))->mengizinkan('2001:db8::1'));
    }

    public function test_keluaran_ping_diurai_untuk_windows_dan_linux(): void
    {
        $windows = PemeriksaIcmp::urai(self::PING_WINDOWS, 4);
        $this->assertSame(KeluaranPemeriksaan::Berhasil, $windows?->keluaran);
        $this->assertSame(25.0, $windows?->packetLossPersen);
        // (12 + 0,5 + 18) / 3; "time<1ms" dihitung setengah milidetik.
        $this->assertSame(10.17, $windows?->latencyMs);

        $linux = PemeriksaIcmp::urai(self::PING_LINUX, 4);
        $this->assertSame([0.5, 0.0], [$linux?->latencyMs, $linux?->packetLossPersen]);

        // Sistem berbahasa lain: tetap terbaca dari TTL dan satuan ms.
        $lokal = PemeriksaIcmp::urai("Balasan dari 198.51.100.10: byte=32 waktu=3ms TTL=64\nBalasan dari 198.51.100.10: byte=32 waktu=5ms TTL=64", 2);
        $this->assertSame([4.0, 0.0], [$lokal?->latencyMs, $lokal?->packetLossPersen]);

        $this->assertNull(PemeriksaIcmp::urai(self::PING_TIDAK_TERJANGKAU, 2));
        $this->assertNull(PemeriksaIcmp::urai("Request timed out.\nRequest timed out.", 2));
        $this->assertNull(PemeriksaIcmp::urai('', 4));
    }

    public function test_ping_dijalankan_dengan_larik_argumen_dan_batas_waktu(): void
    {
        Process::fake(['*' => Process::result(output: PHP_OS_FAMILY === 'Windows' ? self::PING_WINDOWS : self::PING_LINUX)]);

        $hasil = app(PemeriksaIcmp::class)->periksa('198.51.100.10');

        $this->assertSame(KeluaranPemeriksaan::Berhasil, $hasil->keluaran);

        Process::assertRan(function (PendingProcess $proses): bool {
            // Larik, bukan string: tidak ada shell yang menafsirkan isinya.
            $this->assertIsArray($proses->command);
            $this->assertSame('ping', $proses->command[0]);
            $this->assertSame('198.51.100.10', end($proses->command));
            $this->assertContains('4', $proses->command);
            $this->assertNotNull($proses->timeout);
            $this->assertLessThanOrEqual(60, $proses->timeout);

            return true;
        });
    }

    public function test_sasaran_yang_bukan_alamat_ip_tidak_pernah_sampai_ke_ping(): void
    {
        Process::fake();

        foreach (['switch.example.test', '198.51.100.10 && whoami', '-f 198.51.100.10', '$(reboot)', "198.51.100.10\n-t"] as $jahat) {
            $hasil = app(PemeriksaIcmp::class)->periksa($jahat);

            $this->assertSame(KeluaranPemeriksaan::GalatKolektor, $hasil->keluaran);
            $this->assertSame('alamat_tidak_valid', $hasil->kodeGalat);
        }

        Process::assertNothingRan();
    }

    public function test_kegagalan_ping_dibedakan_perangkat_atau_kolektor(): void
    {
        // Ping berjalan, tidak ada balasan: perangkat.
        Process::fake(['*' => Process::result(output: self::PING_TIDAK_TERJANGKAU, exitCode: 1)]);
        $hasil = app(PemeriksaIcmp::class)->periksa('198.51.100.10');
        $this->assertSame([KeluaranPemeriksaan::PerangkatGagal, 'tanpa_jawaban', 100.0], [$hasil->keluaran, $hasil->kodeGalat, $hasil->packetLossPersen]);

        // Ping tidak menghasilkan apa pun (mis. tidak punya izin): kolektor.
        Process::fake(['*' => Process::result(output: '', errorOutput: 'ping: socket: Operation not permitted', exitCode: 2)]);
        $hasil = app(PemeriksaIcmp::class)->periksa('198.51.100.10');
        $this->assertSame([KeluaranPemeriksaan::GalatKolektor, 'ping_tidak_dapat_dijalankan'], [$hasil->keluaran, $hasil->kodeGalat]);
        $this->assertStringContainsString('Operation not permitted', (string) $hasil->pesan);

        // Ping macet melewati batas waktu: kolektor.
        Process::fake(['*' => fn () => throw new ProcessTimedOutException(new SymfonyTimedOut(new SymfonyProcess(['ping']), SymfonyTimedOut::TYPE_GENERAL), Process::result())]);
        $hasil = app(PemeriksaIcmp::class)->periksa('198.51.100.10');
        $this->assertSame([KeluaranPemeriksaan::GalatKolektor, 'batas_waktu_ping'], [$hasil->keluaran, $hasil->kodeGalat]);
    }

    public function test_setelan_di_luar_batas_dipangkas(): void
    {
        config([
            'monitoring.icmp.jumlah_paket' => 5000, 'monitoring.icmp.batas_waktu_ms' => 1,
            'monitoring.snmp.batas_waktu_ms' => 999999, 'monitoring.snmp.percobaan_ulang' => 50, 'monitoring.snmp.maks_interface' => 0,
        ]);

        $this->assertSame(10, BatasPemeriksaan::jumlahPaketIcmp());
        $this->assertSame(100, BatasPemeriksaan::batasWaktuIcmpMs());
        $this->assertSame(5000, BatasPemeriksaan::batasWaktuSnmpMs());
        $this->assertSame(3, BatasPemeriksaan::percobaanUlangSnmp());
        $this->assertSame(1, BatasPemeriksaan::maksInterface());

        Process::fake(['*' => Process::result(output: self::PING_LINUX)]);
        app(PemeriksaIcmp::class)->periksa('198.51.100.10');

        Process::assertRan(fn (PendingProcess $p) => in_array('10', (array) $p->command, true) && ! in_array('5000', (array) $p->command, true));
    }

    public function test_sasaran_di_luar_daftar_izin_tidak_dikirimi_paket(): void
    {
        Process::fake();
        $snmp = $this->pasangSnmp(new KlienSnmpTiruan);

        $this->assertInstanceOf(AdapterJaringan::class, app(AdapterPemeriksa::class));

        // 198.51.100.200 di luar 198.51.100.0/28.
        $endpoint = $this->endpointSnmp('198.51.100.200');

        app(Kolektor::class)->jalankanSekali();

        $hasil = HasilPemeriksaan::query()->sole();
        $this->assertSame([KeluaranPemeriksaan::GalatKolektor, 'sasaran_tidak_diizinkan', 'jaringan'], [$hasil->keluaran, $hasil->kode_galat, $hasil->sumber]);
        $this->assertFalse($hasil->simulasi);
        $this->assertNull($hasil->keluaran_snmp);
        // Bukan bukti endpoint mati: statusnya tetap belum diketahui.
        $this->assertSame(StatusMonitoring::BelumDiketahui, $endpoint->status()->sole()->statusTampil());

        Process::assertNothingRan();
        $this->assertSame([], $snmp->sesi);
    }

    public function test_ping_sukses_dan_snmp_gagal_dicatat_terpisah(): void
    {
        Process::fake(['*' => Process::result(output: self::PING_LINUX)]);
        $snmp = $this->pasangSnmp(new KlienSnmpTiruan);
        $snmp->galatSesi = GalatSnmp::perangkat('snmp_ditolak', 'Perangkat menolak kredensial SNMP.');

        $endpoint = $this->endpointSnmp();

        foreach ([0, 60, 120] as $detik) {
            $this->travelTo($this->awal->addSeconds($detik));
            app(Kolektor::class)->jalankanSekali();
        }

        $hasil = HasilPemeriksaan::query()->latest('id')->firstOrFail();
        $this->assertSame(KeluaranPemeriksaan::Berhasil, $hasil->keluaran);
        $this->assertSame('0.50', $hasil->latency_ms);
        $this->assertSame(KeluaranPemeriksaan::PerangkatGagal, $hasil->keluaran_snmp);
        $this->assertSame('snmp_ditolak', $hasil->kode_galat_snmp);
        $this->assertNull($hasil->kode_galat);

        // Terjangkau menurut ICMP; kegagalan SNMP terlihat tersendiri.
        $status = $endpoint->status()->sole();
        $this->assertSame(StatusMonitoring::Normal, $status->statusTampil());
        $this->assertSame(0, $status->gagal_berturut);
        $this->assertSame(KeluaranPemeriksaan::PerangkatGagal, $status->snmp_keluaran);
        $this->assertSame(3, $status->snmp_gagal_berturut);
        $this->assertNull($status->snmp_berhasil_terakhir_pada);
        $this->assertSame(0, InterfaceSnmp::query()->count());

        // SNMP pulih: penghitung gagalnya kembali nol.
        $snmp->galatSesi = null;
        $snmp->skalar = KlienSnmpTiruan::switch([])->skalar;
        $this->travelTo($this->awal->addSeconds(180));
        app(Kolektor::class)->jalankanSekali();

        $status->refresh();
        $this->assertSame([KeluaranPemeriksaan::Berhasil, 0], [$status->snmp_keluaran, $status->snmp_gagal_berturut]);
        $this->assertTrue($status->snmp_berhasil_terakhir_pada?->equalTo($this->awal->addSeconds(180)));
    }

    public function test_snmp_tidak_dicoba_bila_ping_gagal_atau_endpoint_tanpa_profil(): void
    {
        $snmp = $this->pasangSnmp(new KlienSnmpTiruan);

        Process::fake(['*' => Process::result(output: self::PING_TIDAK_TERJANGKAU, exitCode: 1)]);
        $this->endpointSnmp();
        app(Kolektor::class)->jalankanSekali();

        $hasil = HasilPemeriksaan::query()->sole();
        $this->assertSame(KeluaranPemeriksaan::PerangkatGagal, $hasil->keluaran);
        $this->assertNull($hasil->keluaran_snmp);
        $this->assertSame([], $snmp->sesi);

        // Endpoint ICMP saja.
        Process::fake(['*' => Process::result(output: self::PING_LINUX)]);
        EndpointMonitoring::factory()->create(['alamat' => '198.51.100.11']);
        $this->travelTo($this->awal->addSeconds(1));
        app(Kolektor::class)->jalankanSekali();

        $this->assertSame([], $snmp->sesi);
        $this->assertNull(HasilPemeriksaan::query()->latest('id')->firstOrFail()->keluaran_snmp);
    }

    public function test_kolektor_nyata_menyimpan_data_snmp_dan_kredensial_hanya_dibuka_untuk_sesi(): void
    {
        Process::fake(['*' => Process::result(output: self::PING_LINUX)]);
        Log::spy();

        $snmp = $this->pasangSnmp(KlienSnmpTiruan::switch([
            1 => ['descr' => 'GigabitEthernet0/1', 'nama' => 'Gi0/1', 'mbps' => 1000, 'admin' => 1, 'oper' => 1, 'masuk64' => 1000, 'keluar64' => 2000],
        ]));

        $endpoint = $this->endpointSnmp();
        app(Kolektor::class)->jalankanSekali();

        // Kredensial dibuka dari profil tersegel dan diteruskan ke sesi, bukan ke proses atau log.
        $this->assertSame('sandi-auth-rahasia-1', $snmp->sesi[0]['kredensial']['sandi_auth']);
        $this->assertSame(['198.51.100.10', 'v3'], [$snmp->sesi[0]['alamat'], $snmp->sesi[0]['versi']]);

        $this->assertSame(KeluaranPemeriksaan::Berhasil, HasilPemeriksaan::query()->sole()->keluaran_snmp);
        $this->assertTrue(InterfaceSnmp::query()->where('endpoint_id', $endpoint->id)->sole()->uplink);

        Process::assertRan(fn (PendingProcess $p) => ! str_contains(implode(' ', (array) $p->command), 'rahasia'));
        Log::shouldNotHaveReceived('error');
        Log::shouldNotHaveReceived('warning');

        // Tidak ada kredensial polos di tabel mana pun.
        foreach (['hasil_pemeriksaan', 'status_terkini', 'endpoint_monitoring', 'profil_snmp', 'snmp_perangkat', 'interface_snmp', 'log_aktivitas'] as $tabel) {
            $isi = (string) json_encode(DB::table($tabel)->get());

            $this->assertStringNotContainsString('sandi-auth-rahasia-1', $isi, $tabel);
            $this->assertStringNotContainsString('sandi-priv-rahasia-2', $isi, $tabel);
        }
    }

    public function test_penyamar_menghapus_rahasia_dari_teks(): void
    {
        $this->assertSame(
            'gagal: [disamarkan] ditolak, juga [disamarkan]',
            Penyamar::bersihkan('gagal: SandiRahasia123 ditolak, juga sandirahasia123', ['SandiRahasia123', null, 42, 'ab']),
        );
        $this->assertNull(Penyamar::bersihkan(null, ['x']));
        // Nilai sangat pendek tidak dipakai menyamarkan, supaya teks biasa tidak rusak.
        $this->assertSame('authPriv gagal', Penyamar::bersihkan('authPriv gagal', ['au']));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function konfigurasiUjiTidakLengkap(): array
    {
        return [
            'alamat kosong' => [['monitoring.uji.alamat' => null], 'MONITORING_UJI_ALAMAT'],
            'alamat berupa nama host' => [['monitoring.uji.alamat' => 'switch.example.test'], 'MONITORING_UJI_ALAMAT'],
            'di luar daftar izin' => [['monitoring.uji.alamat' => '198.51.100.200'], 'MONITORING_SASARAN_DIIZINKAN'],
            'versi snmp tidak dikenal' => [['monitoring.uji.alamat' => '198.51.100.10', 'monitoring.uji.snmp.versi' => 'v1'], 'MONITORING_UJI_SNMP_VERSI'],
        ];
    }

    /**
     * @param  array<string, mixed>  $konfigurasi
     */
    #[DataProvider('konfigurasiUjiTidakLengkap')]
    public function test_perintah_uji_menolak_konfigurasi_yang_salah_tanpa_mengirim_paket(array $konfigurasi, string $pesan): void
    {
        Process::fake();
        $snmp = $this->pasangSnmp(new KlienSnmpTiruan);
        config($konfigurasi);

        $this->artisan('monitoring:uji-endpoint')->expectsOutputToContain($pesan)->assertFailed();

        Process::assertNothingRan();
        $this->assertSame([], $snmp->sesi);
    }

    public function test_perintah_uji_satu_endpoint_dari_environment(): void
    {
        Process::fake(['*' => Process::result(output: self::PING_LINUX)]);
        $this->pasangSnmp(KlienSnmpTiruan::switch([
            1 => ['descr' => 'GigabitEthernet0/1', 'nama' => 'Gi0/1', 'mbps' => 1000, 'mac' => '0:1a:2b:3c:4d:5e', 'admin' => 1, 'oper' => 1, 'masuk64' => 1000, 'keluar64' => 2000],
            2 => ['descr' => 'FastEthernet0/2', 'admin' => 1, 'oper' => 2],
        ]));

        config([
            'monitoring.uji.alamat' => '198.51.100.10',
            'monitoring.uji.port_uplink' => 'Gi0/1',
            'monitoring.uji.snmp.versi' => 'v3',
            'monitoring.uji.snmp.pengguna' => 'pemantau',
            'monitoring.uji.snmp.sandi_auth' => 'sandi-auth-rahasia-1',
            'monitoring.uji.snmp.sandi_priv' => 'sandi-priv-rahasia-2',
        ]);

        $this->assertSame(0, Artisan::call('monitoring:uji-endpoint', ['--semua' => true]));
        $keluaran = Artisan::output();

        foreach ([
            'Endpoint uji: 198.51.100.10', 'tidak ada pemindaian subnet', 'ICMP : Berhasil', 'SNMP : Berhasil', 'sw-uji-01',
            'UPLINK', '00:1a:2b:3c:4d:5e', 'up/up', '1,00 Gbps', 'butuh sampel ke-2',
            // Interface kedua tidak punya penghitung, kecepatan, maupun alamat fisik.
            'FastEthernet0/2', 'Tidak tersedia / Tidak tersedia', 'optik SFP   : Tidak tersedia',
        ] as $diharapkan) {
            $this->assertStringContainsString($diharapkan, $keluaran);
        }

        // Rahasia dari environment tidak pernah ditampilkan.
        foreach (['sandi-auth-rahasia-1', 'sandi-priv-rahasia-2', 'pemantau'] as $rahasia) {
            $this->assertStringNotContainsString($rahasia, $keluaran);
        }

        // Tanpa --simpan tidak ada yang ditulis ke database.
        $this->assertSame(0, EndpointMonitoring::query()->count());
        $this->assertSame(0, HasilPemeriksaan::query()->count());
        $this->assertSame(0, ProfilSnmp::query()->count());

        $this->artisan('monitoring:uji-endpoint', ['--simpan' => true])->assertSuccessful();

        $endpoint = EndpointMonitoring::query()->sole();
        $this->assertSame([UjiEndpoint::NAMA_ENDPOINT, '198.51.100.10', 'snmp', 'Gi0/1'], [$endpoint->nama, $endpoint->alamat, $endpoint->metode, $endpoint->port_uplink]);
        $this->assertFalse($endpoint->simulasi);

        // Kredensial dari environment tersimpan tersegel, tidak polos.
        $profil = ProfilSnmp::query()->sole();
        $this->assertSame('sandi-auth-rahasia-1', $profil->kredensial()['sandi_auth']);
        $this->assertStringNotContainsString('sandi-auth-rahasia-1', (string) DB::table('profil_snmp')->value('kredensial_tersegel'));

        $this->assertSame(KeluaranPemeriksaan::Berhasil, HasilPemeriksaan::query()->sole()->keluaran_snmp);
        $this->assertSame(StatusMonitoring::Normal, $endpoint->status()->sole()->status);
        $this->assertTrue(InterfaceSnmp::query()->where('nama', 'Gi0/1')->sole()->uplink);

        // Menjalankan ulang memperbarui endpoint yang sama, lalu kolektor ikut memeriksanya.
        $this->travelTo($this->awal->addSeconds(60));
        $this->artisan('monitoring:uji-endpoint', ['--simpan' => true])->assertSuccessful();
        $this->assertSame(1, EndpointMonitoring::query()->count());

        $this->travelTo($this->awal->addSeconds(120));
        $this->assertSame(1, app(Kolektor::class)->jalankanSekali()['diperiksa']);
        $this->assertSame(3, HasilPemeriksaan::query()->count());
    }

    public function test_perintah_uji_melaporkan_snmp_gagal_tanpa_menyebut_icmp_gagal(): void
    {
        Process::fake(['*' => Process::result(output: self::PING_LINUX)]);
        $snmp = $this->pasangSnmp(new KlienSnmpTiruan);
        $snmp->galatSesi = GalatSnmp::perangkat('snmp_tanpa_jawaban', 'Perangkat tidak menjawab permintaan SNMP.');

        config(['monitoring.uji.alamat' => '198.51.100.10', 'monitoring.uji.snmp.versi' => 'v2c', 'monitoring.uji.snmp.community' => 'community-rahasia-xyz']);

        $this->artisan('monitoring:uji-endpoint')
            ->expectsOutputToContain('ICMP : Berhasil')
            ->expectsOutputToContain('SNMP : Endpoint tidak menjawab [snmp_tanpa_jawaban]')
            ->expectsOutputToContain('community-nya baca-saja')
            ->doesntExpectOutputToContain('community-rahasia-xyz')
            ->assertFailed();
    }
}
