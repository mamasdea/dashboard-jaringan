<?php

namespace Tests\Feature\Monitoring;

use App\Models\EndpointMonitoring;
use App\Models\ProfilSnmp;
use App\Monitoring\SasaranPemeriksaan;
use App\Support\BrankasKredensial;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Kredensial SNMP: terpisah dari perangkat, tersegel, dan hanya dapat dibuka
 * oleh host yang memegang kunci privat.
 */
class ProfilSnmpTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{publik: string, privat: string} */
    private array $kunci;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kunci = BrankasKredensial::buatPasanganKunci();
        $this->sebagaiKolektor();
    }

    private function sebagaiKolektor(): void
    {
        config(['monitoring.kunci_publik' => $this->kunci['publik'], 'monitoring.kunci_privat' => $this->kunci['privat']]);
    }

    private function sebagaiWeb(): void
    {
        config(['monitoring.kunci_publik' => $this->kunci['publik'], 'monitoring.kunci_privat' => null]);
    }

    private function profilV3(): ProfilSnmp
    {
        $profil = new ProfilSnmp(['nama' => 'SNMPv3 switch OPD', 'versi' => 'v3']);

        $profil->aturKredensial([
            'pengguna' => 'pemantau',
            'tingkat_keamanan' => 'authPriv',
            'protokol_auth' => 'SHA',
            'sandi_auth' => 'sandi-auth-sangat-rahasia',
            'protokol_priv' => 'AES',
            'sandi_priv' => 'sandi-priv-sangat-rahasia',
        ])->save();

        return $profil;
    }

    public function test_kredensial_tersimpan_tersegel_dan_dapat_dibuka_kolektor(): void
    {
        $profil = $this->profilV3();

        $mentah = (string) DB::table('profil_snmp')->where('id', $profil->id)->value('kredensial_tersegel');

        $this->assertNotSame('', $mentah);
        $this->assertStringNotContainsString('sandi-auth-sangat-rahasia', $mentah);
        $this->assertStringNotContainsString('pemantau', $mentah);
        $this->assertStringNotContainsString('sandi-auth-sangat-rahasia', (string) base64_decode($mentah));

        $dibuka = ProfilSnmp::query()->findOrFail($profil->id)->kredensial();

        $this->assertSame('pemantau', $dibuka['pengguna']);
        $this->assertSame('sandi-auth-sangat-rahasia', $dibuka['sandi_auth']);
        $this->assertSame('sandi-priv-sangat-rahasia', $dibuka['sandi_priv']);

        // Setiap penyegelan menghasilkan teks sandi yang berbeda.
        $ulang = app(BrankasKredensial::class)->segel($dibuka);
        $this->assertNotSame($mentah, $ulang);
        $this->assertSame($dibuka, app(BrankasKredensial::class)->buka($ulang));
    }

    public function test_host_web_dapat_menyimpan_tetapi_tidak_dapat_membaca_kredensial(): void
    {
        $this->sebagaiWeb();

        $this->assertFalse(app(BrankasKredensial::class)->dapatMembuka());

        // Menyimpan kredensial baru cukup dengan kunci publik.
        $profil = new ProfilSnmp(['nama' => 'SNMPv2c baca-saja', 'versi' => 'v2c']);
        $profil->aturKredensial(['community' => 'community-sangat-rahasia'])->save();

        try {
            $profil->refresh()->kredensial();
            $this->fail('Host web seharusnya tidak dapat membuka kredensial.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('MONITORING_KUNCI_PRIVAT', $e->getMessage());
            $this->assertStringNotContainsString('community-sangat-rahasia', $e->getMessage());
        }

        // Kolektor, dengan kunci privat, dapat membukanya.
        $this->sebagaiKolektor();
        $this->assertSame(['community' => 'community-sangat-rahasia'], $profil->kredensial());
    }

    public function test_kunci_yang_salah_atau_tidak_ada_ditolak(): void
    {
        $profil = $this->profilV3();

        $lain = BrankasKredensial::buatPasanganKunci();
        config(['monitoring.kunci_publik' => $lain['publik'], 'monitoring.kunci_privat' => $lain['privat']]);

        try {
            $profil->kredensial();
            $this->fail('Kunci lain seharusnya tidak dapat membuka kredensial.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('kunci tidak cocok', $e->getMessage());
        }

        config(['monitoring.kunci_publik' => null, 'monitoring.kunci_privat' => null]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MONITORING_KUNCI_PUBLIK');

        (new ProfilSnmp(['nama' => 'Tanpa kunci', 'versi' => 'v2c']))->aturKredensial(['community' => 'x']);
    }

    public function test_kredensial_tidak_ikut_diserialkan(): void
    {
        $profil = $this->profilV3();

        $serial = (string) json_encode($profil->refresh()->toArray());

        $this->assertStringNotContainsString('kredensial', $serial);
        $this->assertStringNotContainsString('sandi', $serial);
        $this->assertArrayNotHasKey('kredensial_tersegel', $profil->toArray());
        // Tidak dapat diisi lewat mass assignment.
        $this->assertNull((new ProfilSnmp(['nama' => 'X', 'versi' => 'v2c', 'kredensial_tersegel' => 'polos']))->getAttribute('kredensial_tersegel'));
    }

    public function test_kredensial_wajib_sesuai_versi(): void
    {
        foreach ([
            ['v2c', []],
            ['v2c', ['community' => '']],
            ['v3', ['pengguna' => 'pemantau']],
            ['v1', ['community' => 'x']],
        ] as [$versi, $kredensial]) {
            try {
                (new ProfilSnmp(['nama' => 'Uji', 'versi' => $versi]))->aturKredensial($kredensial);
                $this->fail("Kredensial {$versi} yang tidak lengkap seharusnya ditolak.");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_kredensial_snmp_terpisah_dari_tabel_perangkat_dan_endpoint(): void
    {
        foreach (['perangkat', 'endpoint_monitoring', 'port_perangkat'] as $tabel) {
            foreach (Schema::getColumnListing($tabel) as $kolom) {
                $this->assertDoesNotMatchRegularExpression(
                    '/community|komunitas|kredensial|credential|password|sandi|secret|rahasia|token|auth|priv/i',
                    $kolom,
                    "Kolom {$tabel}.{$kolom} tampak menyimpan kredensial.",
                );
            }
        }

        $this->assertContains('kredensial_tersegel', Schema::getColumnListing('profil_snmp'));
    }

    public function test_endpoint_snmp_merujuk_profil_dan_kredensial_dibuka_hanya_bila_diminta(): void
    {
        $profil = $this->profilV3();
        $endpoint = EndpointMonitoring::factory()->create(['metode' => 'snmp', 'profil_snmp_id' => $profil->id]);

        // Sasaran dapat dibentuk tanpa kunci privat; kredensial baru dibuka saat adapter memintanya.
        $this->sebagaiWeb();
        $sasaran = SasaranPemeriksaan::dari($endpoint->refresh());

        $this->assertSame('snmp', $sasaran->metode);
        $this->assertSame('v3', $sasaran->versiSnmp);
        $this->assertSame(161, $sasaran->portSnmp);
        $this->assertNotNull($sasaran->kredensialSnmp);

        $this->sebagaiKolektor();
        $this->assertSame('pemantau', ($sasaran->kredensialSnmp)()['pengguna']);

        // Endpoint ICMP tidak membawa kredensial sama sekali.
        $this->assertNull(SasaranPemeriksaan::dari(EndpointMonitoring::factory()->create())->kredensialSnmp);

        // Profil yang masih dipakai tidak dapat dihapus, dan metode SNMP wajib punya profil.
        try {
            DB::transaction(fn () => $profil->delete());
            $this->fail('Profil yang dipakai seharusnya tidak dapat dihapus.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(QueryException::class);

        EndpointMonitoring::factory()->create(['metode' => 'snmp', 'profil_snmp_id' => null]);
    }

    public function test_perintah_buat_kunci_menghasilkan_pasangan_yang_berfungsi(): void
    {
        $this->artisan('monitoring:buat-kunci')
            ->expectsOutputToContain('MONITORING_KUNCI_PUBLIK=')
            ->expectsOutputToContain('MONITORING_KUNCI_PRIVAT=')
            ->expectsOutputToContain('HANYA host kolektor')
            ->assertSuccessful();

        $baru = BrankasKredensial::buatPasanganKunci();
        $brankas = new BrankasKredensial($baru['publik'], $baru['privat']);

        $this->assertNotSame($baru['publik'], $baru['privat']);
        $this->assertSame(['community' => 'abc'], $brankas->buka($brankas->segel(['community' => 'abc'])));
    }
}
