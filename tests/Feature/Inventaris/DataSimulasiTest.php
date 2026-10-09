<?php

namespace Tests\Feature\Inventaris;

use App\Models\Opd;
use App\Models\Perangkat;
use Database\Seeders\SimulasiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DataSimulasiTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_mengisi_data_yang_seluruhnya_berlabel_simulasi(): void
    {
        $this->seed(SimulasiSeeder::class);

        $this->assertGreaterThan(5, Opd::query()->count());
        $this->assertGreaterThan(10, Perangkat::query()->count());

        $this->assertSame(0, Opd::query()->where('simulasi', false)->count());
        $this->assertSame(0, Perangkat::query()->where('simulasi', false)->count());
        $this->assertSame(0, Opd::query()->whereNotLike('nama', '[SIMULASI]%')->count());
        $this->assertSame(0, Perangkat::query()->whereNotLike('nama', '[SIMULASI]%')->count());

        // IP contoh hanya dari rentang dokumentasi RFC 5737, bukan alamat nyata.
        $this->assertSame(0, Perangkat::query()->whereNotNull('ip_manajemen')->whereRaw("NOT (ip_manajemen << '192.0.2.0/24')")->count());

        // Diskominfo dan OPD sama-sama punya perangkat; ada OPD berperangkat lebih dari satu.
        $this->assertTrue(Perangkat::query()->where('penempatan', 'diskominfo')->exists());
        $this->assertTrue(Perangkat::query()->where('penempatan', 'opd')->exists());
        $this->assertTrue(Opd::query()->has('perangkat', '>', 1)->exists());
        $this->assertSame(0, Opd::query()->has('switchUtama', '>', 1)->count());
    }

    public function test_seeder_dapat_dijalankan_ulang_tanpa_menggandakan_data(): void
    {
        $this->seed(SimulasiSeeder::class);
        $jumlah = [Opd::query()->count(), Perangkat::query()->count()];

        $this->seed(SimulasiSeeder::class);

        $this->assertSame($jumlah, [Opd::query()->count(), Perangkat::query()->count()]);
    }

    public function test_seeder_menolak_berjalan_di_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        try {
            (new SimulasiSeeder)->run();
            $this->fail('Seeder simulasi seharusnya menolak berjalan di production.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('production', $e->getMessage());
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }

        $this->assertSame(0, Opd::query()->count());
    }

    public function test_perintah_hapus_hanya_menghapus_data_simulasi(): void
    {
        $this->seed(SimulasiSeeder::class);

        $opdNyata = Opd::factory()->create();
        $perangkatNyata = Perangkat::factory()->create(['opd_id' => $opdNyata->id]);
        $opdSimulasi = Opd::query()->simulasi()->firstOrFail();
        $nyataDiOpdSimulasi = Perangkat::factory()->create(['opd_id' => $opdSimulasi->id]);

        $this->artisan('simulasi:hapus', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Perangkat::query()->simulasi()->count());
        $this->assertNotNull($opdNyata->fresh());
        $this->assertNotNull($perangkatNyata->fresh());
        $this->assertNotNull($nyataDiOpdSimulasi->fresh());
        $this->assertSame([$opdSimulasi->id], Opd::query()->simulasi()->pluck('id')->all());
    }
}
