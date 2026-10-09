<?php

namespace Tests\Feature\Monitoring;

use App\Enums\AksiAktivitas;
use App\Enums\StatusMonitoring;
use App\Models\EndpointMonitoring;
use App\Models\HasilPemeriksaan;
use App\Models\LogAktivitas;
use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\ProfilSnmp;
use App\Models\User;
use App\Monitoring\Kolektor;
use App\Support\BrankasKredensial;
use Database\Seeders\SimulasiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Symfony\Component\Finder\SplFileInfo;
use Tests\TestCase;

class EndpointDanArsitekturTest extends TestCase
{
    use RefreshDatabase;

    public function test_endpoint_mengikuti_penanda_monitoring_perangkat(): void
    {
        $opd = Opd::factory()->create();
        $perangkat = Perangkat::factory()->create(['opd_id' => $opd->id, 'kode_aset' => 'SW-01', 'nama' => 'Switch Satu', 'ip_manajemen' => '198.51.100.5']);

        $this->assertSame(0, EndpointMonitoring::query()->count(), 'Monitoring belum diaktifkan.');

        $perangkat->update(['monitoring_aktif' => true]);

        $endpoint = EndpointMonitoring::query()->sole();
        $this->assertSame($perangkat->id, $endpoint->perangkat_id);
        $this->assertSame('SW-01 — Switch Satu', $endpoint->nama);
        $this->assertSame('198.51.100.5', $endpoint->alamat);
        $this->assertSame('icmp', $endpoint->metode);
        $this->assertSame(60, $endpoint->interval_detik);
        $this->assertTrue($endpoint->aktif);
        $this->assertSame(StatusMonitoring::BelumDiketahui, $endpoint->status?->status);

        // Perubahan IP dan nama ikut tersalin; tidak ada endpoint kedua.
        $perangkat->update(['ip_manajemen' => '198.51.100.6', 'nama' => 'Switch Satu Baru']);
        $endpoint->refresh();
        $this->assertSame('198.51.100.6', $endpoint->alamat);
        $this->assertSame('SW-01 — Switch Satu Baru', $endpoint->nama);
        $this->assertSame(1, EndpointMonitoring::query()->count());

        // Dimatikan: endpoint dan riwayatnya tetap ada, tetapi tidak diperiksa.
        $perangkat->update(['monitoring_aktif' => false]);
        $this->assertFalse($endpoint->refresh()->aktif);
        $this->assertSame(0, app(Kolektor::class)->jalankanSekali()['diperiksa']);

        $perangkat->update(['monitoring_aktif' => true]);
        $this->assertTrue($endpoint->refresh()->aktif);
        $this->assertSame(1, app(Kolektor::class)->jalankanSekali()['diperiksa']);
    }

    public function test_menghapus_perangkat_mempertahankan_riwayat_monitoring(): void
    {
        $perangkat = Perangkat::factory()->dipantau()->create();
        $endpoint = EndpointMonitoring::query()->sole();
        app(Kolektor::class)->jalankanSekali();

        $perangkat->delete();

        $endpoint->refresh();
        $this->assertNull($endpoint->perangkat_id);
        $this->assertFalse($endpoint->aktif);
        $this->assertSame(1, HasilPemeriksaan::query()->where('endpoint_id', $endpoint->id)->count());
        $this->assertSame(StatusMonitoring::BelumDiketahui, $endpoint->status?->statusTampil());
    }

    public function test_formulir_perangkat_membuat_endpoint_tanpa_menghubungi_jaringan(): void
    {
        $opd = Opd::factory()->create();
        $this->actingAs(User::factory()->operator()->create());

        Livewire::test('pages::perangkat.index')
            ->call('tambah')
            ->set('opd_id', (string) $opd->id)
            ->set('kode_aset', 'SW-02')
            ->set('nama', 'Switch Dua')
            ->set('jenis', 'switch')
            ->set('ip_manajemen', '198.51.100.7')
            ->set('monitoring_aktif', true)
            ->call('simpan')
            ->assertHasNoErrors();

        // Endpoint terbentuk, tetapi pemeriksaan hanya dijalankan kolektor.
        $this->assertSame('198.51.100.7', EndpointMonitoring::query()->sole()->alamat);
        $this->assertSame(0, HasilPemeriksaan::query()->count());
    }

