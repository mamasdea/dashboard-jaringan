<?php

namespace Tests\Feature\Tiket;

use App\Enums\JenisCatatanTiket;
use App\Enums\StatusMonitoring;
use App\Enums\StatusTiket;
use App\Models\EndpointMonitoring;
use App\Models\Insiden;
use App\Models\Perangkat;
use App\Models\Tiket;
use App\Models\User;
use App\Monitoring\HasilAdapter;
use App\Monitoring\LayananStatus;
use App\Tiket\LayananTiket;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Insiden monitoring membuat tiket, paling banyak satu yang terbuka per
 * endpoint, dan pemulihan monitoring tidak menutup pekerjaan.
 */
class TiketOtomatisTest extends TestCase
{
    use RefreshDatabase;

    private LayananStatus $monitoring;

    private EndpointMonitoring $endpoint;

    private CarbonImmutable $awal;

    private int $detik = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->awal = CarbonImmutable::parse('2026-10-09 03:00:00', 'UTC');
        $this->travelTo($this->awal);

        $this->monitoring = app(LayananStatus::class);

        $perangkat = Perangkat::factory()->switchUtama()->dipantau()->create(['ip_manajemen' => '198.51.100.20']);
        $this->endpoint = EndpointMonitoring::query()->where('perangkat_id', $perangkat->id)->firstOrFail();
    }

    /**
     * Mengirim sejumlah hasil berturut-turut, satu per menit.
     */
    private function kirim(HasilAdapter $hasil, int $kali = 1): void
    {
        for ($i = 0; $i < $kali; $i++) {
            $this->detik += 60;
            $pada = $this->awal->addSeconds($this->detik);
            $this->travelTo($pada);

            $this->monitoring->terimaHasil($this->endpoint->refresh(), $hasil, $pada, (string) Str::uuid(), 'uji', false);
        }
    }

    private function gagal(int $kali = 3): void
    {
        $this->kirim(HasilAdapter::gagalPerangkat(), $kali);
    }

    private function pulih(): void
    {
        $this->kirim(HasilAdapter::berhasil(10), 2);
    }

    public function test_insiden_membuat_satu_tiket_otomatis(): void
    {
        $this->gagal(2);
        $this->assertSame(0, Tiket::query()->count(), 'Dua kegagalan belum menjadi insiden.');

        $this->gagal(1);

        $tiket = Tiket::query()->sole();
        $insiden = Insiden::query()->sole();

        $this->assertSame(Tiket::OTOMATIS, $tiket->sumber);
        $this->assertSame($insiden->id, $tiket->insiden_id);
        $this->assertSame($this->endpoint->id, $tiket->endpoint_id);
        $this->assertSame($this->endpoint->perangkat_id, $tiket->perangkat_id);
        $this->assertSame($this->endpoint->perangkat->opd_id, $tiket->opd_id);
        $this->assertTrue($insiden->mulai_pada->equalTo($tiket->waktu_mulai));
        $this->assertSame(StatusTiket::Baru, $tiket->status);
        $this->assertNull($tiket->teknisi_id);
        $this->assertMatchesRegularExpression('/^TKT-2026-\d{5}$/', $tiket->nomor);
    }

    public function test_kegagalan_lanjutan_tidak_membuat_tiket_kedua(): void
    {
        $this->gagal(10);

        $this->assertSame(1, Tiket::query()->count());
        $this->assertSame(1, Insiden::query()->count());
    }

    /**
     * Ping yang gagal hanya berarti endpoint tidak menjawab. Tiket otomatis
     * tidak boleh menebak penyebab ataupun menunjuk kabel.
     */
    public function test_tiket_otomatis_tidak_menyimpulkan_penyebab(): void
    {
        $this->gagal();

        $tiket = Tiket::query()->sole();

        $this->assertNull($tiket->dugaan_penyebab);
        $this->assertNull($tiket->penyebab_terverifikasi);
        $this->assertNull($tiket->segmen_kabel_id);
        $this->assertNull($tiket->titik_jaringan_id);
        $this->assertNull($tiket->tiang_id);
        $this->assertStringNotContainsStringIgnoringCase('putus', $tiket->judul);

        $catatan = $tiket->catatan()->sole();
        $this->assertSame(JenisCatatanTiket::Sistem, $catatan->jenis);
        $this->assertStringContainsString('Penyebab belum diketahui', $catatan->isi);
    }

    public function test_pemulihan_monitoring_tidak_menutup_tiket(): void
    {
        $this->gagal();
        $this->pulih();

        $this->assertSame(StatusMonitoring::Normal, $this->endpoint->status()->firstOrFail()->status);

        $tiket = Tiket::query()->sole();
        $insiden = Insiden::query()->sole();

        $this->assertNotNull($insiden->selesai_pada);
        $this->assertSame(StatusTiket::Baru, $tiket->status, 'Status pekerjaan tidak boleh berubah karena monitoring.');
        $this->assertNull($tiket->waktu_pemulihan, 'Waktu pemulihan hanya diisi orang yang menangani.');
        $this->assertTrue($insiden->selesai_pada->equalTo($tiket->monitoring_pulih_pada));
        $this->assertTrue($tiket->catatan()->where('isi', 'like', '%terjangkau kembali%')->exists());
    }

    public function test_tiket_yang_sedang_dikerjakan_tetap_dikerjakan_setelah_pulih(): void
    {
        $this->gagal();

        $tiket = Tiket::query()->sole();
        $layanan = app(LayananTiket::class);
        $layanan->tugaskan($tiket, User::factory()->teknisi()->create(), null);
        $layanan->ubahStatus($tiket, StatusTiket::Dikerjakan, null);

        $this->pulih();

        $this->assertSame(StatusTiket::Dikerjakan, $tiket->refresh()->status);
    }

    public function test_gangguan_berulang_selama_tiket_terbuka_tidak_membuat_tiket_baru(): void
    {
        $this->gagal();
        $this->pulih();
        $this->gagal();

        $this->assertSame(2, Insiden::query()->count());

        $tiket = Tiket::query()->sole();

        $this->assertSame(Insiden::query()->whereNull('selesai_pada')->sole()->id, $tiket->insiden_id);
        $this->assertNull($tiket->monitoring_pulih_pada, 'Endpoint sedang tidak terjangkau lagi.');
        $this->assertTrue($tiket->catatan()->where('isi', 'like', '%kembali mencatat endpoint tidak terjangkau%')->exists());
    }

    public function test_gangguan_baru_setelah_tiket_selesai_membuat_tiket_baru(): void
    {
        $this->gagal();
        $this->pulih();

        app(LayananTiket::class)->ubahStatus(Tiket::query()->sole(), StatusTiket::Selesai, null, now());

        $this->gagal();

        $this->assertSame(2, Tiket::query()->count());
        $this->assertSame(1, Tiket::query()->terbuka()->count());
    }

    public function test_tiket_manual_yang_terbuka_mencegah_tiket_otomatis(): void
    {
        $manual = app(LayananTiket::class)->buat([
            'judul' => 'Laporan telepon dari OPD',
            'perangkat_id' => $this->endpoint->perangkat_id,
            'waktu_mulai' => now(),
        ], null);

        $this->assertSame($this->endpoint->id, $manual->endpoint_id);
        $this->assertNull($manual->insiden_id);

        $this->gagal();

        $tiket = Tiket::query()->sole();

        $this->assertSame(Tiket::MANUAL, $tiket->sumber);
        $this->assertSame(Insiden::query()->sole()->id, $tiket->insiden_id);
    }

    public function test_tiket_manual_saat_gangguan_berlangsung_dikaitkan_dengan_insidennya(): void
    {
        config(['tiket.otomatis' => false]);

        $this->gagal();
        $this->assertSame(0, Tiket::query()->count());

        $tiket = app(LayananTiket::class)->buat([
            'judul' => 'Dibuat operator',
            'perangkat_id' => $this->endpoint->perangkat_id,
            'waktu_mulai' => now(),
        ], null);

        $this->assertSame(Insiden::query()->sole()->id, $tiket->insiden_id);
    }

    /**
     * Lapisan terakhir: database sendiri menolak tiket otomatis ganda.
     */
    public function test_database_menolak_dua_tiket_otomatis_terbuka_untuk_satu_endpoint(): void
    {
        $this->gagal();

        $this->expectException(UniqueConstraintViolationException::class);

        Tiket::create([
            'judul' => 'Duplikat',
            'endpoint_id' => $this->endpoint->id,
            'sumber' => Tiket::OTOMATIS,
            'waktu_mulai' => now(),
        ]);
    }

    public function test_tiket_dari_insiden_simulasi_berlabel_simulasi(): void
    {
        $this->monitoring->terimaHasil($this->endpoint, HasilAdapter::gagalPerangkat(), $this->awal->addSeconds(60), (string) Str::uuid(), 'simulasi', true);
        $this->monitoring->terimaHasil($this->endpoint, HasilAdapter::gagalPerangkat(), $this->awal->addSeconds(120), (string) Str::uuid(), 'simulasi', true);
        $this->monitoring->terimaHasil($this->endpoint, HasilAdapter::gagalPerangkat(), $this->awal->addSeconds(180), (string) Str::uuid(), 'simulasi', true);

        $tiket = Tiket::query()->sole();

        $this->assertTrue($tiket->simulasi);
        $this->assertStringStartsWith('[SIMULASI]', $tiket->judul);
    }

    /**
     * Kegagalan membuat tiket tidak boleh membatalkan penilaian status.
     */
    public function test_kegagalan_tiket_tidak_menggagalkan_monitoring(): void
    {
        $this->mock(LayananTiket::class, fn ($mock) => $mock->shouldReceive('bukaDariInsiden')->andThrow(new \RuntimeException('uji')));

        $this->gagal();

        $this->assertSame(StatusMonitoring::TidakTerjangkau, $this->endpoint->status()->firstOrFail()->status);
        $this->assertSame(1, Insiden::query()->count());
        $this->assertSame(0, Tiket::query()->count());
    }

    public function test_dashboard_menampilkan_nomor_tiket_pada_gangguan_aktif(): void
    {
        $this->gagal();

        $nomor = Tiket::query()->sole()->nomor;

        $this->actingAs(User::factory()->viewer()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($nomor)
            ->assertDontSee(route('tiket.rinci', Tiket::query()->sole()));

        $this->actingAs(User::factory()->operator()->create())
            ->get(route('dashboard'))
            ->assertSee(route('tiket.rinci', Tiket::query()->sole()));
    }
}
