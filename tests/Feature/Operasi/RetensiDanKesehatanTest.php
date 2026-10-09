<?php

namespace Tests\Feature\Operasi;

use App\Jobs\CatatDetakAntrean;
use App\Kesehatan\PemeriksaKesehatan;
use App\Models\EndpointMonitoring;
use App\Models\Insiden;
use App\Models\RiwayatStatus;
use App\Monitoring\HasilAdapter;
use App\Monitoring\LayananStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

class RetensiDanKesehatanTest extends TestCase
{
    use RefreshDatabase;

    private function isiMonitoring(): EndpointMonitoring
    {
        $endpoint = EndpointMonitoring::factory()->create();
        $layanan = app(LayananStatus::class);
        $kini = CarbonImmutable::now()->utc();

        // Tiga kegagalan 100 hari lalu (membuka insiden), dua hasil baru hari ini.
        foreach ([100 * 86400, 100 * 86400 - 60, 100 * 86400 - 120] as $detikLalu) {
            $pada = $kini->subSeconds($detikLalu);
            $layanan->terimaHasil($endpoint->refresh(), HasilAdapter::gagalPerangkat(), $pada, (string) Str::uuid(), 'uji', false, $pada);
        }

        foreach ([120, 60] as $detikLalu) {
            $pada = $kini->subSeconds($detikLalu);
            $layanan->terimaHasil($endpoint->refresh(), HasilAdapter::berhasil(10), $pada, (string) Str::uuid(), 'uji', false, $pada);
        }

        return $endpoint;
    }

    public function test_pangkas_menghapus_hasil_lama_dan_menyimpan_riwayat_serta_insiden(): void
    {
        $this->isiMonitoring();

        $riwayat = RiwayatStatus::query()->count();
        $this->assertSame(5, DB::table('hasil_pemeriksaan')->count());
        $this->assertSame(1, Insiden::query()->count());
        $this->assertGreaterThanOrEqual(3, $riwayat);

        $this->artisan('monitoring:pangkas --uji-coba')
            ->expectsOutputToContain('Hasil pemeriksaan: 3 baris lebih tua dari 90 hari akan dihapus.')
            ->assertSuccessful();
        $this->assertSame(5, DB::table('hasil_pemeriksaan')->count(), 'Uji coba tidak menghapus.');

        $this->artisan('monitoring:pangkas')
            ->expectsOutputToContain('Hasil pemeriksaan: 3 baris lebih tua dari 90 hari dihapus.')
            ->assertSuccessful();

        $this->assertSame(2, DB::table('hasil_pemeriksaan')->count());
        // Dasar laporan tetap utuh; rujukan ke hasil yang dihapus dikosongkan.
        $this->assertSame($riwayat, RiwayatStatus::query()->count());
        $this->assertSame(1, Insiden::query()->count());
        $this->assertSame(0, DB::table('riwayat_status')->whereNotNull('hasil_pemeriksaan_id')
            ->whereNotIn('hasil_pemeriksaan_id', DB::table('hasil_pemeriksaan')->select('id'))->count());
    }

    public function test_retensi_nol_berarti_tidak_dipangkas(): void
    {
        $this->isiMonitoring();
        config(['monitoring.retensi.hasil_hari' => 0]);

        $this->artisan('monitoring:pangkas')
            ->expectsOutputToContain('Hasil pemeriksaan: retensi dimatikan')
            ->assertSuccessful();

        $this->assertSame(5, DB::table('hasil_pemeriksaan')->count());
    }

    public function test_jadwal_memuat_retensi_dan_tanda_hidup_tanpa_kolektor(): void
    {
        $perintah = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description.' '.$e->command)->implode("\n");

