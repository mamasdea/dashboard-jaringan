<?php

namespace Tests\Feature\Monitoring;

use App\Enums\AlasanStatus;
use App\Enums\KeluaranPemeriksaan;
use App\Enums\StatusMonitoring;
use App\Models\EndpointMonitoring;
use App\Models\HasilPemeriksaan;
use App\Models\Insiden;
use App\Models\RiwayatStatus;
use App\Models\StatusTerkini;
use App\Monitoring\HasilAdapter;
use App\Monitoring\LayananStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * Aturan status: 3 gagal berturut-turut -> tidak terjangkau, 2 berhasil
 * berturut-turut -> pulih, hasil kedaluwarsa -> belum diketahui.
 */
class TransisiStatusTest extends TestCase
{
    use RefreshDatabase;

    private LayananStatus $layanan;

    private EndpointMonitoring $endpoint;

    private CarbonImmutable $awal;

    protected function setUp(): void
    {
        parent::setUp();

        $this->awal = CarbonImmutable::parse('2026-10-09 03:00:00', 'UTC');
        $this->travelTo($this->awal);

        $this->layanan = app(LayananStatus::class);
        $this->endpoint = EndpointMonitoring::factory()->create();
    }

    /**
     * Mengirim hasil pemeriksaan yang dilakukan pada detik ke-$detik sejak awal.
     */
    private function hasil(HasilAdapter $hasil, int $detik, ?string $id = null): HasilPemeriksaan
    {
        $pada = $this->awal->addSeconds($detik);

        if ($pada->greaterThan(now())) {
            $this->travelTo($pada);
        }

        return $this->layanan->terimaHasil($this->endpoint->refresh(), $hasil, $pada, $id ?? (string) Str::uuid(), 'uji', false);
    }

    private function ok(float $latency = 10, float $loss = 0): HasilAdapter
    {
        return HasilAdapter::berhasil($latency, $loss);
    }

    private function gagal(): HasilAdapter
    {
        return HasilAdapter::gagalPerangkat();
    }

    private function statusKini(): StatusTerkini
    {
        return $this->endpoint->status()->firstOrFail();
    }

    /**
     * @return list<array{string, string, string}>
     */
    private function riwayat(): array
    {
        return RiwayatStatus::query()->orderBy('id')->get()
            ->map(fn (RiwayatStatus $r) => [$r->dari->value, $r->menjadi->value, $r->alasan->value])
            ->all();
    }

    public function test_endpoint_baru_berstatus_belum_diketahui(): void
    {
        $status = $this->statusKini();

        $this->assertSame(StatusMonitoring::BelumDiketahui, $status->status);
        $this->assertSame(StatusMonitoring::BelumDiketahui, $status->statusTampil());
        $this->assertSame(60, $this->endpoint->interval_detik);
        $this->assertSame([], $this->riwayat());
    }

    public function test_tidak_terjangkau_setelah_tiga_gagal_berturut_turut(): void
    {
        $this->hasil($this->ok(), 0);
        $this->assertSame(StatusMonitoring::Normal, $this->statusKini()->status);

        $this->hasil($this->gagal(), 60);
        $this->assertSame(StatusMonitoring::KoneksiMenurun, $this->statusKini()->status);
        $this->assertSame(0, Insiden::query()->count());

        $this->hasil($this->gagal(), 120);
        $this->assertSame(StatusMonitoring::KoneksiMenurun, $this->statusKini()->status);
        $this->assertSame(2, $this->statusKini()->gagal_berturut);

        $ketiga = $this->hasil($this->gagal(), 180);
        $status = $this->statusKini();

        $this->assertSame(StatusMonitoring::TidakTerjangkau, $status->status);
        $this->assertSame(StatusMonitoring::TidakTerjangkau, $status->statusTampil());
        $this->assertSame(3, $status->gagal_berturut);
        $this->assertTrue($status->sejak->equalTo($this->awal->addSeconds(180)));

        $insiden = Insiden::query()->sole();
        $this->assertTrue($insiden->terbuka());
        $this->assertTrue($insiden->mulai_pada->equalTo($this->awal->addSeconds(180)));

        $this->assertSame([
            ['belum_diketahui', 'normal', 'hasil_baru'],
            ['normal', 'menurun', 'pemeriksaan_gagal'],
            ['menurun', 'tidak_terjangkau', 'gagal_berturut'],
        ], $this->riwayat());

        $terakhir = RiwayatStatus::query()->latest('id')->firstOrFail();
        $this->assertSame($ketiga->id, $terakhir->hasil_pemeriksaan_id);
        $this->assertSame($insiden->id, $terakhir->insiden_id);

        // Gagal berikutnya tidak membuka insiden kedua maupun menambah riwayat.
        $this->hasil($this->gagal(), 240);
        $this->assertSame(1, Insiden::query()->count());
        $this->assertCount(3, $this->riwayat());
    }

