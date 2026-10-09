<?php

namespace Tests\Feature\Fo;

use App\Fo\LayananHubungan;
use App\Models\Perangkat;
use App\Models\RiwayatHubungan;
use App\Models\Sambungan;
use App\Models\SegmenKabel;
use App\Models\Tiang;
use App\Models\TitikJaringan;
use App\Models\User;
use Database\Seeders\SimulasiSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Viewer tidak dapat melihat atau mengubah inventaris FO; Teknisi dapat
 * melihat tetapi tidak mengubah. Diuji lewat URL dan pemanggilan aksi langsung.
 */
class HakAksesFoTest extends TestCase
{
    use RefreshDatabase;

    private Tiang $tiang;

    private TitikJaringan $otb;

    private TitikJaringan $closure;

    private SegmenKabel $kabelA;

    private SegmenKabel $kabelB;

    private Sambungan $sambungan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tiang = Tiang::factory()->create(['kode' => 'TG-ASLI']);
        $this->otb = TitikJaringan::factory()->otb(4)->create(['kode' => 'OTB-ASLI']);
        $this->closure = TitikJaringan::factory()->create(['kode' => 'CL-ASLI']);
        $this->kabelA = SegmenKabel::factory()->antara($this->otb, $this->closure)->create(['kode' => 'KBL-A']);
        $this->kabelB = SegmenKabel::factory()->antara($this->closure, TitikJaringan::factory()->create())->create(['kode' => 'KBL-B']);

