<?php

namespace Tests\Feature\Laporan;

use App\Enums\AksiAktivitas;
use App\Enums\StatusMonitoring;
use App\Laporan\DaftarLaporan;
use App\Laporan\DokumenLaporan;
use App\Laporan\Ekspor;
use App\Laporan\LaporanGangguan;
use App\Laporan\LaporanInventaris;
use App\Laporan\LaporanKetersediaan;
use App\Laporan\LaporanTiket;
use App\Laporan\PenghitungKetersediaan as P;
use App\Laporan\Periode;
use App\Models\EndpointMonitoring;
use App\Models\Insiden;
use App\Models\LogAktivitas;
use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\SegmenKabel;
use App\Models\Tiang;
use App\Models\Tiket;
use App\Models\User;
use App\Monitoring\HasilAdapter;
use App\Monitoring\LayananStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class LaporanTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $kini;

    protected function setUp(): void
    {
        parent::setUp();

        // 9 Oktober 2026 12.00 WIB.
        $this->kini = CarbonImmutable::parse('2026-10-09 05:00:00', 'UTC');
        $this->travelTo($this->kini);
    }

    private function t(string $jam): CarbonImmutable
    {
        return CarbonImmutable::parse("2026-10-09 {$jam}", 'UTC');
    }

    // ------------------------------------------------------------ perhitungan

    public function test_waktu_dibagi_ke_lima_golongan_dan_jumlahnya_sama_dengan_rentang(): void
    {
        $detik = P::hitung(
            StatusMonitoring::Normal,
            [
                ['pada' => $this->t('01:00'), 'menjadi' => StatusMonitoring::KoneksiMenurun],
                ['pada' => $this->t('01:30'), 'menjadi' => StatusMonitoring::TidakTerjangkau],
                ['pada' => $this->t('02:30'), 'menjadi' => StatusMonitoring::Pemeliharaan],
                ['pada' => $this->t('03:00'), 'menjadi' => StatusMonitoring::BelumDiketahui],
                ['pada' => $this->t('03:15'), 'menjadi' => StatusMonitoring::Normal],
            ],
            $this->t('00:00'),
            $this->t('04:00'),
        );

        $this->assertSame([P::NORMAL => 6300, P::MENURUN => 1800, P::GANGGUAN => 3600, P::PEMELIHARAAN => 1800, P::TANPA_DATA => 900], $detik);
        $this->assertSame(4 * 3600, array_sum($detik));

        // (6300 + 1800) / (6300 + 1800 + 3600): pemeliharaan dan tanpa data di luar pembagi.
        $this->assertEqualsWithDelta(8100 / 11700 * 100, P::persen($detik), 0.0001);
        $this->assertEqualsWithDelta((14400 - 900) / 14400 * 100, P::cakupan($detik), 0.0001);
    }

    public function test_tanpa_riwayat_seluruh_rentang_adalah_tanpa_data_bukan_normal(): void
    {
        $detik = P::hitung(null, [], $this->t('00:00'), $this->t('02:00'));

        $this->assertSame(7200, $detik[P::TANPA_DATA]);
        $this->assertSame(0, $detik[P::NORMAL]);
        $this->assertNull(P::persen($detik), 'Tanpa waktu teramati, ketersediaan tidak dapat dihitung.');
        $this->assertSame(0.0, P::cakupan($detik));
    }

    public function test_status_terakhir_tidak_dianggap_berlaku_setelah_hasilnya_kedaluwarsa(): void
    {
        // Kolektor mati pukul 01.00: tidak ada perubahan status yang tertulis.
        $detik = P::hitung(StatusMonitoring::Normal, [], $this->t('00:00'), $this->t('04:00'), $this->t('01:00'));

        $this->assertSame(3600, $detik[P::NORMAL]);
        $this->assertSame(3 * 3600, $detik[P::TANPA_DATA]);

        // Pemeliharaan adalah keputusan orang, bukan hasil pengamatan: tidak ikut dipotong.
        $detik = P::hitung(StatusMonitoring::Pemeliharaan, [], $this->t('00:00'), $this->t('04:00'), $this->t('01:00'));
        $this->assertSame(4 * 3600, $detik[P::PEMELIHARAAN]);
    }

    public function test_seluruh_rentang_pemeliharaan_tidak_menghasilkan_angka_ketersediaan(): void
    {
        $detik = P::hitung(StatusMonitoring::Pemeliharaan, [], $this->t('00:00'), $this->t('01:00'));

        $this->assertNull(P::persen($detik));
        $this->assertSame(100.0, P::cakupan($detik));
    }

    public function test_transisi_di_luar_rentang_dan_rentang_kosong(): void
    {
        $this->assertSame(0, array_sum(P::hitung(StatusMonitoring::Normal, [], $this->t('02:00'), $this->t('02:00'))));

        $detik = P::hitung(StatusMonitoring::TidakTerjangkau, [['pada' => $this->t('05:00'), 'menjadi' => StatusMonitoring::Normal]], $this->t('00:00'), $this->t('01:00'));
        $this->assertSame(3600, $detik[P::GANGGUAN]);
    }

    public function test_periode(): void
    {
        $periode = Periode::dariIsian('2026-10-01', '2026-10-09');

        // Tengah malam WIB = 17.00 UTC hari sebelumnya; "sampai" ikut penuh.
        $this->assertSame('2026-09-30 17:00:00', $periode->mulai->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-09 17:00:00', $periode->akhir->format('Y-m-d H:i:s'));
        $this->assertTrue($periode->akhirEfektif()->equalTo($this->kini), 'Waktu yang belum terjadi tidak dihitung.');
        $this->assertSame('01-10-2026 s.d. 09-10-2026', $periode->label());

        $bawaan = Periode::dariIsian(null, '');
        $this->assertSame('2026-10-09', $bawaan->sampai);
        $this->assertSame('2026-09-10', $bawaan->dari);

        foreach ([['2026-10-09', '2026-10-01'], ['2025-01-01', '2026-10-01'], ['kemarin', '2026-10-01'], ['2026-02-31', '2026-10-01']] as [$dari, $sampai]) {
            try {
                Periode::dariIsian($dari, $sampai);
                $this->fail("Seharusnya ditolak: {$dari} - {$sampai}");
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ----------------------------------------------------------- dari database

    /**
     * Menjalankan pemeriksaan satu per menit mulai $mulai.
     */
    private function periksa(EndpointMonitoring $endpoint, CarbonImmutable $mulai, int $kali, bool $berhasil): CarbonImmutable
    {
        $layanan = app(LayananStatus::class);

        for ($i = 0; $i < $kali; $i++) {
            $pada = $mulai->addMinutes($i);
            $this->travelTo($pada);
            $layanan->terimaHasil($endpoint->refresh(), $berhasil ? HasilAdapter::berhasil(10) : HasilAdapter::gagalPerangkat(), $pada, (string) Str::uuid(), 'uji', false);
        }

        return $mulai->addMinutes($kali);
    }

    public function test_laporan_ketersediaan_dari_riwayat_status(): void
    {
        $opd = Opd::factory()->create(['nama' => 'Dinas Uji']);
        Opd::factory()->create(['nama' => 'Dinas Tanpa Switch']);
        $perangkat = Perangkat::factory()->switchUtama()->dipantau()->create(['opd_id' => $opd->id, 'ip_manajemen' => '198.51.100.30', 'kode_aset' => 'SW-UJI']);
        $endpoint = EndpointMonitoring::query()->where('perangkat_id', $perangkat->id)->firstOrFail();

        // 00.00-00.59 UTC normal (60 pemeriksaan), lalu gagal 30 menit, lalu normal sampai 02.00.
        $awal = CarbonImmutable::parse('2026-10-09 00:00:00', 'UTC');
        $berikut = $this->periksa($endpoint, $awal, 60, true);
        $berikut = $this->periksa($endpoint, $berikut, 30, false);
        $this->periksa($endpoint, $berikut, 30, true);
        $this->travelTo(CarbonImmutable::parse('2026-10-09 02:00:00', 'UTC'));

        $dokumen = app(LaporanKetersediaan::class)->buat(Periode::dariIsian('2026-10-09', '2026-10-09'));
        $baris = collect($dokumen->bagian[0]->baris)->keyBy(0);

        // Periode mulai 17.00 UTC hari sebelumnya: 7 jam sebelum pemeriksaan pertama tanpa data.
        $uji = $baris['Dinas Uji'];
        $this->assertSame('SW-UJI', $uji[1]);
        $this->assertSame('7,00', $uji[7], 'Sebelum ada pemeriksaan = tanpa data.');
        $this->assertSame('0,00', $uji[6]);
        // Tidak terjangkau sejak gagal ke-3 (01.02) sampai berhasil ke-2 (01.31): 29 menit.
        $this->assertSame(DokumenLaporan::jam(29 * 60), $uji[5]);
        $normalDanMenurun = 2 * 3600 - 29 * 60;
        $this->assertSame(DokumenLaporan::persen($normalDanMenurun / (2 * 3600) * 100), $uji[2]);
        $this->assertSame(DokumenLaporan::persen(2 / 9 * 100), $uji[8]);

        $tanpa = $baris['Dinas Tanpa Switch'];
        $this->assertSame('Belum ada switch utama', $tanpa[1]);
        $this->assertSame('Tidak tersedia', $tanpa[2], 'Tanpa data tidak boleh tampil sebagai 100%.');
        $this->assertSame('9,00', $tanpa[7]);
        $this->assertSame('0,00%', $tanpa[8]);

        $this->assertSame('1', $dokumen->ringkasan['OPD tanpa switch utama yang dipantau']);
    }

    public function test_kolektor_yang_mati_menjadi_tanpa_data_di_laporan(): void
    {
        $perangkat = Perangkat::factory()->switchUtama()->dipantau()->create(['ip_manajemen' => '198.51.100.31']);
        $endpoint = EndpointMonitoring::query()->where('perangkat_id', $perangkat->id)->firstOrFail();

        // Normal 00.00-00.59, lalu kolektor mati. Tidak ada penyapu yang berjalan.
        $this->periksa($endpoint, CarbonImmutable::parse('2026-10-09 00:00:00', 'UTC'), 60, true);
        $this->travelTo(CarbonImmutable::parse('2026-10-09 05:00:00', 'UTC'));

        $baris = app(LaporanKetersediaan::class)->buat(Periode::dariIsian('2026-10-09', '2026-10-09'))->bagian[0]->baris[0];

        // Hasil terakhir 00.59 berlaku 3 menit; sisanya tanpa data, bukan normal.
        $this->assertSame(DokumenLaporan::jam(62 * 60), $baris[3]);
        $this->assertSame(DokumenLaporan::jam(7 * 3600 + (5 * 3600 - 62 * 60)), $baris[7]);
        $this->assertSame('100,00%', $baris[2]);
    }

    /**
     * Kolektor mati lama, lalu penyapu baru berjalan saat kolektor dinyalakan
     * lagi. Perubahan ke "Belum diketahui" harus tercatat pada saat hasil
     * terakhir berhenti berlaku, supaya selang itu tidak terhitung normal.
     */
    public function test_penyapu_yang_terlambat_tidak_membuat_selang_mati_terhitung_normal(): void
    {
        $perangkat = Perangkat::factory()->switchUtama()->dipantau()->create(['ip_manajemen' => '198.51.100.33']);
        $endpoint = EndpointMonitoring::query()->where('perangkat_id', $perangkat->id)->firstOrFail();

        $this->periksa($endpoint, CarbonImmutable::parse('2026-10-09 00:00:00', 'UTC'), 60, true);

        // Tiga jam tanpa kolektor; penyapu baru berjalan pukul 04.00.
        $this->travelTo(CarbonImmutable::parse('2026-10-09 04:00:00', 'UTC'));
        $this->assertSame(1, app(LayananStatus::class)->sapu());

        $kedaluwarsa = DB::table('riwayat_status')->where('endpoint_id', $endpoint->id)->where('alasan', 'kedaluwarsa')->sole();
        $this->assertSame('2026-10-09 01:02:00', CarbonImmutable::parse($kedaluwarsa->pada)->utc()->format('Y-m-d H:i:s'));

        // Kolektor hidup lagi 04.00-04.59.
        $this->periksa($endpoint, CarbonImmutable::parse('2026-10-09 04:00:00', 'UTC'), 60, true);
        $this->travelTo(CarbonImmutable::parse('2026-10-09 05:00:00', 'UTC'));

        $baris = app(LaporanKetersediaan::class)->buat(Periode::dariIsian('2026-10-09', '2026-10-09'))->bagian[0]->baris[0];

        // Normal: 00.00-01.02 dan 04.00-05.00. Selang 01.02-04.00 tanpa data.
        $this->assertSame(DokumenLaporan::jam(62 * 60 + 3600), $baris[3]);
        $this->assertSame(DokumenLaporan::jam(7 * 3600 + (2 * 3600 + 58 * 60)), $baris[7]);
    }

    public function test_laporan_gangguan_memotong_insiden_pada_batas_periode(): void
    {
        $opd = Opd::factory()->create(['nama' => 'Dinas Gangguan']);
        $perangkat = Perangkat::factory()->dipantau()->create(['opd_id' => $opd->id, 'ip_manajemen' => '198.51.100.32', 'kode_aset' => 'SW-G']);
        $endpoint = EndpointMonitoring::query()->where('perangkat_id', $perangkat->id)->firstOrFail();

        // Mulai sehari sebelum periode, selesai 1 jam setelah periode mulai (17.00 UTC).
        Insiden::create(['endpoint_id' => $endpoint->id, 'mulai_pada' => '2026-10-07 10:00:00+00', 'selesai_pada' => '2026-10-08 18:00:00+00', 'ditutup_karena' => 'pulih']);
        // Di luar periode.
        Insiden::create(['endpoint_id' => $endpoint->id, 'mulai_pada' => '2026-10-01 10:00:00+00', 'selesai_pada' => '2026-10-01 11:00:00+00', 'ditutup_karena' => 'pulih']);
        // Masih berlangsung, 2 jam sebelum sekarang.
        Insiden::create(['endpoint_id' => $endpoint->id, 'mulai_pada' => '2026-10-09 03:00:00+00']);

        $dokumen = app(LaporanGangguan::class)->buat(Periode::dariIsian('2026-10-09', '2026-10-09'));

        $this->assertSame('2', $dokumen->ringkasan['Jumlah gangguan']);
        $this->assertSame('1', $dokumen->ringkasan['Masih berlangsung']);
        $this->assertSame('3,00', $dokumen->ringkasan['Total lama gangguan (jam)']);
        $this->assertSame(['Dinas Gangguan', 'SW-G', '2', '3,00', '2,00', '1,50', '1'], $dokumen->bagian[0]->baris[0]);
        $this->assertCount(2, $dokumen->bagian[1]->baris);
        $this->assertSame('Masih berlangsung', $dokumen->bagian[1]->baris[1][3]);
    }

    public function test_laporan_tiket(): void
    {
        $teknisi = User::factory()->teknisi()->create(['name' => 'Teknisi Lap']);
        Tiket::factory()->untuk($teknisi)->create([
            'judul' => 'Selesai', 'waktu_mulai' => '2026-10-09 00:00:00+00', 'waktu_pemulihan' => '2026-10-09 03:00:00+00', 'status' => 'selesai',
            'dugaan_penyebab' => 'Dugaan A', 'penyebab_terverifikasi' => 'Pasti B',
        ]);
        Tiket::factory()->create(['judul' => '=RUMUS()', 'waktu_mulai' => '2026-10-09 01:00:00+00']);
        Tiket::factory()->create(['judul' => 'Di luar periode', 'waktu_mulai' => '2026-09-01 01:00:00+00']);

        $dokumen = app(LaporanTiket::class)->buat(Periode::dariIsian('2026-10-09', '2026-10-09'));

        $this->assertSame('2', $dokumen->ringkasan['Jumlah tiket']);
        $this->assertSame('1', $dokumen->ringkasan['Selesai']);
        $this->assertSame('3,00', $dokumen->ringkasan['Rata-rata lama penanganan tiket selesai (jam)']);

        $selesai = $dokumen->bagian[0]->baris[0];
        $this->assertSame(['Teknisi Lap', '09-10-2026 07:00', '09-10-2026 10:00', '3,00', 'Dugaan A', 'Pasti B'], array_slice($selesai, 7));

        // Teks dari database tidak boleh menjadi rumus di lembar kerja.
        $csv = app(Ekspor::class)->csv($dokumen);
        $this->assertStringContainsString("'=RUMUS()", $csv);
        $this->assertStringStartsWith("\u{FEFF}", $csv);
    }

    public function test_laporan_inventaris_tanpa_data_sensitif(): void
    {
        $opd = Opd::factory()->create(['nama' => 'Dinas Inv', 'lintang' => null, 'bujur' => null]);
        Perangkat::factory()->switchUtama()->create(['opd_id' => $opd->id, 'kode_aset' => 'SW-INV', 'ip_manajemen' => '198.51.100.99', 'nomor_seri' => 'SN-RAHASIA-1', 'port_uplink' => null]);
        Tiang::factory()->create(['kode' => 'TG-LENGKAP']);
        Tiang::factory()->create(['kode' => 'TG-KURANG', 'lintang' => null, 'bujur' => null]);
        SegmenKabel::factory()->belumLengkap()->create(['kode' => 'KBL-KURANG']);

        $dokumen = app(LaporanInventaris::class)->buat();
        $jumlah = collect($dokumen->bagian[0]->baris)->keyBy(0);

        $this->assertNull($dokumen->periode);
        $this->assertSame(['Tiang', '2', '1', '1', '50,00%', '0'], $jumlah['Tiang']);

        $kurang = collect($dokumen->bagian[2]->baris)->keyBy(1);
        $this->assertStringContainsString('koordinat', $kurang['TG-KURANG'][3]);
        $this->assertStringContainsString('port uplink', $kurang['SW-INV'][3]);
        $this->assertArrayNotHasKey('TG-LENGKAP', $kurang->all());
        $this->assertArrayHasKey('KBL-KURANG', $kurang->all());

        $semua = json_encode($dokumen, JSON_THROW_ON_ERROR).app(Ekspor::class)->csv($dokumen);
        $this->assertStringNotContainsString('198.51.100.99', $semua);
        $this->assertStringNotContainsString('SN-RAHASIA-1', $semua);
    }

    // ------------------------------------------------------- halaman dan ekspor

    public function test_viewer_operator_administrator_dapat_membuka_laporan(): void
    {
        foreach (['viewer', 'operator', 'administrator'] as $peran) {
            $this->actingAs(User::factory()->{$peran}()->create());

            foreach (array_keys(DaftarLaporan::JENIS) as $jenis) {
                $this->get(route('laporan.index', ['jenis' => $jenis]))->assertOk()->assertSee('Definisi dan keterbatasan');
            }
        }
    }

    public function test_halaman_ketersediaan_memuat_definisi_tertulis(): void
    {
        Opd::factory()->create(['nama' => 'Dinas Halaman']);

        $this->actingAs(User::factory()->viewer()->create())
            ->get(route('laporan.index'))
            ->assertOk()
            ->assertSee('Dinas Halaman')
            ->assertSee('Ketersediaan = (normal + menurun) ÷ (normal + menurun + gangguan)')
            ->assertSee('Waktu ini tidak dihitung sebagai normal')
            ->assertSee('bukan layanan internet di OPD')
            ->assertSee(route('laporan.ekspor', ['jenis' => 'ketersediaan', 'format' => 'csv']), false);
    }

    public function test_rentang_tidak_sah_tidak_menampilkan_laporan(): void
    {
        Livewire::actingAs(User::factory()->operator()->create())->test('pages::laporan.index')
            ->set('dari', '2026-10-09')->set('sampai', '2026-10-01')
            ->assertSee('data-test="periode-tidak-sah"', false)
            ->assertDontSee('data-test="ekspor-csv"', false)
            ->set('sampai', '2026-10-09')
            ->assertSee('data-test="ekspor-csv"', false);
    }

    public function test_teknisi_dan_tamu_ditolak(): void
    {
        $ekspor = route('laporan.ekspor', ['jenis' => 'tiket', 'format' => 'csv']);

        $this->get(route('laporan.index'))->assertRedirect(route('login'));
        $this->get($ekspor)->assertRedirect(route('login'));

        $this->actingAs(User::factory()->teknisi()->create());

        $this->get(route('laporan.index'))->assertForbidden();
        $this->get($ekspor)->assertForbidden();
        $this->get(route('laporan.ekspor', ['jenis' => 'inventaris', 'format' => 'pdf']))->assertForbidden();
        Livewire::test('pages::laporan.index')->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('laporan.index'));

        $this->assertSame(0, LogAktivitas::query()->where('aksi', AksiAktivitas::LaporanDiekspor)->count());
    }

    public function test_ekspor_csv_dan_pdf(): void
    {
        Process::fake();
        Opd::factory()->create(['nama' => 'Dinas Ekspor']);
        $viewer = User::factory()->viewer()->create();
        $hasil = DB::table('hasil_pemeriksaan')->count();

        foreach (array_keys(DaftarLaporan::JENIS) as $jenis) {
            $csv = $this->actingAs($viewer)->get(route('laporan.ekspor', ['jenis' => $jenis, 'format' => 'csv', 'dari' => '2026-10-01', 'sampai' => '2026-10-09']));
            $csv->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
            $this->assertStringContainsString('attachment; filename="laporan-'.$jenis.'-', (string) $csv->headers->get('Content-Disposition'));
            $this->assertStringContainsString('Catatan', $csv->getContent());

            $pdf = $this->actingAs($viewer)->get(route('laporan.ekspor', ['jenis' => $jenis, 'format' => 'pdf', 'dari' => '2026-10-01', 'sampai' => '2026-10-09']));
            $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF-', (string) $pdf->getContent());
        }

        $csv = $this->get(route('laporan.ekspor', ['jenis' => 'ketersediaan', 'format' => 'csv', 'dari' => '2026-10-01', 'sampai' => '2026-10-09']))->getContent();
        $this->assertStringContainsString('"Dinas Ekspor"', $csv);
        $this->assertStringContainsString('Periode;"01-10-2026 s.d. 09-10-2026"', $csv);
        $this->assertStringContainsString('Tanpa data', $csv);

        $catatan = LogAktivitas::query()->where('aksi', AksiAktivitas::LaporanDiekspor)->where('pelaku_id', $viewer->id)->get();
        $this->assertCount(9, $catatan);
        $this->assertSame('ketersediaan', $catatan->last()->rincian['laporan']);

        // Laporan tidak memicu pemeriksaan.
        Process::assertNothingRan();
        $this->assertSame($hasil, DB::table('hasil_pemeriksaan')->count());
    }

    public function test_ekspor_menolak_jenis_format_dan_rentang_yang_tidak_dikenal(): void
    {
        $this->actingAs(User::factory()->operator()->create());

        $this->get('/laporan/ekspor/rahasia.csv')->assertNotFound();
        $this->get('/laporan/ekspor/tiket.xlsx')->assertNotFound();
        $this->get(route('laporan.ekspor', ['jenis' => 'tiket', 'format' => 'csv', 'dari' => '2026-10-09', 'sampai' => '2026-10-01']))->assertSessionHasErrors('dari');
    }
}
