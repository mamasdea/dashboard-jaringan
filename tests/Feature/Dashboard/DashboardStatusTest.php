<?php

namespace Tests\Feature\Dashboard;

use App\Dashboard\KesehatanKolektor;
use App\Enums\Peran;
use App\Enums\StatusMonitoring;
use App\Models\DetakKolektor;
use App\Models\EndpointMonitoring;
use App\Models\HasilPemeriksaan;
use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\User;
use App\Monitoring\HasilAdapter;
use App\Monitoring\Kolektor;
use App\Monitoring\Kontrak\AdapterPemeriksa;
use App\Monitoring\LayananStatus;
use App\Monitoring\SasaranPemeriksaan;
use App\Monitoring\Snmp\PabrikKlienSnmp;
use App\Monitoring\Snmp\PemeriksaSnmp;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\KlienSnmpTiruan;
use Tests\TestCase;

class DashboardStatusTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $awal;

    private LayananStatus $layanan;

    protected function setUp(): void
    {
        parent::setUp();

        // 10.00 WIB.
        $this->awal = CarbonImmutable::parse('2026-10-09 03:00:00', 'UTC');
        $this->travelTo($this->awal);
        $this->layanan = app(LayananStatus::class);
    }

    /**
     * OPD dengan switch utama yang dipantau.
     */
    private function opd(string $kode, string $nama, array $perangkat = []): Opd
    {
        $opd = Opd::factory()->create(['kode' => $kode, 'nama' => $nama, 'lintang' => -7.36, 'bujur' => 109.90]);

        Perangkat::factory()->switchUtama()->dipantau()->create($perangkat + [
            'opd_id' => $opd->id, 'kode_aset' => "SW-{$kode}", 'nama' => "Switch Utama {$nama}",
        ]);

        return $opd;
    }

    private function endpoint(Opd $opd): EndpointMonitoring
    {
        return EndpointMonitoring::query()->whereHas('perangkat', fn ($q) => $q->where('opd_id', $opd->id))->sole();
    }

    /**
     * Hasil pemeriksaan yang dilakukan $detikLalu detik sebelum sekarang.
     */
    private function hasil(Opd $opd, HasilAdapter $hasil, int $detikLalu = 0): void
    {
        $this->layanan->terimaHasil($this->endpoint($opd), $hasil, now()->subSeconds($detikLalu), (string) Str::uuid(), 'uji', false);
    }

    private function tidakTerjangkau(Opd $opd): void
    {
        foreach ([120, 60, 0] as $detikLalu) {
            $this->hasil($opd, HasilAdapter::gagalPerangkat(), $detikLalu);
        }
    }

    /**
     * Satu OPD untuk tiap status, ditambah OPD tanpa switch utama dan OPD nonaktif.
     *
     * @return array<string, Opd>
     */
    private function limaStatus(): array
    {
        $opd = [
            'normal' => $this->opd('SETDA', 'Sekretariat Daerah'),
            'menurun' => $this->opd('DISDIK', 'Dinas Pendidikan'),
            'tidak_terjangkau' => $this->opd('DINKES', 'Dinas Kesehatan'),
            'belum_diketahui' => $this->opd('BAPPEDA', 'Bappeda'),
            'pemeliharaan' => $this->opd('DPUPR', 'Dinas Pekerjaan Umum'),
            'tanpa_switch' => Opd::factory()->create(['kode' => 'ARSIP', 'nama' => 'Dinas Kearsipan']),
        ];

        Opd::factory()->nonaktif()->create(['kode' => 'LAMA', 'nama' => 'OPD Yang Sudah Dibubarkan']);

        $this->hasil($opd['normal'], HasilAdapter::berhasil(8.5, 0));
        $this->hasil($opd['menurun'], HasilAdapter::berhasil(320, 12));
        $this->tidakTerjangkau($opd['tidak_terjangkau']);
        $this->layanan->mulaiPemeliharaan($this->endpoint($opd['pemeliharaan']), catatan: 'Ganti switch');

        return $opd;
    }

    /**
     * @return array<string, array{Peran}>
     */
    public static function semuaPeran(): array
    {
        return ['administrator' => [Peran::Administrator], 'operator' => [Peran::Operator], 'teknisi' => [Peran::Teknisi], 'viewer' => [Peran::Viewer]];
    }

    #[DataProvider('semuaPeran')]
    public function test_semua_peran_dapat_membuka_dashboard_dan_detail_opd(Peran $peran): void
    {
        $opd = $this->limaStatus();
        $this->actingAs(User::factory()->peran($peran)->create());

        $this->get(route('dashboard'))->assertOk()->assertSee('Status Koneksi OPD ke Diskominfo');
        $this->get(route('dashboard.peta'))->assertOk();
        $this->get(route('dashboard.opd', $opd['normal']))->assertOk()->assertSee('Sekretariat Daerah');
    }

    public function test_tamu_dialihkan_ke_halaman_masuk(): void
    {
        $opd = $this->opd('SETDA', 'Sekretariat Daerah');

        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('dashboard.peta'))->assertRedirect(route('login'));
        $this->get(route('dashboard.opd', $opd))->assertRedirect(route('login'));
    }

    public function test_jumlah_opd_per_status(): void
    {
        $this->limaStatus();
        $this->actingAs(User::factory()->viewer()->create());

        $komponen = Livewire::test('pages::dashboard.index');

        // OPD tanpa switch utama terhitung "belum diketahui"; OPD nonaktif tidak dihitung.
        $this->assertSame(
            ['normal' => 1, 'menurun' => 1, 'tidak_terjangkau' => 1, 'belum_diketahui' => 2, 'pemeliharaan' => 1],
            $komponen->instance()->jumlah,
        );

        $komponen
            ->assertSee('Normal')->assertSee('Koneksi menurun')->assertSee('Endpoint tidak terjangkau')
            ->assertSee('Belum diketahui')->assertSee('Pemeliharaan')
            ->assertSee('Semua OPD')
            ->assertSee('Dinas Kearsipan')
            ->assertSee('OPD belum memiliki switch utama.')
            ->assertSee('Belum ada hasil pemeriksaan.')
            ->assertDontSee('OPD Yang Sudah Dibubarkan');
    }

    public function test_status_ditampilkan_dengan_teks_dan_ikon_bukan_hanya_warna(): void
    {
        $this->limaStatus();
        $this->actingAs(User::factory()->viewer()->create());

        $isi = (string) $this->get(route('dashboard'))->assertOk()->getContent();

        foreach (StatusMonitoring::cases() as $status) {
            // Lencana status memuat labelnya sebagai teks dan penanda status untuk pembaca layar/uji.
            $this->assertStringContainsString('data-status="'.$status->value.'"', $isi);
            $this->assertStringContainsString($status->label(), $isi);
            // Legenda peta: huruf kode di samping label.
            $this->assertMatchesRegularExpression('/peta-status-'.$status->value.'"[^>]*>'.preg_quote($status->kode(), '/').'</', $isi);
        }

        // Kelima kode berbeda, dan tiap status punya ikon sendiri.
        $this->assertCount(5, array_unique(array_map(fn (StatusMonitoring $s) => $s->kode(), StatusMonitoring::cases())));
        $this->assertCount(5, array_unique(array_map(fn (StatusMonitoring $s) => $s->ikon(), StatusMonitoring::cases())));
        $this->assertGreaterThanOrEqual(5, substr_count($isi, '<svg'));
    }

    public function test_tidak_ada_klaim_internet_aktif(): void
    {
        $opd = $this->limaStatus();
        $this->actingAs(User::factory()->administrator()->create());

        foreach ([route('dashboard'), route('dashboard.opd', $opd['normal']), route('dashboard.peta')] as $url) {
            $isi = mb_strtolower((string) $this->get($url)->assertOk()->getContent());

            foreach (['internet aktif', 'internet normal', 'internet ok', 'internet berfungsi', 'internet lancar', '>online<', 'status: online', 'terhubung ke internet'] as $terlarang) {
                $this->assertStringNotContainsString($terlarang, $isi, "{$url} memuat \"{$terlarang}\".");
            }
        }

        // Yang dinyatakan justru batasannya.
        $this->get(route('dashboard'))->assertSee('Bukan ukuran layanan internet di OPD.');
        $this->get(route('dashboard.opd', $opd['normal']))->assertSee('bukan layanan internet di OPD');
    }

    public function test_daftar_gangguan_aktif(): void
    {
        $opd = $this->limaStatus();
        $this->actingAs(User::factory()->viewer()->create());

        $komponen = Livewire::test('pages::dashboard.index');

        $this->assertSame(['Dinas Kesehatan'], $komponen->instance()->gangguan->map(fn ($s) => $s->opd->nama)->all());

        $komponen
            ->assertSee('Gangguan aktif (1)')
            // Insiden dibuka pada kegagalan ketiga, 10.00 WIB.
            ->assertSee('09-10-2026 10:00:00')
            ->assertDontSee('Tidak ada gangguan aktif yang tercatat.');

        // Lama gangguan bertambah seiring waktu.
        $this->travelTo($this->awal->addMinutes(2));
        $this->hasil($opd['tidak_terjangkau'], HasilAdapter::gagalPerangkat());
        $this->assertStringContainsString('0 jam 2 menit', (string) $this->get(route('dashboard'))->getContent());

        // Pulih: tidak lagi di daftar.
        foreach ([60, 0] as $detikLalu) {
            $this->travelTo(now()->addSeconds(60));
            $this->hasil($opd['tidak_terjangkau'], HasilAdapter::berhasil(5));
        }

        Livewire::test('pages::dashboard.index')
            ->assertSee('Gangguan aktif (0)')
            ->assertSee('Tidak ada gangguan aktif yang tercatat.');
    }

    public function test_waktu_pemeriksaan_terakhir_ditampilkan_dalam_wib(): void
    {
        $this->actingAs(User::factory()->viewer()->create());

        $this->get(route('dashboard'))->assertSee('Belum ada pemeriksaan');

        $opd = $this->opd('SETDA', 'Sekretariat Daerah');
        $this->hasil($opd, HasilAdapter::berhasil(5), detikLalu: 45);

        // 02.59.15 UTC = 09.59.15 WIB.
        $this->get(route('dashboard'))
            ->assertSee('09-10-2026 09:59:15 WIB')
            ->assertSee('Pemeriksaan terakhir')
            ->assertDontSee('Belum ada pemeriksaan');
    }

    public function test_hasil_kedaluwarsa_tidak_ditampilkan_sebagai_normal(): void
    {
        $this->limaStatus();
        $this->actingAs(User::factory()->viewer()->create());

        // Kolektor mati 10 menit. Tidak ada yang menyapu status tersimpan.
        $this->travelTo($this->awal->addMinutes(10));

        $jumlah = Livewire::test('pages::dashboard.index')->instance()->jumlah;

        $this->assertSame(0, $jumlah['normal']);
        $this->assertSame(0, $jumlah['menurun']);
        $this->assertSame(0, $jumlah['tidak_terjangkau']);
        $this->assertSame(5, $jumlah['belum_diketahui']);
        // Pemeliharaan tidak kedaluwarsa.
        $this->assertSame(1, $jumlah['pemeliharaan']);

        $this->get(route('dashboard'))
            ->assertSee('Hasil pemeriksaan terakhir sudah kedaluwarsa.')
            // Gangguan yang belum terbukti pulih tetap terdaftar.
            ->assertSee('Gangguan aktif (1)')
            ->assertSee('Belum ada bukti pulih.');
    }

    public function test_status_kesehatan_kolektor(): void
    {
        $this->actingAs(User::factory()->viewer()->create());
        $kesehatan = app(KesehatanKolektor::class);

        $this->assertSame(KesehatanKolektor::BELUM_PERNAH, $kesehatan->periksa()['status']);
        $this->get(route('dashboard'))->assertSee('Kolektor belum pernah berjalan')->assertSee('data-kolektor="belum_pernah"', false);

        $this->opd('SETDA', 'Sekretariat Daerah');
        app(Kolektor::class)->jalankanSekali();

        $hasil = $kesehatan->periksa();
        $this->assertSame([KesehatanKolektor::BERJALAN, 1, 'simulasi', true, 0, 0], [
            $hasil['status'], $hasil['proses_aktif'], $hasil['adapter'], $hasil['simulasi'], $hasil['endpoint_terlambat'], $hasil['galat_kolektor'],
        ]);
        $this->assertTrue($hasil['detak_terakhir']?->equalTo($this->awal));

        $this->get(route('dashboard'))
            ->assertSee('Kolektor berjalan')
            ->assertSee('data-kolektor="berjalan"', false)
            ->assertSee('Tanda hidup terakhir')
            ->assertSee('09-10-2026 10:00:00');

        // Tanpa tanda hidup: terlambat setelah 90 detik, berhenti setelah 5 menit.
        $this->travelTo($this->awal->addSeconds(90));
        $this->assertSame(KesehatanKolektor::BERJALAN, $kesehatan->periksa()['status']);

        $this->travelTo($this->awal->addSeconds(200));
        $this->assertSame(KesehatanKolektor::TERLAMBAT, $kesehatan->periksa()['status']);
        $this->assertSame(1, $kesehatan->periksa()['endpoint_terlambat']);
        $this->get(route('dashboard'))->assertSee('Kolektor perlu diperiksa');

        $this->travelTo($this->awal->addSeconds(301));
        $this->assertSame(KesehatanKolektor::BERHENTI, $kesehatan->periksa()['status']);
        $this->get(route('dashboard'))->assertSee('Kolektor berhenti')->assertSee('data-kolektor="berhenti"', false);

        // Hidup lagi: satu baris detak per proses, putarannya bertambah.
        app(Kolektor::class)->jalankanSekali();
        $this->assertSame(KesehatanKolektor::BERJALAN, $kesehatan->periksa()['status']);
        $this->assertSame([1, 2], [DetakKolektor::query()->count(), DetakKolektor::query()->sole()->putaran]);
    }

    public function test_kolektor_yang_hidup_tetapi_gagal_memeriksa_tidak_dinyatakan_sehat(): void
    {
        $opd = $this->opd('SETDA', 'Sekretariat Daerah');
        $this->endpoint($opd)->update(['skenario_simulasi' => 'galat_kolektor']);

        app(Kolektor::class)->jalankanSekali();

        $hasil = app(KesehatanKolektor::class)->periksa();

        $this->assertSame(KesehatanKolektor::TERLAMBAT, $hasil['status']);
        $this->assertSame(1, $hasil['galat_kolektor']);
        $this->assertStringContainsString('gagal dijalankan kolektor', $hasil['penjelasan']);
    }

    public function test_dashboard_menyegarkan_data_tersimpan_secara_berkala(): void
    {
        $this->actingAs(User::factory()->viewer()->create());

        $this->get(route('dashboard'))->assertSee('wire:poll.30s', false)->assertSee('dibaca ulang tiap 30 detik');

        config(['dashboard.segarkan_detik' => 60]);
        $this->get(route('dashboard'))->assertSee('wire:poll.60s', false);

        $opd = $this->opd('SETDA', 'Sekretariat Daerah');
        $this->get(route('dashboard.opd', $opd))->assertSee('wire:poll.60s', false);

        // Penyegaran membaca keadaan terbaru dari database.
        $komponen = Livewire::test('pages::dashboard.index');
        $this->assertSame(0, $komponen->instance()->jumlah['normal']);

        $this->hasil($opd, HasilAdapter::berhasil(5));
        $komponen->call('$refresh');

        $this->assertSame(1, $komponen->instance()->jumlah['normal']);
    }

    public function test_membuka_dashboard_tidak_memicu_ping_atau_snmp(): void
    {
        $opd = $this->limaStatus();

        // Apa pun yang mencoba memeriksa jaringan dari sini akan menggagalkan uji.
        Process::fake();
        $snmp = new KlienSnmpTiruan;
        $this->app->instance(PabrikKlienSnmp::class, $snmp);
        $this->app->bind(AdapterPemeriksa::class, fn () => new class implements AdapterPemeriksa
        {
            public function nama(): string
            {
                return 'terlarang';
            }

            public function simulasi(): bool
            {
                return false;
            }

            public function periksa(SasaranPemeriksaan $sasaran): HasilAdapter
            {
                throw new RuntimeException('Dashboard tidak boleh memicu pemeriksaan.');
            }
        });

        // Semua endpoint sudah jatuh tempo: kolektor akan memeriksanya, dashboard tidak boleh.
        $this->travelTo($this->awal->addMinutes(5));

        $sebelum = [
            HasilPemeriksaan::query()->count(),
            DetakKolektor::query()->count(),
            EndpointMonitoring::query()->orderBy('id')->pluck('berikutnya_pada', 'id')->map->toIso8601String()->all(),
            EndpointMonitoring::query()->whereNotNull('klaim')->count(),
        ];

        $this->actingAs(User::factory()->administrator()->create());

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('dashboard.peta'))->assertOk();
        $this->get(route('dashboard.opd', $opd['tidak_terjangkau']))->assertOk();
        Livewire::test('pages::dashboard.index')->call('$refresh')->set('status', 'normal')->call('$refresh')->assertOk();
        Livewire::test('pages::dashboard.opd', ['opd' => $opd['normal']])->call('$refresh')->assertOk();

        Process::assertNothingRan();
        $this->assertSame([], $snmp->sesi);
        $this->assertSame($sebelum, [
            HasilPemeriksaan::query()->count(),
            DetakKolektor::query()->count(),
            EndpointMonitoring::query()->orderBy('id')->pluck('berikutnya_pada', 'id')->map->toIso8601String()->all(),
            EndpointMonitoring::query()->whereNotNull('klaim')->count(),
        ]);
    }

    public function test_data_peta_berisi_status_tanpa_alamat_perangkat(): void
    {
        $opd = $this->limaStatus();
        $this->actingAs(User::factory()->viewer()->create());

        $jawaban = $this->getJson(route('dashboard.peta'))->assertOk();
        $data = collect($jawaban->json('opd'))->keyBy('kode');

        $this->assertCount(6, $data);
        $this->assertSame(['normal', 'Normal', 'N'], [$data['SETDA']['status'], $data['SETDA']['label'], $data['SETDA']['kode_status']]);
        $this->assertSame(['tidak_terjangkau', 'Endpoint tidak terjangkau', 'T'], [$data['DINKES']['status'], $data['DINKES']['label'], $data['DINKES']['kode_status']]);
        $this->assertSame(['menurun', 'M'], [$data['DISDIK']['status'], $data['DISDIK']['kode_status']]);
        $this->assertSame(['pemeliharaan', 'P'], [$data['DPUPR']['status'], $data['DPUPR']['kode_status']]);
        $this->assertSame(['belum_diketahui', '?', 'OPD belum memiliki switch utama.'], [$data['ARSIP']['status'], $data['ARSIP']['kode_status'], $data['ARSIP']['keterangan']]);
        $this->assertSame([-7.36, 109.9], [$data['SETDA']['lat'], $data['SETDA']['lng']]);
        $this->assertSame('09-10-2026 10:00:00', $data['SETDA']['diperiksa']);
        $this->assertSame('/dashboard/opd/'.$opd['normal']->id, $data['SETDA']['url']);
        $this->assertSame('10:00:00', $jawaban->json('dibuat_pada'));

        // Viewer tidak berhak melihat inventaris: tidak ada IP maupun kode aset di data peta.
        $mentah = (string) $jawaban->getContent();
        $this->assertStringNotContainsString('203.0.113.', $mentah);
        $this->assertStringNotContainsString('SW-SETDA', $mentah);
        $this->assertStringNotContainsStringIgnoringCase('ip_manajemen', $mentah);

        // Halaman memuat wadah peta, legenda, dan konfigurasi tile yang dapat diatur.
        config(['peta.tile.url' => 'https://tile.intra.example.test/{z}/{x}/{y}.png', 'peta.tile.atribusi' => 'Peta dasar: Bappeda']);
        $this->get(route('dashboard'))
            ->assertSee('data-peta-status', false)
            ->assertSee('Peta status')
            ->assertSee('tile.intra.example.test', false)
            ->assertSee('Peta dasar: Bappeda', false);
    }

    public function test_daftar_opd_dapat_disaring_menurut_status(): void
    {
        $this->limaStatus();
        $this->actingAs(User::factory()->viewer()->create());

        Livewire::test('pages::dashboard.index')
            ->set('status', 'tidak_terjangkau')
            ->assertSee('OPD berstatus Endpoint tidak terjangkau')
            ->assertSee('SW-DINKES')
            ->assertDontSee('SW-SETDA')
            ->set('status', 'bukan-status')
            ->assertSee('Semua OPD')
            ->assertSee('SW-SETDA');
    }

    public function test_detail_opd_menampilkan_perangkat_latency_dan_riwayat_gangguan(): void
    {
        $opd = $this->opd('DINKES', 'Dinas Kesehatan', ['port_uplink' => 'Gi0/1', 'ip_manajemen' => '198.51.100.41', 'merek' => 'Merek Uji', 'model' => 'M-24']);

        // Gangguan pertama, lalu pulih.
        $this->tidakTerjangkau($opd);

        foreach ([60, 120] as $detik) {
            $this->travelTo($this->awal->addSeconds($detik));
            $this->hasil($opd, HasilAdapter::berhasil(7.25, 1.5));
        }

        $this->actingAs(User::factory()->viewer()->create());

        $this->get(route('dashboard.opd', $opd))
            ->assertOk()
            ->assertSee('Status Koneksi OPD ke Diskominfo')
            ->assertSee('Dinas Kesehatan')
            ->assertSee('data-status="normal"', false)
            ->assertSee('SW-DINKES')
            ->assertSee('Switch Utama Dinas Kesehatan')
            ->assertSee('Merek Uji M-24')
            ->assertSee('Gi0/1')
            ->assertSee('7.25 ms')
            ->assertSee('Packet loss 1.50 %')
            // Riwayat gangguan: mulai 10.00, selesai 10.02, dua menit.
            ->assertSee('Riwayat gangguan (1)')
            ->assertSee('09-10-2026 10:00:00')
            ->assertSee('09-10-2026 10:02:00')
            ->assertSee('0 jam 2 menit')
            ->assertSee('Pulih')
            ->assertSee('Perubahan status terakhir')
            ->assertSee('Pemeriksaan berhasil berturut-turut mencapai batas')
            // Tanpa SNMP: uptime dan trafik tidak tersedia, bukan nol.
            ->assertSee('Uptime perangkat')
            ->assertSee('Tidak tersedia')
            ->assertSee('SNMP: tidak dicoba')
            ->assertDontSee('0 bps')
            // Viewer tidak melihat IP manajemen maupun tautan ke inventaris.
            ->assertDontSee('198.51.100.41')
            ->assertDontSee('/aset/perangkat/SW-DINKES', false);

        // Teknisi berhak melihat inventaris.
        $this->actingAs(User::factory()->teknisi()->create());
        $this->get(route('dashboard.opd', $opd))->assertSee('198.51.100.41')->assertSee('/aset/perangkat/SW-DINKES', false);
    }

    public function test_detail_opd_menampilkan_uptime_dan_trafik_uplink_dari_snmp(): void
    {
        $opd = $this->opd('SETDA', 'Sekretariat Daerah', ['port_uplink' => 'Gi0/1']);
        $endpoint = $this->endpoint($opd);

        $sampel = function (int $masuk, int $keluar, int $uptime) use ($endpoint): void {
            $klien = KlienSnmpTiruan::switch([
                1 => ['descr' => 'GigabitEthernet0/1', 'nama' => 'Gi0/1', 'mbps' => 1000, 'admin' => 1, 'oper' => 1, 'masuk64' => $masuk, 'keluar64' => $keluar],
            ], uptimeTiktik: $uptime);
            $snmp = (new PemeriksaSnmp($klien))->periksa(new SasaranPemeriksaan($endpoint->id, $endpoint->alamat, 'snmp', 10, versiSnmp: 'v2c', kredensialSnmp: fn () => ['community' => 'x-uji']));

            $this->layanan->terimaHasil($endpoint->refresh(), HasilAdapter::berhasil(4)->denganSnmp($snmp), now(), (string) Str::uuid(), 'uji', false);
        };

        // 3 hari 2 jam 5 menit = 266.700 detik.
        $sampel(1_000_000, 2_000_000, 26_670_000);

        $this->actingAs(User::factory()->viewer()->create());

        $this->get(route('dashboard.opd', $opd))
            ->assertSee('3 hari 2 jam 5 menit')
            ->assertSee('SNMP: Berhasil')
            ->assertSee('Status up/up')
            ->assertSee('1,00 Gbps')
            // Sampel pertama: laju belum dapat dihitung, dan itu dikatakan.
            ->assertSee('Trafik belum tersedia: menunggu sampel kedua.');

        $this->travelTo($this->awal->addSeconds(60));
        $sampel(1_750_000, 3_500_000, 26_676_000);

        $this->get(route('dashboard.opd', $opd))
            ->assertSee('Masuk 100,00 kbps · keluar 200,00 kbps')
            ->assertSee('Trafik uplink')
            ->assertSee('data-test="tabel-trafik"', false)
            ->assertDontSee('Trafik belum tersedia');
    }

    public function test_detail_opd_tanpa_switch_utama(): void
    {
        $opd = Opd::factory()->create(['nama' => 'Dinas Kearsipan']);
        $this->actingAs(User::factory()->viewer()->create());

        $this->get(route('dashboard.opd', $opd))
            ->assertOk()
            ->assertSee('data-status="belum_diketahui"', false)
            ->assertSee('OPD belum memiliki switch utama.')
            ->assertSee('Belum ada gangguan yang tercatat.')
            ->assertSee('Belum ada perubahan status yang tercatat.');

        $this->get('/dashboard/opd/999999')->assertNotFound();
    }

    public function test_data_simulasi_diberi_peringatan_dan_lencana(): void
    {
        $this->actingAs(User::factory()->viewer()->create());

        $nyata = $this->opd('SETDA', 'Sekretariat Daerah');
        $this->hasil($nyata, HasilAdapter::berhasil(5));

        $this->get(route('dashboard'))->assertDontSee('data-test="peringatan-simulasi"', false)->assertDontSee('data-test="lencana-simulasi"', false);

        $simulasi = Opd::factory()->simulasi()->create(['kode' => 'SIM-DINKES', 'nama' => 'Dinas Kesehatan', 'lintang' => -7.36, 'bujur' => 109.91]);
        Perangkat::factory()->switchUtama()->dipantau()->simulasi()->create(['opd_id' => $simulasi->id]);

        $this->get(route('dashboard'))
            ->assertSee('data-test="peringatan-simulasi"', false)
            ->assertSee('Halaman ini memuat data SIMULASI')
            ->assertSee('data-test="lencana-simulasi"', false);

        $this->assertTrue(collect($this->getJson(route('dashboard.peta'))->json('opd'))->firstWhere('kode', 'SIM-DINKES')['simulasi']);
        $this->get(route('dashboard.opd', $simulasi))->assertSee('data-test="lencana-simulasi"', false);
    }
}