    public function test_keberhasilan_di_tengah_mengulang_hitungan_gagal(): void
    {
        $this->hasil($this->gagal(), 0);
        $this->hasil($this->gagal(), 60);
        $this->hasil($this->ok(), 120);
        $this->hasil($this->gagal(), 180);
        $this->hasil($this->gagal(), 240);

        $this->assertSame(StatusMonitoring::KoneksiMenurun, $this->statusKini()->status);
        $this->assertSame(0, Insiden::query()->count());
    }

    public function test_pulih_setelah_dua_berhasil_berturut_turut(): void
    {
        foreach ([0, 60, 120] as $detik) {
            $this->hasil($this->gagal(), $detik);
        }

        $this->hasil($this->ok(), 180);
        $this->assertSame(StatusMonitoring::TidakTerjangkau, $this->statusKini()->status, 'Satu keberhasilan belum cukup.');
        $this->assertSame(1, $this->statusKini()->berhasil_berturut);

        // Gagal lagi mengulang hitungan pulih.
        $this->hasil($this->gagal(), 240);
        $this->hasil($this->ok(), 300);
        $this->assertSame(StatusMonitoring::TidakTerjangkau, $this->statusKini()->status);
        $this->assertTrue(Insiden::query()->sole()->terbuka());

        $this->hasil($this->ok(), 360);

        $status = $this->statusKini();
        $this->assertSame(StatusMonitoring::Normal, $status->status);
        $this->assertSame(0, $status->gagal_berturut);

        $insiden = Insiden::query()->sole();
        $this->assertFalse($insiden->terbuka());
        $this->assertSame(Insiden::PULIH, $insiden->ditutup_karena);
        $this->assertTrue($insiden->mulai_pada->equalTo($this->awal->addSeconds(120)));
        $this->assertTrue($insiden->selesai_pada->equalTo($this->awal->addSeconds(360)));

        $this->assertSame(['tidak_terjangkau', 'normal', 'pulih'], $this->riwayat()[2]);

        // Gangguan berikutnya adalah insiden baru.
        foreach ([420, 480, 540] as $detik) {
            $this->hasil($this->gagal(), $detik);
        }

        $this->assertSame(2, Insiden::query()->count());
        $this->assertSame(1, Insiden::query()->whereNull('selesai_pada')->count());
    }

    public function test_ambang_latency_dan_packet_loss_dapat_dikonfigurasi(): void
    {
        // Bawaan: 100 ms dan 5%.
        $this->hasil($this->ok(latency: 99, loss: 5), 0);
        $this->assertSame(StatusMonitoring::Normal, $this->statusKini()->status);

        $this->hasil($this->ok(latency: 101), 60);
        $this->assertSame(StatusMonitoring::KoneksiMenurun, $this->statusKini()->status);

        $this->hasil($this->ok(latency: 20, loss: 6), 120);
        $this->assertSame(StatusMonitoring::KoneksiMenurun, $this->statusKini()->status);

        $this->hasil($this->ok(latency: 20, loss: 0), 180);
        $this->assertSame(StatusMonitoring::Normal, $this->statusKini()->status);
        $this->assertSame(['menurun', 'normal', 'kualitas'], $this->riwayat()[2]);
        $this->assertSame(0, Insiden::query()->count());

        // Ambang global.
        config(['monitoring.ambang.latency_ms' => 15]);
        $this->hasil($this->ok(latency: 20), 240);
        $this->assertSame(StatusMonitoring::KoneksiMenurun, $this->statusKini()->status);

        // Ambang per endpoint menimpa ambang global.
        $this->endpoint->update(['ambang_latency_ms' => 500, 'ambang_packet_loss_persen' => 50]);
        $this->hasil($this->ok(latency: 450, loss: 40), 300);
        $this->assertSame(StatusMonitoring::Normal, $this->statusKini()->status);
        $this->assertSame('450.00', $this->statusKini()->latency_ms);
    }

