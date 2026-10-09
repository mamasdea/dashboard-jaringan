<?php

namespace Tests\Feature\Tiket;

use App\Enums\AksiAktivitas;
use App\Models\CatatanTiket;
use App\Models\LogAktivitas;
use App\Models\Tiket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Viewer tidak dapat melihat atau mengubah tiket. Teknisi hanya dapat melihat
 * dan mengerjakan tiket yang ditugaskan kepadanya, dan tidak dapat mengelola.
 * Diuji lewat URL dan pemanggilan aksi langsung.
 */
class HakAksesTiketTest extends TestCase
{
    use RefreshDatabase;

    private User $teknisi;

    private Tiket $tiket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teknisi = User::factory()->teknisi()->create();
        $this->tiket = Tiket::factory()->untuk($this->teknisi)->create(['judul' => 'Tiket milik teknisi']);
    }

    /**
     * @return array<string, mixed>
     */
    private function potret(): array
    {
        return [
            'tiket' => Tiket::query()->orderBy('id')->get()->map->getAttributes()->all(),
            'catatan' => CatatanTiket::query()->count(),
        ];
    }

    /**
     * Aksi untuk pengelola (kelola-tiket).
     *
     * @return array<string, array{string, string, list<string>}>
     */
    public static function aksiKelola(): array
    {
        return [
            'daftar tambah' => ['pages::tiket.index', 'tambah', []],
            'daftar simpan' => ['pages::tiket.index', 'simpan', []],
            'rinci ubah' => ['pages::tiket.rinci', 'ubah', []],
            'rinci simpan' => ['pages::tiket.rinci', 'simpan', []],
            'rinci tugaskan' => ['pages::tiket.rinci', 'tugaskan', []],
            'rinci batalkan' => ['pages::tiket.rinci', 'ubahStatus', ['dibatalkan']],
            'rinci buka kembali' => ['pages::tiket.rinci', 'ubahStatus', ['baru']],
        ];
    }

    /**
     * Aksi untuk yang menangani (tangani-tiket pada tiket miliknya).
     *
     * @return array<string, array{string, string, list<string>}>
     */
    public static function aksiTangani(): array
    {
        return [
            'rinci mulai kerjakan' => ['pages::tiket.rinci', 'ubahStatus', ['dikerjakan']],
            'rinci tunda' => ['pages::tiket.rinci', 'ubahStatus', ['menunggu']],
            'rinci selesaikan' => ['pages::tiket.rinci', 'selesaikan', []],
            'rinci simpanPenyebab' => ['pages::tiket.rinci', 'simpanPenyebab', []],
            'rinci tambahCatatan' => ['pages::tiket.rinci', 'tambahCatatan', []],
        ];
    }

    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function semuaAksi(): array
    {
        return [...self::aksiKelola(), ...self::aksiTangani()];
    }

    /**
     * @return array<string, mixed>
     */
    private function parameter(string $komponen): array
    {
        return $komponen === 'pages::tiket.rinci' ? ['tiket' => $this->tiket] : [];
    }

    public function test_tamu_dialihkan_ke_halaman_masuk(): void
    {
        $this->get(route('tiket.index'))->assertRedirect(route('login'));
        $this->get(route('tiket.rinci', $this->tiket))->assertRedirect(route('login'));
    }

    public function test_viewer_ditolak_lewat_url(): void
    {
        $this->actingAs(User::factory()->viewer()->create());

        $this->get(route('tiket.index'))->assertForbidden();
        $this->get(route('tiket.rinci', $this->tiket))->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('tiket.index'));

        $this->assertTrue(LogAktivitas::query()->where('aksi', AksiAktivitas::AksesDitolak)->exists());
    }

    public function test_viewer_tidak_dapat_memuat_komponen(): void
    {
        $this->actingAs(User::factory()->viewer()->create());

        Livewire::test('pages::tiket.index')->assertForbidden();
        Livewire::test('pages::tiket.rinci', ['tiket' => $this->tiket])->assertForbidden();
    }

    /**
     * Request palsu: snapshot komponen milik Operator dikirim ulang oleh Viewer.
     *
     * @param  list<string>  $argumen
     */
    #[DataProvider('semuaAksi')]
    public function test_viewer_tidak_dapat_memanggil_aksi_secara_langsung(string $komponen, string $aksi, array $argumen): void
    {
        $operator = User::factory()->operator()->create();
        $viewer = User::factory()->viewer()->create();
        $sebelum = $this->potret();

        $uji = Livewire::actingAs($operator)->test($komponen, $this->parameter($komponen));

        $this->actingAs($viewer);

        $uji->call($aksi, ...$argumen)->assertForbidden();

        $this->assertSame($sebelum, $this->potret());
    }

    /**
     * Teknisi boleh membuka halaman tiket, sehingga penolakan di sini datang
     * dari authorize() pada tiap aksi pengelolaan.
     *
     * @param  list<string>  $argumen
     */
    #[DataProvider('aksiKelola')]
    public function test_teknisi_tidak_dapat_mengelola_tiket(string $komponen, string $aksi, array $argumen): void
    {
        $this->actingAs($this->teknisi);
        $sebelum = $this->potret();

        Livewire::test($komponen, $this->parameter($komponen))
            ->assertOk()
            ->call($aksi, ...$argumen)
            ->assertForbidden();

        $this->assertSame($sebelum, $this->potret());
    }

    public function test_teknisi_tidak_dapat_membuka_tiket_orang_lain(): void
    {
        $lain = User::factory()->teknisi()->create();
        $belumDitugaskan = Tiket::factory()->create();

        $this->actingAs($lain);

        $this->get(route('tiket.index'))->assertOk()->assertDontSee('Tiket milik teknisi');
        $this->get(route('tiket.rinci', $this->tiket))->assertForbidden();
        $this->get(route('tiket.rinci', $belumDitugaskan))->assertForbidden();

        Livewire::test('pages::tiket.rinci', ['tiket' => $this->tiket])->assertForbidden();
    }

    /**
     * Snapshot rincian tiket milik teknisi lain dikirim ulang: ditolak saat
     * komponen dihidupkan kembali, sebelum aksi apa pun berjalan.
     *
     * @param  list<string>  $argumen
     */
    #[DataProvider('aksiTangani')]
    public function test_teknisi_tidak_dapat_mengerjakan_tiket_orang_lain(string $komponen, string $aksi, array $argumen): void
    {
        $lain = User::factory()->teknisi()->create();
        $sebelum = $this->potret();

        $uji = Livewire::actingAs($this->teknisi)->test($komponen, $this->parameter($komponen));

        $this->actingAs($lain);

        $uji->call($aksi, ...$argumen)->assertForbidden();

        $this->assertSame($sebelum, $this->potret());
    }

    /**
     * Setelah penugasan dipindahkan, teknisi lama kehilangan akses walaupun
     * halamannya masih terbuka.
     */
    public function test_teknisi_kehilangan_akses_setelah_penugasan_dipindahkan(): void
    {
        $uji = Livewire::actingAs($this->teknisi)->test('pages::tiket.rinci', ['tiket' => $this->tiket])->assertOk();

        $this->tiket->update(['teknisi_id' => User::factory()->teknisi()->create()->id]);

        $uji->call('tambahCatatan')->assertForbidden();

        $this->assertSame(0, CatatanTiket::query()->count());
    }

    /**
     * @param  list<string>  $argumen
     */
    #[DataProvider('aksiTangani')]
    public function test_teknisi_tidak_dapat_mengerjakan_tiket_yang_sudah_ditutup(string $komponen, string $aksi, array $argumen): void
    {
        $this->tiket->update(['status' => 'selesai', 'waktu_pemulihan' => now()]);
        $sebelum = $this->potret();

        Livewire::actingAs($this->teknisi)->test($komponen, $this->parameter($komponen))
            ->assertOk()
            ->assertDontSee('data-test="formulir-catatan"', false)
            ->set('isi_catatan', 'Terlambat')
            ->call($aksi, ...$argumen)
            ->assertForbidden();

        $this->assertSame($sebelum, $this->potret());
    }

    public function test_operator_dan_administrator_melihat_semua_tiket(): void
    {
        foreach ([User::factory()->operator()->create(), User::factory()->administrator()->create()] as $pelaku) {
            $this->actingAs($pelaku);

            $this->get(route('tiket.index'))->assertOk()->assertSee('Tiket milik teknisi');
            $this->get(route('tiket.rinci', $this->tiket))->assertOk()->assertSee($this->tiket->nomor)->assertSee('data-test="penugasan"', false);
        }
    }

    public function test_teknisi_melihat_rincian_tiketnya(): void
    {
        $this->actingAs($this->teknisi)
            ->get(route('tiket.rinci', $this->tiket))
            ->assertOk()
            ->assertSee($this->tiket->nomor)
            ->assertSee('data-test="formulir-catatan"', false)
            ->assertDontSee('data-test="penugasan"', false);
    }
}
