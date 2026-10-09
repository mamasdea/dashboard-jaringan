<?php

namespace Tests\Feature\Monitoring;

use App\Enums\KeluaranPemeriksaan;
use App\Enums\StatusMonitoring;
use App\Models\EndpointMonitoring;
use App\Models\HasilPemeriksaan;
use App\Models\Insiden;
use App\Models\Opd;
use App\Models\Perangkat;
use App\Monitoring\Adapter\AdapterSimulasi;
use App\Monitoring\HasilAdapter;
use App\Monitoring\Kolektor;
use App\Monitoring\Kontrak\AdapterPemeriksa;
use App\Monitoring\LayananStatus;
use App\Monitoring\SasaranPemeriksaan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class KolektorTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $awal;

    protected function setUp(): void
    {
        parent::setUp();

        // Menit ke-0: skenario "berkedip" sedang menjawab.
        $this->awal = CarbonImmutable::parse('2026-10-09 03:00:00', 'UTC');
        $this->travelTo($this->awal);
    }

    private function kolektor(): Kolektor
    {
        return app(Kolektor::class);
    }

    /**
     * Mengganti adapter dengan tiruan yang hasilnya ditentukan pengujian.
     *
     * @param  callable(SasaranPemeriksaan): HasilAdapter  $perilaku
     */
    private function pasangAdapter(callable $perilaku, string $nama = 'tiruan'): object
    {
        $adapter = new class($perilaku, $nama) implements AdapterPemeriksa
        {
            /** @var list<int> */
            public array $dipanggil = [];

            /**
             * @param  callable(SasaranPemeriksaan): HasilAdapter  $perilaku
             */
            public function __construct(private $perilaku, private string $nama) {}

            public function nama(): string
            {
                return $this->nama;
            }

            public function simulasi(): bool
            {
                return false;
            }

            public function periksa(SasaranPemeriksaan $sasaran): HasilAdapter
            {
                $this->dipanggil[] = $sasaran->endpointId;

                return ($this->perilaku)($sasaran);
            }
        };

        $this->app->instance(AdapterPemeriksa::class, $adapter);

        return $adapter;
    }

    public function test_adapter_bawaan_adalah_simulasi_dan_tidak_dikenal_ditolak(): void
    {
        $adapter = app(AdapterPemeriksa::class);

        $this->assertInstanceOf(AdapterSimulasi::class, $adapter);
        $this->assertSame('simulasi', $adapter->nama());
        $this->assertTrue($adapter->simulasi());

        config(['monitoring.adapter' => 'ping-sungguhan']);

        $this->expectException(InvalidArgumentException::class);

        app(AdapterPemeriksa::class);
    }

    public function test_kolektor_memeriksa_endpoint_yang_jatuh_tempo_setiap_interval(): void
    {
        $endpoint = EndpointMonitoring::factory()->create();

        $this->assertSame(['disapu' => 0, 'diperiksa' => 1], $this->kolektor()->jalankanSekali());

        $endpoint->refresh();
        $this->assertNull($endpoint->klaim);
        $this->assertNull($endpoint->sedang_diperiksa_sejak);
        $this->assertTrue($endpoint->berikutnya_pada->equalTo($this->awal->addSeconds(60)));

        $hasil = HasilPemeriksaan::query()->sole();
        $this->assertSame(KeluaranPemeriksaan::Berhasil, $hasil->keluaran);
        $this->assertSame('simulasi', $hasil->sumber);
        $this->assertTrue($hasil->simulasi);
        $this->assertTrue($hasil->diperiksa_pada->equalTo($this->awal));
        $this->assertTrue(Str::isUuid($hasil->id_pemeriksaan));
        $this->assertSame(StatusMonitoring::Normal, $endpoint->status?->status);
        $this->assertTrue((bool) $endpoint->status?->simulasi);

        // Belum 60 detik: tidak diperiksa lagi.
        $this->travelTo($this->awal->addSeconds(59));
        $this->assertSame(0, $this->kolektor()->jalankanSekali()['diperiksa']);

        $this->travelTo($this->awal->addSeconds(60));
        $this->assertSame(1, $this->kolektor()->jalankanSekali()['diperiksa']);
        $this->assertSame(2, HasilPemeriksaan::query()->count());
    }

    public function test_simulator_menghasilkan_setiap_status(): void
    {
        $normal = EndpointMonitoring::factory()->skenario(AdapterSimulasi::NORMAL)->create();
        $menurun = EndpointMonitoring::factory()->skenario(AdapterSimulasi::MENURUN)->create();
        $mati = EndpointMonitoring::factory()->skenario(AdapterSimulasi::TIDAK_TERJANGKAU)->create();
        $galat = EndpointMonitoring::factory()->skenario(AdapterSimulasi::GALAT_KOLEKTOR)->create();
        $asing = EndpointMonitoring::factory()->skenario('entah')->create();

        foreach ([0, 60, 120] as $detik) {
            $this->travelTo($this->awal->addSeconds($detik));
            $this->assertSame(5, $this->kolektor()->jalankanSekali()['diperiksa']);
        }

        $this->assertSame(StatusMonitoring::Normal, $normal->status()->sole()->statusTampil());
        $this->assertSame(StatusMonitoring::KoneksiMenurun, $menurun->status()->sole()->statusTampil());
        $this->assertSame(StatusMonitoring::TidakTerjangkau, $mati->status()->sole()->statusTampil());
        $this->assertSame(1, Insiden::query()->where('endpoint_id', $mati->id)->whereNull('selesai_pada')->where('simulasi', true)->count());

        // Kolektor yang gagal tidak membuat endpoint tampak mati: statusnya tidak diketahui.
        $statusGalat = $galat->status()->sole();
        $this->assertSame(StatusMonitoring::BelumDiketahui, $statusGalat->statusTampil());
        $this->assertSame(3, $statusGalat->galat_kolektor_berturut);
        $this->assertSame(0, $statusGalat->gagal_berturut);
        $this->assertSame(
            ['galat_adapter'],
            HasilPemeriksaan::query()->where('endpoint_id', $galat->id)->distinct()->pluck('kode_galat')->all(),
        );
        $this->assertSame(
            ['skenario_tidak_dikenal'],
            HasilPemeriksaan::query()->where('endpoint_id', $asing->id)->distinct()->pluck('kode_galat')->all(),
        );
        $this->assertSame(0, Insiden::query()->whereIn('endpoint_id', [$galat->id, $asing->id])->count());
        $this->assertSame(15, HasilPemeriksaan::query()->where('simulasi', true)->count());
    }

    public function test_skenario_berkedip_membuka_dan_menutup_insiden(): void
    {
        $endpoint = EndpointMonitoring::factory()->skenario(AdapterSimulasi::BERKEDIP)->create();

        // Menit 0-2 menjawab, 3-4 tidak, berulang. Diperiksa tiap 20 detik agar batas 3 gagal tercapai.
        $endpoint->update(['interval_detik' => 20]);

        for ($detik = 0; $detik <= 420; $detik += 20) {
            $this->travelTo($this->awal->addSeconds($detik));
            $this->kolektor()->jalankanSekali();
        }

        $insiden = Insiden::query()->where('endpoint_id', $endpoint->id)->sole();

        $this->assertSame(Insiden::PULIH, $insiden->ditutup_karena);
        // Gagal mulai menit 3 (180 s); kegagalan ketiga pada 220 s. Pulih pada keberhasilan kedua setelah menit 5.
        $this->assertTrue($insiden->mulai_pada->equalTo($this->awal->addSeconds(220)));
        $this->assertTrue($insiden->selesai_pada->equalTo($this->awal->addSeconds(320)));
        $this->assertSame(StatusMonitoring::Normal, $endpoint->status()->sole()->status);
    }

    public function test_endpoint_nonaktif_dan_dalam_pemeliharaan_tidak_diperiksa(): void
    {
        $aktif = EndpointMonitoring::factory()->create();
        EndpointMonitoring::factory()->create(['aktif' => false]);
        $pemeliharaan = EndpointMonitoring::factory()->create();
        app(LayananStatus::class)->mulaiPemeliharaan($pemeliharaan);

        $adapter = $this->pasangAdapter(fn () => HasilAdapter::berhasil(5));

        $this->assertSame(1, $this->kolektor()->jalankanSekali()['diperiksa']);
        $this->assertSame([$aktif->id], $adapter->dipanggil);
    }

    public function test_pemeriksaan_endpoint_yang_sama_tidak_bertumpuk(): void
    {
        $endpoint = EndpointMonitoring::factory()->create();
        EndpointMonitoring::factory()->create();
        $kolektor = $this->kolektor();

        // Kolektor A mengklaim keduanya dan belum selesai memeriksa.
        $klaimA = (string) Str::uuid();
        $milikA = $kolektor->klaim($klaimA);
        $this->assertCount(2, $milikA);

        // Kolektor B (atau putaran berikutnya) tidak mendapat apa pun.
        $this->assertSame([], $kolektor->klaim((string) Str::uuid()));
        $this->travelTo($this->awal->addSeconds(90));
        $this->assertSame(0, $kolektor->jalankanSekali()['diperiksa']);
        $this->assertSame(0, HasilPemeriksaan::query()->count());

        // A selesai: klaim dilepas dan jadwal berikutnya ditetapkan.
        foreach ($milikA as $diklaim) {
            $kolektor->periksa($diklaim, $klaimA);
        }

        $this->assertSame(2, HasilPemeriksaan::query()->count());
        $this->assertNull($endpoint->refresh()->klaim);
        $this->assertSame([], $kolektor->klaim((string) Str::uuid()), 'Belum jatuh tempo lagi.');

        $this->travelTo($this->awal->addSeconds(150));
        $this->assertCount(2, $kolektor->klaim((string) Str::uuid()));
    }

    public function test_klaim_kolektor_yang_mati_dapat_diambil_alih(): void
    {
        $endpoint = EndpointMonitoring::factory()->create();
        $kolektor = $this->kolektor();

        $klaimMati = (string) Str::uuid();
        $kolektor->klaim($klaimMati);

        // Batas klaim 120 detik.
        $this->travelTo($this->awal->addSeconds(120));
        $this->assertSame([], $kolektor->klaim((string) Str::uuid()));

        $this->travelTo($this->awal->addSeconds(121));
        $klaimBaru = (string) Str::uuid();
        $this->assertCount(1, $kolektor->klaim($klaimBaru));
        $this->assertSame($klaimBaru, $endpoint->refresh()->klaim);

        // Kolektor lama yang ternyata masih hidup tidak boleh melepas klaim milik yang baru.
        $kolektor->periksa($endpoint, $klaimMati);
        $this->assertSame($klaimBaru, $endpoint->refresh()->klaim);

        $kolektor->periksa($endpoint, $klaimBaru);
        $this->assertNull($endpoint->refresh()->klaim);
    }

    public function test_simulator_dapat_diganti_adapter_lain_tanpa_mengubah_kolektor(): void
    {
        $opd = Opd::factory()->create();
        $perangkat = Perangkat::factory()->dipantau()->create(['opd_id' => $opd->id, 'ip_manajemen' => '198.51.100.9']);
        $endpoint = EndpointMonitoring::query()->where('perangkat_id', $perangkat->id)->sole();

        $adapter = $this->pasangAdapter(function (SasaranPemeriksaan $sasaran): HasilAdapter {
            return $sasaran->alamat === '198.51.100.9' ? HasilAdapter::berhasil(12.5, 1) : HasilAdapter::gagalPerangkat();
        }, 'icmp-uji');

        $this->kolektor()->jalankanSekali();

        $hasil = HasilPemeriksaan::query()->sole();
        $this->assertSame([$endpoint->id], $adapter->dipanggil);
        $this->assertSame('icmp-uji', $hasil->sumber);
        $this->assertFalse($hasil->simulasi);
        $this->assertSame('12.50', $hasil->latency_ms);
        $this->assertSame('1.00', $hasil->packet_loss_persen);
        $this->assertSame(StatusMonitoring::Normal, $endpoint->status()->sole()->status);
        $this->assertFalse($endpoint->status()->sole()->simulasi);
    }

    public function test_galat_dan_keterlambatan_adapter_dicatat_sebagai_galat_kolektor(): void
    {
        $endpoint = EndpointMonitoring::factory()->create();

        $this->pasangAdapter(fn () => throw new RuntimeException('ping: perintah tidak ditemukan'));
        $this->kolektor()->jalankanSekali();

        $galat = HasilPemeriksaan::query()->sole();
        $this->assertSame(KeluaranPemeriksaan::GalatKolektor, $galat->keluaran);
        $this->assertSame('galat_adapter', $galat->kode_galat);
        $this->assertStringContainsString('ping: perintah tidak ditemukan', (string) $galat->pesan);
        // Klaim tetap dilepas dan pemeriksaan berikutnya dijadwalkan.
        $this->assertNull($endpoint->refresh()->klaim);
        $this->assertTrue($endpoint->berikutnya_pada->equalTo($this->awal->addSeconds(60)));

        // Adapter yang melewati batas waktu: hasilnya tidak dipercaya, meski berkata "gagal".
        config(['monitoring.batas_waktu_adapter_detik' => 0.01]);
        $this->pasangAdapter(function (): HasilAdapter {
            usleep(30_000);

            return HasilAdapter::gagalPerangkat();
        });

        $this->travelTo($this->awal->addSeconds(60));
        $this->kolektor()->jalankanSekali();

        $lambat = HasilPemeriksaan::query()->latest('id')->firstOrFail();
        $this->assertSame(KeluaranPemeriksaan::GalatKolektor, $lambat->keluaran);
        $this->assertSame('batas_waktu_kolektor', $lambat->kode_galat);

        $status = $endpoint->status()->sole();
        $this->assertSame(StatusMonitoring::BelumDiketahui, $status->status);
        $this->assertSame(0, $status->gagal_berturut);
        $this->assertSame(2, $status->galat_kolektor_berturut);
    }

    public function test_kolektor_menyapu_status_kedaluwarsa(): void
    {
        $endpoint = EndpointMonitoring::factory()->create();
        $this->kolektor()->jalankanSekali();

        // Endpoint dinonaktifkan; hasil lamanya menjadi kedaluwarsa.
        $endpoint->update(['aktif' => false]);
        $this->travelTo($this->awal->addSeconds(600));

        $this->assertSame(['disapu' => 1, 'diperiksa' => 0], $this->kolektor()->jalankanSekali());
        $this->assertSame(StatusMonitoring::BelumDiketahui, $endpoint->status()->sole()->status);
    }

    public function test_perintah_kolektor_dan_status(): void
    {
        EndpointMonitoring::factory()->skenario(AdapterSimulasi::TIDAK_TERJANGKAU)->create(['nama' => 'SW-UJI — Switch uji', 'simulasi' => true]);

        $this->artisan('monitoring:status')
            ->expectsOutputToContain('Belum diketahui')
            ->assertSuccessful();

        foreach ([0, 60, 120] as $detik) {
            $this->travelTo($this->awal->addSeconds($detik));

            $this->artisan('monitoring:kolektor', ['--sekali' => true])
                ->expectsOutputToContain('SIMULASI: tidak ada paket yang dikirim ke jaringan')
                ->expectsOutputToContain('1 endpoint diperiksa')
                ->assertSuccessful();
        }

        $this->artisan('monitoring:status')
            ->expectsOutputToContain('Endpoint tidak terjangkau')
            ->expectsOutputToContain('Insiden terbuka: 1')
            ->expectsOutputToContain('bukan akses internet OPD')
            ->assertSuccessful();
    }
}