    public function test_batas_gagal_dan_pulih_dapat_dikonfigurasi(): void
    {
        config(['monitoring.gagal_berturut' => 2, 'monitoring.pulih_berturut' => 1]);

        $this->hasil($this->gagal(), 0);
        $this->hasil($this->gagal(), 60);
        $this->assertSame(StatusMonitoring::TidakTerjangkau, $this->statusKini()->status);

        $this->hasil($this->ok(), 120);
        $this->assertSame(StatusMonitoring::Normal, $this->statusKini()->status);
    }

    public function test_galat_kolektor_dibedakan_dari_kegagalan_perangkat(): void
    {
        $this->hasil($this->ok(), 0);

        // Kolektor gagal berkali-kali: bukan bukti endpoint tidak terjangkau.
        foreach ([60, 120] as $detik) {
            $baris = $this->hasil(HasilAdapter::galatKolektor('batas_waktu_kolektor', 'Pemeriksaan melewati batas waktu.'), $detik);
        }

        $status = $this->statusKini();
        $this->assertSame(KeluaranPemeriksaan::GalatKolektor, $baris->keluaran);
        $this->assertSame('batas_waktu_kolektor', $baris->kode_galat);
        $this->assertTrue($baris->diterapkan);
        $this->assertSame(StatusMonitoring::Normal, $status->status);
        $this->assertSame(0, $status->gagal_berturut);
        $this->assertSame(2, $status->galat_kolektor_berturut);
        $this->assertSame(0, Insiden::query()->count());
        // Dasar kedaluwarsa tetap hasil valid terakhir.
        $this->assertTrue($status->hasil_valid_terakhir_pada->equalTo($this->awal));
        $this->assertTrue($status->diperiksa_terakhir_pada->equalTo($this->awal->addSeconds(120)));

        // Galat kolektor di antara kegagalan perangkat tidak mengulang hitungan gagal.
        $this->hasil($this->gagal(), 130);
        $this->hasil($this->gagal(), 140);
        $this->hasil(HasilAdapter::galatKolektor('galat_adapter'), 150);
        $this->assertSame(StatusMonitoring::KoneksiMenurun, $this->statusKini()->status);
        $this->hasil($this->gagal(), 160);

        $this->assertSame(StatusMonitoring::TidakTerjangkau, $this->statusKini()->status);
        $this->assertSame(0, $this->statusKini()->galat_kolektor_berturut);
    }

    public function test_hasil_kedaluwarsa_ditampilkan_sebagai_belum_diketahui(): void
    {
        $this->hasil($this->ok(), 0);
        $status = $this->statusKini();

        // Batas: 3 x 60 detik.
        $this->assertSame(180, $this->endpoint->kedaluwarsaDetik());
        $this->assertSame(StatusMonitoring::Normal, $status->statusTampil($this->awal->addSeconds(180)));
        // Tanpa penyapu pun, status lama tidak ditampilkan sebagai masih berlaku.
        $this->assertSame(StatusMonitoring::BelumDiketahui, $status->statusTampil($this->awal->addSeconds(181)));
        $this->assertSame(StatusMonitoring::Normal, $status->status);

        // Galat kolektor tidak memperpanjang umur data.
        $this->hasil(HasilAdapter::galatKolektor('galat_adapter'), 170);
        $this->travelTo($this->awal->addSeconds(181));
        $this->assertSame(StatusMonitoring::BelumDiketahui, $this->statusKini()->statusTampil());

        // Penyapu mencatat perubahannya.
        $this->assertSame(1, $this->layanan->sapu());
        $this->assertSame(0, $this->layanan->sapu());

        $status = $this->statusKini();
        $this->assertSame(StatusMonitoring::BelumDiketahui, $status->status);
        $this->assertSame(['normal', 'belum_diketahui', 'kedaluwarsa'], $this->riwayat()[1]);

        // Hasil baru menghidupkan status kembali.
        $this->hasil($this->ok(), 200);
        $this->assertSame(StatusMonitoring::Normal, $this->statusKini()->statusTampil());
    }