    public function test_kode_web_tidak_memanggil_adapter_kolektor_atau_membuka_kredensial(): void
    {
        $terlarang = [
            'App\\Monitoring\\Adapter',
            'App\\Monitoring\\Kontrak',
            'App\\Monitoring\\Kolektor',
            'Monitoring\\Kolektor',
            'AdapterPemeriksa',
            '->kredensial()',
            'kunci_privat',
            'jalankanSekali',
        ];

        $berkas = collect([app_path('Livewire'), app_path('Http'), resource_path('views'), base_path('routes')])
            ->filter(fn (string $folder) => File::isDirectory($folder))
            ->flatMap(fn (string $folder) => File::allFiles($folder));

        $this->assertGreaterThan(20, $berkas->count());

        $berkas->each(function (SplFileInfo $f) use ($terlarang): void {
            foreach ($terlarang as $pola) {
                $this->assertStringNotContainsString(
                    $pola,
                    $f->getContents(),
                    "{$f->getRelativePathname()} memanggil '{$pola}'. Pemeriksaan jaringan dan kredensial hanya untuk proses kolektor.",
                );
            }
        });
    }

    public function test_data_simulasi_monitoring_berlabel_dan_dapat_dihapus(): void
    {
        $kunci = BrankasKredensial::buatPasanganKunci();
        config(['monitoring.kunci_publik' => $kunci['publik'], 'monitoring.kunci_privat' => $kunci['privat']]);

        $this->seed(SimulasiSeeder::class);

        $endpoint = EndpointMonitoring::query()->get();

        // Satu endpoint per perangkat contoh yang monitoringnya diaktifkan.
        $this->assertSame(Perangkat::query()->simulasi()->where('monitoring_aktif', true)->count(), $endpoint->count());
        $this->assertGreaterThan(5, $endpoint->count());
        $this->assertTrue($endpoint->every(fn (EndpointMonitoring $e) => $e->simulasi));
        $this->assertSame(0, EndpointMonitoring::query()->whereRaw("NOT (alamat << '192.0.2.0/24')")->count());
        $this->assertSame(1, EndpointMonitoring::query()->where('pemeliharaan', true)->count());
        $this->assertSame(1, ProfilSnmp::query()->simulasi()->whereLike('nama', '[SIMULASI]%')->count());

        // Menjalankan ulang tidak menggandakan apa pun.
        $this->seed(SimulasiSeeder::class);
        $this->assertSame($endpoint->count(), EndpointMonitoring::query()->count());
        $this->assertSame(1, ProfilSnmp::query()->count());

        // Tiga putaran kolektor memperlihatkan semua status, seluruhnya berlabel SIMULASI.
        foreach ([0, 60, 120] as $detik) {
            $this->travelTo(now()->addSeconds($detik === 0 ? 0 : 60));
            app(Kolektor::class)->jalankanSekali();
        }

        $tampil = EndpointMonitoring::query()->with('status')->get()
            ->map(function (EndpointMonitoring $e) {
                $e->status?->setRelation('endpoint', $e);

                return $e->status?->statusTampil()->value;
            })
            ->countBy()
            ->all();

        foreach (['normal', 'menurun', 'tidak_terjangkau', 'belum_diketahui', 'pemeliharaan'] as $status) {
            $this->assertArrayHasKey($status, $tampil, "Tidak ada endpoint contoh berstatus {$status}.");
        }

        $this->assertSame(0, HasilPemeriksaan::query()->where('simulasi', false)->count());
        $this->assertSame(0, DB::table('status_terkini')->where('simulasi', false)->count());

        $this->artisan('simulasi:hapus', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, EndpointMonitoring::query()->count());
        $this->assertSame(0, HasilPemeriksaan::query()->count());
        $this->assertSame(0, ProfilSnmp::query()->count());
    }

    public function test_cap_waktu_log_aktivitas_tersimpan_sebagai_saat_yang_benar(): void
    {
        // Regresi: tanpa zona waktu sesi UTC, kolom timestamptz bergeser sebesar
        // selisih zona waktu server database.
        $this->travelTo('2026-10-09 03:00:00');

        $log = LogAktivitas::create(['waktu' => now(), 'aksi' => AksiAktivitas::Masuk]);

        $mentah = DB::selectOne("select extract(epoch from waktu)::bigint as epoch, current_setting('TimeZone') as zona from log_aktivitas where id = ?", [$log->id]);

        $this->assertSame('UTC', $mentah->zona);
        $this->assertSame(now()->timestamp, (int) $mentah->epoch);
        $this->assertSame('2026-10-09 10:00', $log->refresh()->waktu->timezone('Asia/Jakarta')->format('Y-m-d H:i'));
    }
}