        $layanan = app(LayananHubungan::class);
        $layanan->aturRuteTiang($this->kabelA, [$this->tiang->id]);
        $this->sambungan = $layanan->sambung(
            $this->closure,
            $this->kabelA->core()->where('nomor', 1)->firstOrFail(),
            $this->kabelB->core()->where('nomor', 1)->firstOrFail(),
        );
    }

    /**
     * @return array<string, int>
     */
    private function potret(): array
    {
        return [
            'tiang' => Tiang::query()->count(),
            'titik' => TitikJaringan::query()->count(),
            'segmen' => SegmenKabel::query()->count(),
            'rute' => DB::table('tiang_segmen')->count(),
            'sambungan' => Sambungan::query()->count(),
            'ujung' => DB::table('pemakaian_ujung_core')->count(),
            'riwayat' => RiwayatHubungan::query()->count(),
            'core_rusak' => DB::table('core_kabel')->where('status', 'rusak')->count(),
            'port_rusak' => DB::table('port_terminasi')->where('status', 'rusak')->count(),
            'berjalur' => SegmenKabel::query()->whereNull('jalur_peta')->count(),
        ];
    }

    /**
     * Komponen, parameter mount, aksi, dan argumennya.
     *
     * @return array<string, array{string, string|null, string, list<string|int>}>
     */
    public static function aksiPengubah(): array
    {
        return [
            'tiang tambah' => ['pages::fo.tiang', null, 'tambah', []],
            'tiang ubah' => ['pages::fo.tiang', null, 'ubah', ['tiang']],
            'tiang simpan' => ['pages::fo.tiang', null, 'simpan', []],
            'tiang konfirmasiHapus' => ['pages::fo.tiang', null, 'konfirmasiHapus', ['tiang']],
            'tiang hapus' => ['pages::fo.tiang', null, 'hapus', []],
            'titik tambah' => ['pages::fo.titik', null, 'tambah', []],
            'titik ubah' => ['pages::fo.titik', null, 'ubah', ['closure']],
            'titik simpan' => ['pages::fo.titik', null, 'simpan', []],
            'titik konfirmasiHapus' => ['pages::fo.titik', null, 'konfirmasiHapus', ['closure']],
            'titik hapus' => ['pages::fo.titik', null, 'hapus', []],
            'kabel tambah' => ['pages::fo.kabel', null, 'tambah', []],
            'kabel ubah' => ['pages::fo.kabel', null, 'ubah', ['kabelA']],
            'kabel simpan' => ['pages::fo.kabel', null, 'simpan', []],
            'kabel konfirmasiHapus' => ['pages::fo.kabel', null, 'konfirmasiHapus', ['kabelA']],
            'kabel hapus' => ['pages::fo.kabel', null, 'hapus', []],
            'rute tambahTiang' => ['pages::fo.kabel-rinci', 'kabelA', 'tambahTiang', []],
            'rute geserTiang' => ['pages::fo.kabel-rinci', 'kabelA', 'geserTiang', ['tiang', 1]],
            'rute lepasTiang' => ['pages::fo.kabel-rinci', 'kabelA', 'lepasTiang', ['tiang']],
            'jalur susunJalur' => ['pages::fo.kabel-rinci', 'kabelA', 'susunJalur', []],
            'jalur simpanJalur' => ['pages::fo.kabel-rinci', 'kabelA', 'simpanJalur', []],
            'core tandaiCore' => ['pages::fo.kabel-rinci', 'kabelA', 'tandaiCore', ['coreA2']],
            'hubungan sambung' => ['pages::fo.titik-rinci', 'closure', 'sambung', []],
            'hubungan lepasSambungan' => ['pages::fo.titik-rinci', 'closure', 'lepasSambungan', ['sambungan']],
            'hubungan terminasikan' => ['pages::fo.titik-rinci', 'otb', 'terminasikan', []],
            'hubungan lepasTerminasi' => ['pages::fo.titik-rinci', 'otb', 'lepasTerminasi', ['port1']],
            'hubungan hubungkanEndpoint' => ['pages::fo.titik-rinci', 'otb', 'hubungkanEndpoint', []],
            'hubungan lepasEndpoint' => ['pages::fo.titik-rinci', 'otb', 'lepasEndpoint', [1]],
            'hubungan tandaiPort' => ['pages::fo.titik-rinci', 'otb', 'tandaiPort', ['port1']],
        ];
    }

    /**
     * @param  list<string|int>  $argumen
     * @return list<int>
     */
    private function argumen(array $argumen): array
    {
        return array_map(fn (string|int $a): int => match ($a) {
            'tiang' => $this->tiang->id,
            'closure' => $this->closure->id,
            'kabelA' => $this->kabelA->id,
            'sambungan' => $this->sambungan->id,
            'coreA2' => $this->kabelA->core()->where('nomor', 2)->firstOrFail()->id,
            'port1' => $this->otb->port()->where('nomor', 1)->firstOrFail()->id,
            default => (int) $a,
        }, $argumen);
    }

    /**
     * @return array<string, mixed>
     */
    private function parameterMount(string $komponen, ?string $mount): array
    {
        return match (true) {
            $mount === null => [],
            str_contains($komponen, 'kabel') => ['segmen' => $this->{$mount}],
            default => ['titik' => $this->{$mount}],
        };
    }

    public function test_viewer_ditolak_lewat_url(): void
    {
        $this->actingAs(User::factory()->viewer()->create());

        foreach ([
            '/fo/tiang', '/fo/titik', '/fo/kabel',
            route('fo.titik.rinci', $this->closure), route('fo.kabel.rinci', $this->kabelA), route('fo.tiang.foto', $this->tiang),
        ] as $url) {
            $this->get($url)->assertForbidden();
        }

        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('fo.tiang'))->assertDontSee(route('fo.kabel'));
    }

    public function test_tamu_dialihkan_ke_halaman_masuk(): void
    {
        foreach (['/fo/tiang', '/fo/titik', '/fo/kabel', route('fo.titik.rinci', $this->closure), route('fo.kabel.rinci', $this->kabelA)] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_viewer_tidak_dapat_memuat_komponen(): void
    {
        $this->actingAs(User::factory()->viewer()->create());

        Livewire::test('pages::fo.tiang')->assertForbidden();
        Livewire::test('pages::fo.titik')->assertForbidden();
        Livewire::test('pages::fo.kabel')->assertForbidden();
        Livewire::test('pages::fo.kabel-rinci', ['segmen' => $this->kabelA])->assertForbidden();
        Livewire::test('pages::fo.titik-rinci', ['titik' => $this->closure])->assertForbidden();
    }

    /**
     * Request palsu: snapshot komponen milik Operator dikirim ulang oleh Viewer.
     *
     * @param  list<string|int>  $argumen
     */
    #[DataProvider('aksiPengubah')]
    public function test_viewer_tidak_dapat_memanggil_aksi_secara_langsung(string $komponen, ?string $mount, string $aksi, array $argumen): void
    {
        $operator = User::factory()->operator()->create();
        $viewer = User::factory()->viewer()->create();
        $sebelum = $this->potret();

        $uji = Livewire::actingAs($operator)->test($komponen, $this->parameterMount($komponen, $mount));

        $this->actingAs($viewer);

        $uji->call($aksi, ...$this->argumen($argumen))->assertForbidden();

        $this->assertSame($sebelum, $this->potret());
    }

    /**
     * Teknisi lolos pemeriksaan tingkat komponen (boleh melihat), sehingga
     * penolakan di sini datang dari authorize() pada tiap aksi.
     *
     * @param  list<string|int>  $argumen
     */
    #[DataProvider('aksiPengubah')]
    public function test_teknisi_dapat_melihat_tetapi_tidak_dapat_mengubah(string $komponen, ?string $mount, string $aksi, array $argumen): void
    {
        $this->actingAs(User::factory()->teknisi()->create());
        $sebelum = $this->potret();

        Livewire::test($komponen, $this->parameterMount($komponen, $mount))
            ->assertOk()
            ->assertDontSee('data-test="tambah-', false)
            ->assertDontSee('data-test="sambung"', false)
            ->assertDontSee('data-test="simpan-jalur"', false)
            ->call($aksi, ...$this->argumen($argumen))
            ->assertForbidden();

        $this->assertSame($sebelum, $this->potret());
    }

    public function test_teknisi_dapat_membuka_semua_halaman_fo(): void
    {
        $this->actingAs(User::factory()->teknisi()->create());

        $this->get('/fo/tiang')->assertOk()->assertSee('TG-ASLI');
        $this->get('/fo/titik')->assertOk()->assertSee('CL-ASLI');
        $this->get('/fo/kabel')->assertOk()->assertSee('KBL-A');
        $this->get(route('fo.kabel.rinci', $this->kabelA))->assertOk()->assertSee('TG-ASLI')->assertDontSee('Tandai rusak');
        $this->get(route('fo.titik.rinci', $this->closure))->assertOk()->assertSee('KBL-A core 1 (biru)')->assertDontSee('Lepas');
    }

    public function test_seeder_simulasi_mengisi_inventaris_fo_berlabel(): void
    {
        $this->seed(SimulasiSeeder::class);

        $this->assertSame(8, Tiang::query()->simulasi()->count());
        $this->assertSame(0, Tiang::query()->simulasi()->whereNotLike('kode', 'SIM-%')->count());
        $this->assertSame(5, TitikJaringan::query()->simulasi()->whereLike('kode', 'SIM-%')->count());
        $this->assertSame(3, SegmenKabel::query()->simulasi()->whereLike('kode', 'SIM-%')->count());

        // Tiang 4 dilalui dua kabel; sebagian objek sengaja belum lengkap.
        $this->assertSame(2, Tiang::query()->where('kode', 'SIM-TG-004')->firstOrFail()->segmen()->count());
        $this->assertGreaterThan(0, Tiang::query()->perluDilengkapi()->count());
        $this->assertGreaterThan(0, TitikJaringan::query()->perluDilengkapi()->count());
        $this->assertGreaterThan(0, SegmenKabel::query()->perluDilengkapi()->count());
        $this->assertSame(2, Sambungan::query()->whereIn('titik_jaringan_id', TitikJaringan::query()->simulasi()->select('id'))->count());
        $this->assertTrue(Perangkat::query()->where('kode_aset', 'SIM-SW-SETDA-01')->firstOrFail()->port()->whereHas('koneksiEndpoint')->exists());

        $this->actingAs(User::factory()->operator()->create());
        $this->get('/fo/tiang')->assertOk()->assertSee('data-test="lencana-simulasi"', false)->assertSee('Perlu dilengkapi');
        $this->get(route('fo.titik.rinci', TitikJaringan::query()->where('kode', 'SIM-CL-01')->firstOrFail()))
            ->assertOk()
            ->assertSee('SIM-BB-01 core 1 (biru)');

        // Menjalankan ulang tidak menggandakan apa pun.
        $jumlah = $this->potret();
        $this->seed(SimulasiSeeder::class);
        $this->assertSame($jumlah, $this->potret());

        $this->artisan('simulasi:hapus', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Tiang::query()->simulasi()->count());
        $this->assertSame(0, TitikJaringan::query()->simulasi()->count());
        $this->assertSame(0, SegmenKabel::query()->simulasi()->count());
        $this->assertSame(0, Perangkat::query()->simulasi()->count());
        // Data bukan simulasi tidak tersentuh.
        $this->assertNotNull($this->sambungan->fresh());
        $this->assertNotNull($this->tiang->fresh());
    }
}