    public function test_setelah_jeda_panjang_hitungan_gagal_dimulai_dari_awal(): void
    {
        $this->hasil($this->ok(), 0);
        $this->hasil($this->gagal(), 60);
        $this->hasil($this->gagal(), 120);

        // Kolektor mati 10 menit, lalu satu pemeriksaan gagal. Dua kegagalan
        // lama tidak boleh dijumlahkan dengan yang baru.
        $this->hasil($this->gagal(), 720);

        $status = $this->statusKini();
        $this->assertSame(StatusMonitoring::KoneksiMenurun, $status->status);
        $this->assertSame(1, $status->gagal_berturut);
        $this->assertSame(0, Insiden::query()->count());

        $this->assertSame([
            ['belum_diketahui', 'normal', 'hasil_baru'],
            ['normal', 'menurun', 'pemeriksaan_gagal'],
            ['menurun', 'belum_diketahui', 'kedaluwarsa'],
            ['belum_diketahui', 'menurun', 'pemeriksaan_gagal'],
        ], $this->riwayat());

        // Dicatat pada saat data menjadi kedaluwarsa, bukan saat hasil baru tiba.
        $kedaluwarsa = RiwayatStatus::query()->where('alasan', AlasanStatus::Kedaluwarsa)->sole();
        $this->assertTrue($kedaluwarsa->pada->equalTo($this->awal->addSeconds(120 + 180)));
    }

    public function test_insiden_tetap_terbuka_selama_status_belum_diketahui(): void
    {
        foreach ([0, 60, 120] as $detik) {
            $this->hasil($this->gagal(), $detik);
        }

        $this->travelTo($this->awal->addSeconds(600));
        $this->layanan->sapu();

        $this->assertSame(StatusMonitoring::BelumDiketahui, $this->statusKini()->status);
        $this->assertTrue(Insiden::query()->sole()->terbuka(), 'Belum ada bukti bahwa endpoint pulih.');

        // Masih tidak menjawab: insiden yang sama berlanjut.
        foreach ([660, 720, 780] as $detik) {
            $this->hasil($this->gagal(), $detik);
        }

        $this->assertSame(StatusMonitoring::TidakTerjangkau, $this->statusKini()->status);
        $this->assertSame(1, Insiden::query()->count());

        // Pulih: insiden ditutup.
        $this->hasil($this->ok(), 840);
        $this->hasil($this->ok(), 900);
        $this->assertFalse(Insiden::query()->sole()->terbuka());
    }

    public function test_pemeliharaan_menahan_status_dan_insiden(): void
    {
        foreach ([0, 60, 120] as $detik) {
            $this->hasil($this->gagal(), $detik);
        }

        $this->travelTo($this->awal->addSeconds(130));
        $this->layanan->mulaiPemeliharaan($this->endpoint->refresh(), catatan: 'Ganti switch');

        $status = $this->statusKini();
        $this->assertSame(StatusMonitoring::Pemeliharaan, $status->status);
        $this->assertSame(StatusMonitoring::Pemeliharaan, $status->statusTampil());
        $this->assertSame('Ganti switch', $this->endpoint->refresh()->pemeliharaan_catatan);

        $insiden = Insiden::query()->sole();
        $this->assertSame(Insiden::PEMELIHARAAN, $insiden->ditutup_karena);
        $this->assertTrue($insiden->selesai_pada->equalTo($this->awal->addSeconds(130)));

        // Hasil selama pemeliharaan disimpan tetapi tidak mengubah apa pun.
        foreach ([180, 240, 300, 360] as $detik) {
            $baris = $this->hasil($this->gagal(), $detik);
        }

        $this->assertFalse($baris->diterapkan);
        $this->assertSame(HasilPemeriksaan::PEMELIHARAAN, $baris->alasan_tidak_diterapkan);
        $this->assertSame(StatusMonitoring::Pemeliharaan, $this->statusKini()->status);
        $this->assertSame(0, $this->statusKini()->gagal_berturut);
        $this->assertSame(1, Insiden::query()->count());
        $this->assertSame(7, HasilPemeriksaan::query()->count());

        // Pemeliharaan tidak pernah kedaluwarsa menjadi "belum diketahui".
        $this->travelTo($this->awal->addSeconds(3600));
        $this->assertSame(0, $this->layanan->sapu());
        $this->assertSame(StatusMonitoring::Pemeliharaan, $this->statusKini()->statusTampil());

        $this->layanan->selesaiPemeliharaan($this->endpoint->refresh());

        $status = $this->statusKini();
        $this->assertSame(StatusMonitoring::BelumDiketahui, $status->status);
        $this->assertSame(StatusMonitoring::BelumDiketahui, $status->statusTampil());
        $this->assertFalse($this->endpoint->refresh()->pemeliharaan);
        // Diperiksa segera setelah pemeliharaan selesai.
        $this->assertTrue($this->endpoint->berikutnya_pada->equalTo($this->awal->addSeconds(3600)));

        $this->assertSame([
            ['tidak_terjangkau', 'pemeliharaan', 'pemeliharaan_mulai'],
            ['pemeliharaan', 'belum_diketahui', 'pemeliharaan_selesai'],
        ], array_slice($this->riwayat(), 2));

        // Setelah pemeliharaan, penilaian dimulai dari awal.
        $this->hasil($this->ok(), 3660);
        $this->assertSame(StatusMonitoring::Normal, $this->statusKini()->status);
    }

