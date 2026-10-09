<?php

namespace Tests\Feature\Inventaris;

use App\Enums\Peran;
use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Viewer tidak dapat melihat atau mengubah inventaris; Teknisi dapat melihat
 * tetapi tidak mengubah. Diuji lewat URL dan pemanggilan aksi langsung.
 */
class HakAksesInventarisTest extends TestCase
{
    use RefreshDatabase;

    private Opd $opd;

    private Perangkat $perangkat;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Perangkat::DISK_FOTO);

        $this->opd = Opd::factory()->create(['kode' => 'DINKES', 'nama' => 'Nama Asli']);
        $this->perangkat = Perangkat::factory()->create(['opd_id' => $this->opd->id, 'kode_aset' => 'SW-ASLI', 'nama' => 'Perangkat Asli']);
        $this->perangkat->foto_path = UploadedFile::fake()->image('x.jpg')->store(Perangkat::FOLDER_FOTO, Perangkat::DISK_FOTO);
        $this->perangkat->save();
    }

    private function pastikanTidakBerubah(): void
    {
        $this->assertSame('Nama Asli', $this->opd->refresh()->nama);
        $this->assertSame('Perangkat Asli', $this->perangkat->refresh()->nama);
        $this->assertSame(1, Opd::query()->count());
        $this->assertSame(1, Perangkat::query()->count());
    }

    /**
     * @return array<string, array{string, string, list<string>}>
     */
    public static function aksiPengubah(): array
    {
        return [
            'opd tambah' => ['pages::opd.index', 'tambah', []],
            'opd ubah' => ['pages::opd.index', 'ubah', ['opd']],
            'opd simpan' => ['pages::opd.index', 'simpan', []],
            'opd konfirmasiHapus' => ['pages::opd.index', 'konfirmasiHapus', ['opd']],
            'opd hapus' => ['pages::opd.index', 'hapus', []],
            'perangkat tambah' => ['pages::perangkat.index', 'tambah', []],
            'perangkat ubah' => ['pages::perangkat.index', 'ubah', ['perangkat']],
            'perangkat simpan' => ['pages::perangkat.index', 'simpan', []],
            'perangkat konfirmasiHapus' => ['pages::perangkat.index', 'konfirmasiHapus', ['perangkat']],
            'perangkat hapus' => ['pages::perangkat.index', 'hapus', []],
        ];
    }

    public function test_viewer_ditolak_lewat_url(): void
    {
        $this->actingAs(User::factory()->viewer()->create());

        foreach (['/opd', '/perangkat', '/impor/opd', '/impor/perangkat', route('perangkat.foto', $this->perangkat)] as $url) {
            $this->get($url)->assertForbidden();
        }

        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('opd.index'))->assertDontSee(route('perangkat.index'));
    }

    public function test_tamu_dialihkan_ke_halaman_masuk(): void
    {
        foreach (['/opd', '/perangkat', '/impor/opd', route('perangkat.foto', $this->perangkat)] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_viewer_tidak_dapat_memuat_komponen(): void
    {
        $this->actingAs(User::factory()->viewer()->create());

        Livewire::test('pages::opd.index')->assertForbidden();
        Livewire::test('pages::perangkat.index')->assertForbidden();
        Livewire::test('pages::impor.index', ['jenis' => 'opd'])->assertForbidden();
    }

    /**
     * Request palsu: snapshot komponen milik Operator, berisi formulir ubah
     * yang sudah terisi, dikirim ulang oleh Viewer.
     *
     * @param  list<string>  $argumen
     */
    #[DataProvider('aksiPengubah')]
    public function test_viewer_tidak_dapat_memanggil_aksi_secara_langsung(string $komponen, string $aksi, array $argumen): void
    {
        $operator = User::factory()->operator()->create();
        $viewer = User::factory()->viewer()->create();
        $target = str_contains($komponen, 'opd') ? $this->opd : $this->perangkat;

        $uji = Livewire::actingAs($operator)
            ->test($komponen)
            ->call('ubah', $target->id)
            ->set('nama', 'Diubah Viewer');

        $this->actingAs($viewer);

        $uji->call($aksi, ...array_map(fn () => $target->id, $argumen))->assertForbidden();

        $this->pastikanTidakBerubah();
    }

    /**
     * Teknisi lolos pemeriksaan tingkat komponen (boleh melihat), sehingga
     * penolakan di sini datang dari authorize() pada tiap aksi.
     *
     * @param  list<string>  $argumen
     */
    #[DataProvider('aksiPengubah')]
    public function test_teknisi_dapat_melihat_tetapi_tidak_dapat_mengubah(string $komponen, string $aksi, array $argumen): void
    {
        $teknisi = User::factory()->teknisi()->create();
        $target = str_contains($komponen, 'opd') ? $this->opd : $this->perangkat;

        $this->actingAs($teknisi);

        $uji = Livewire::test($komponen)
            ->assertOk()
            ->assertSee($target->nama)
            ->assertDontSee('data-test="tambah-', false)
            ->set('nama', 'Diubah Teknisi');

        $uji->call($aksi, ...array_map(fn () => $target->id, $argumen))->assertForbidden();

        $this->pastikanTidakBerubah();
    }

    public function test_teknisi_dapat_melihat_rincian_dan_foto_tetapi_tidak_impor(): void
    {
        $this->actingAs(User::factory()->teknisi()->create());

        $this->get('/opd')->assertOk()->assertSee('Nama Asli')->assertDontSee('Impor CSV');
        $this->get('/perangkat')->assertOk()->assertSee('Perangkat Asli')->assertDontSee('Impor CSV');
        $this->get(route('perangkat.foto', $this->perangkat))->assertOk();

        Livewire::test('pages::perangkat.index')->call('lihat', $this->perangkat->id)->assertOk();

        $this->get('/impor/opd')->assertForbidden();
        $this->get('/impor/perangkat')->assertForbidden();
        Livewire::test('pages::impor.index', ['jenis' => 'perangkat'])->assertForbidden();
    }

    /**
     * @return array<string, array{Peran}>
     */
    public static function bukanPengelola(): array
    {
        return ['viewer' => [Peran::Viewer], 'teknisi' => [Peran::Teknisi]];
    }

    #[DataProvider('bukanPengelola')]
    public function test_impor_tidak_dapat_dijalankan_dengan_request_langsung(Peran $peran): void
    {
        $operator = User::factory()->operator()->create();
        $pelaku = User::factory()->peran($peran)->create();

        // Komponen uji tidak dapat dipakai lagi setelah menerima 403, jadi
        // setiap aksi memakai snapshot Operator yang baru.
        foreach (['impor', 'pratinjau'] as $aksi) {
            $uji = Livewire::actingAs($operator)
                ->test('pages::impor.index', ['jenis' => 'opd'])
                ->set('berkas', UploadedFile::fake()->createWithContent('opd.csv', "kode,nama\nBARU,Dinas Baru\nDINKES,Diubah Lewat Impor\n"))
                ->call('pratinjau');

            $this->actingAs($pelaku);

            $uji->call($aksi)->assertForbidden();
        }

        $this->pastikanTidakBerubah();
    }

    #[DataProvider('bukanPengelola')]
    public function test_tidak_ada_rute_http_untuk_mengubah_inventaris(Peran $peran): void
    {
        $this->actingAs(User::factory()->peran($peran)->create());

        foreach (['post', 'put', 'patch', 'delete'] as $metode) {
            foreach (['/opd', "/opd/{$this->opd->id}", '/perangkat', "/perangkat/{$this->perangkat->id}", '/impor/opd'] as $jalur) {
                $status = $this->{$metode}($jalur, ['nama' => 'Diubah'])->getStatusCode();

                $this->assertContains($status, [403, 404, 405], "{$metode} {$jalur} menjawab {$status}");
            }
        }

        $this->pastikanTidakBerubah();
    }

    public function test_operator_dan_administrator_dapat_mengelola(): void
    {
        foreach ([User::factory()->operator()->create(), User::factory()->administrator()->create()] as $pengguna) {
            $this->actingAs($pengguna);

            $this->get('/opd')->assertOk()->assertSee('Impor CSV');
            $this->get('/perangkat')->assertOk()->assertSee('Impor CSV');
            $this->get('/impor/perangkat')->assertOk();
            $this->get(route('dashboard'))->assertSee(route('opd.index'))->assertSee(route('perangkat.index'));
        }
    }
}