        $this->assertStringContainsString('monitoring:pangkas', $perintah);
        $this->assertStringContainsString('detak-penjadwal', $perintah);
        $this->assertStringContainsString('detak-antrean', $perintah);
        // Pemeriksaan jaringan bukan pekerjaan penjadwal web.
        $this->assertStringNotContainsString('monitoring:kolektor', $perintah);
        $this->assertStringNotContainsString('monitoring:uji-endpoint', $perintah);
    }

    public function test_kesehatan_melaporkan_komponen_yang_berhenti(): void
    {
        Process::fake();

        $hasil = app(PemeriksaKesehatan::class)->periksa();

        $this->assertFalse($hasil['sehat']);
        $this->assertTrue($hasil['komponen']['database']['baik']);
        $this->assertTrue($hasil['komponen']['penyimpanan']['baik']);
        $this->assertFalse($hasil['komponen']['penjadwal']['baik']);
        $this->assertFalse($hasil['komponen']['antrean']['baik']);
        $this->assertFalse($hasil['komponen']['kolektor']['baik']);

        $this->artisan('kesehatan:periksa')->expectsOutputToContain('BERMASALAH')->assertFailed();

        // Semua hidup.
        PemeriksaKesehatan::catatDetak(PemeriksaKesehatan::DETAK_PENJADWAL);
        (new CatatDetakAntrean)->handle();
        DB::table('detak_kolektor')->insert(['nama' => 'uji-1', 'adapter' => 'simulasi', 'simulasi' => true, 'mulai_pada' => now(), 'detak_pada' => now()]);

        $this->assertTrue(app(PemeriksaKesehatan::class)->periksa()['sehat']);
        $this->artisan('kesehatan:periksa')->assertSuccessful();

        // Worker berhenti: tanda hidupnya menua.
        $this->travel(10)->minutes();
        PemeriksaKesehatan::catatDetak(PemeriksaKesehatan::DETAK_PENJADWAL);
        DB::table('detak_kolektor')->update(['detak_pada' => now()]);

        $hasil = app(PemeriksaKesehatan::class)->periksa();
        $this->assertFalse($hasil['sehat']);
        $this->assertTrue($hasil['komponen']['penjadwal']['baik']);
        $this->assertFalse($hasil['komponen']['antrean']['baik']);

        Process::assertNothingRan();
    }

    public function test_titik_kesehatan_tertutup_tanpa_token_yang_benar(): void
    {
        // Tanpa token terpasang: tidak ada.
        $this->get('/kesehatan')->assertNotFound();
        $this->withToken('apa-saja')->get('/kesehatan')->assertNotFound();

        config(['kesehatan.token' => 'token-uji-kesehatan']);

        $this->flushHeaders()->get('/kesehatan')->assertNotFound();
        $this->withToken('salah')->get('/kesehatan')->assertNotFound();

        $jawaban = $this->withToken('token-uji-kesehatan')->get('/kesehatan');
        $jawaban->assertStatus(503)->assertJsonPath('status', 'bermasalah')->assertJsonPath('komponen.database', 'baik');

        // Hanya baik/bermasalah; tanpa rincian server.
        $this->assertSame(['status', 'komponen'], array_keys($jawaban->json()));
        $this->assertStringNotContainsString('MB', (string) $jawaban->getContent());

        PemeriksaKesehatan::catatDetak(PemeriksaKesehatan::DETAK_PENJADWAL);
        PemeriksaKesehatan::catatDetak(PemeriksaKesehatan::DETAK_ANTREAN);
        DB::table('detak_kolektor')->insert(['nama' => 'uji-1', 'adapter' => 'simulasi', 'simulasi' => true, 'mulai_pada' => now(), 'detak_pada' => now()]);

        $this->withToken('token-uji-kesehatan')->get('/kesehatan')->assertOk()->assertJsonPath('status', 'baik');
    }

    public function test_env_contoh_tidak_memuat_rahasia(): void
    {
        $baris = file(base_path('.env.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($baris as $b) {
            if (preg_match('/^(?<kunci>[A-Z0-9_]*(PASSWORD|SECRET|TOKEN|KUNCI|SANDI|COMMUNITY|APP_KEY)[A-Z0-9_]*)=(?<nilai>.*)$/', $b, $cocok) === 1) {
                $this->assertContains($cocok['nilai'], ['', 'null'], "{$cocok['kunci']} di .env.example harus kosong.");
            }
        }

        $this->assertNotEmpty($baris);
    }
}