    public function test_pemeliharaan_berjangka_berakhir_sendiri(): void
    {
        $this->hasil($this->ok(), 0);
        $this->layanan->mulaiPemeliharaan($this->endpoint->refresh(), sampai: $this->awal->addSeconds(600));

        $this->travelTo($this->awal->addSeconds(599));
        $this->assertSame(0, $this->layanan->sapu());
        $this->assertSame(StatusMonitoring::Pemeliharaan, $this->statusKini()->statusTampil());

        // Saat dibaca setelah masanya habis, sebelum disapu sekalipun.
        $this->travelTo($this->awal->addSeconds(601));
        $this->assertSame(StatusMonitoring::BelumDiketahui, $this->statusKini()->statusTampil());

        $this->assertSame(1, $this->layanan->sapu());
        $this->assertFalse($this->endpoint->refresh()->pemeliharaan);
        $this->assertSame(StatusMonitoring::BelumDiketahui, $this->statusKini()->status);
    }

    public function test_hasil_yang_tiba_setelah_masa_pemeliharaan_habis_langsung_dinilai(): void
    {
        $this->layanan->mulaiPemeliharaan($this->endpoint, sampai: $this->awal->addSeconds(100));

        // Penyapu belum berjalan, tetapi hasil ini diperiksa dan diterima setelah masa pemeliharaan.
        $baris = $this->hasil($this->ok(), 160);

        $this->assertTrue($baris->diterapkan);
        $this->assertFalse($this->endpoint->refresh()->pemeliharaan);
        $this->assertSame(StatusMonitoring::Normal, $this->statusKini()->status);
        $this->assertSame([
            ['belum_diketahui', 'pemeliharaan', 'pemeliharaan_mulai'],
            ['pemeliharaan', 'belum_diketahui', 'pemeliharaan_selesai'],
            ['belum_diketahui', 'normal', 'hasil_baru'],
        ], $this->riwayat());
    }

    public function test_hasil_yang_datang_tidak_berurutan_tidak_mengubah_status(): void
    {
        $this->hasil($this->ok(), 0);
        $this->hasil($this->ok(), 60);
        $this->hasil($this->ok(), 240);

        $sebelum = $this->statusKini()->only(['status', 'gagal_berturut', 'berhasil_berturut', 'sejak', 'diperiksa_terakhir_pada']);

        // Tiga kegagalan dari menit-menit sebelumnya baru tiba sekarang.
        foreach ([120, 150, 180] as $detik) {
            $terlambat = $this->hasil($this->gagal(), $detik);
        }

        $this->assertFalse($terlambat->diterapkan);
        $this->assertSame(HasilPemeriksaan::TERLAMBAT, $terlambat->alasan_tidak_diterapkan);
        $this->assertTrue($terlambat->diperiksa_pada->equalTo($this->awal->addSeconds(180)));
        // Diterima setelah hasil yang lebih baru: cap waktu penerimaan tetap jam server.
        $this->assertTrue($terlambat->diterima_pada->equalTo($this->awal->addSeconds(240)));

        $this->assertEquals($sebelum, $this->statusKini()->only(['status', 'gagal_berturut', 'berhasil_berturut', 'sejak', 'diperiksa_terakhir_pada']));
        $this->assertSame(StatusMonitoring::Normal, $this->statusKini()->status);
        $this->assertSame(0, Insiden::query()->count());
        $this->assertCount(1, $this->riwayat());

        // Tetap tersimpan lengkap untuk analisis.
        $this->assertSame(6, HasilPemeriksaan::query()->count());
        $this->assertSame(3, HasilPemeriksaan::query()->where('diterapkan', false)->count());

        // Hasil bercap waktu sama dengan yang terakhir juga dianggap terlambat.
        $this->assertSame(HasilPemeriksaan::TERLAMBAT, $this->hasil($this->gagal(), 240)->alasan_tidak_diterapkan);

        // Hasil berikutnya yang berurutan diterapkan seperti biasa.
        $this->assertTrue($this->hasil($this->gagal(), 300)->diterapkan);
        $this->assertSame(StatusMonitoring::KoneksiMenurun, $this->statusKini()->status);
    }

    public function test_hasil_yang_terkirim_dua_kali_hanya_dihitung_sekali(): void
    {
        $id = (string) Str::uuid();

        $pertama = $this->hasil($this->gagal(), 0, $id);
        $kedua = $this->hasil($this->gagal(), 0, $id);
        $ketiga = $this->hasil($this->ok(), 30, $id);

        $this->assertSame($pertama->id, $kedua->id);
        $this->assertSame($pertama->id, $ketiga->id);
        $this->assertSame(1, HasilPemeriksaan::query()->count());
        $this->assertSame(1, $this->statusKini()->gagal_berturut);
    }

    public function test_hasil_bercap_waktu_jauh_di_depan_ditolak(): void
    {
        $this->hasil($this->ok(), 0);

        // Jam kolektor melenceng satu jam ke depan.
        $baris = $this->layanan->terimaHasil($this->endpoint, $this->gagal(), $this->awal->addHour(), (string) Str::uuid(), 'uji', false);

        $this->assertFalse($baris->diterapkan);
        $this->assertSame(HasilPemeriksaan::WAKTU_DI_DEPAN, $baris->alasan_tidak_diterapkan);
        $this->assertSame(StatusMonitoring::Normal, $this->statusKini()->status);

        // Hasil berikutnya yang wajar tidak dianggap terlambat karenanya.
        $this->assertTrue($this->hasil($this->ok(), 60)->diterapkan);

        // Selisih kecil masih diterima.
        $this->assertTrue($this->layanan->terimaHasil($this->endpoint, $this->ok(), now()->addSeconds(200), (string) Str::uuid(), 'uji', false)->diterapkan);
    }

    public function test_cap_waktu_pemeriksaan_dan_penerimaan_disimpan_dalam_utc(): void
    {
        // Kolektor mengirim cap waktu WIB; server menerimanya 5 detik kemudian.
        $diperiksa = CarbonImmutable::parse('2026-10-09 10:00:00', 'Asia/Jakarta');
        $this->travelTo($diperiksa->addSeconds(5));

        $baris = $this->layanan->terimaHasil($this->endpoint, $this->ok(), $diperiksa, (string) Str::uuid(), 'uji', false);

        $mentah = DB::selectOne(
            "select to_char(diperiksa_pada at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as diperiksa,
                    to_char(diterima_pada at time zone 'UTC', 'YYYY-MM-DD HH24:MI:SS') as diterima,
                    extract(epoch from diperiksa_pada)::bigint as epoch,
                    current_setting('TimeZone') as zona
               from hasil_pemeriksaan where id = ?",
            [$baris->id],
        );

        $this->assertSame('UTC', $mentah->zona);
        $this->assertSame('2026-10-09 03:00:00', $mentah->diperiksa);
        $this->assertSame('2026-10-09 03:00:05', $mentah->diterima);
        $this->assertSame($diperiksa->timestamp, (int) $mentah->epoch);

        $baris->refresh();
        $this->assertSame(0, $baris->diperiksa_pada->getOffset());
        $this->assertSame('2026-10-09T03:00:00+00:00', $baris->diperiksa_pada->toIso8601String());
        $this->assertSame('2026-10-09T03:00:05+00:00', $baris->diterima_pada->toIso8601String());

        $status = $this->statusKini();
        $this->assertSame($diperiksa->timestamp, $status->diperiksa_terakhir_pada->timestamp);
        $this->assertSame($diperiksa->timestamp, $status->sejak->timestamp);
        $this->assertSame($diperiksa->timestamp, RiwayatStatus::query()->sole()->pada->timestamp);
    }

    public function test_riwayat_status_tidak_dapat_diubah_atau_dihapus(): void
    {
        $this->hasil($this->ok(), 0);
        $riwayat = RiwayatStatus::query()->sole();

        try {
            $riwayat->update(['alasan' => AlasanStatus::Pulih]);
            $this->fail('Riwayat status seharusnya tidak dapat diubah.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(LogicException::class);

        $riwayat->delete();
    }

    public function test_database_hanya_mengizinkan_satu_insiden_terbuka_per_endpoint(): void
    {
        Insiden::create(['endpoint_id' => $this->endpoint->id, 'mulai_pada' => now()]);

        $this->expectException(UniqueConstraintViolationException::class);

        Insiden::create(['endpoint_id' => $this->endpoint->id, 'mulai_pada' => now()]);
    }
}
