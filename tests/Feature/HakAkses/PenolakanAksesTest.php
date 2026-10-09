<?php

namespace Tests\Feature\HakAkses;

use App\Enums\Peran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\PublicPropertyNotFoundException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Viewer (dan peran lain selain Administrator) tidak dapat mengubah data,
 * baik lewat URL maupun dengan mengirim request langsung ke komponen.
 */
class PenolakanAksesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{Peran}>
     */
    public static function bukanAdministrator(): array
    {
        return [
            'viewer' => [Peran::Viewer],
            'teknisi' => [Peran::Teknisi],
            'operator' => [Peran::Operator],
        ];
    }

    /**
     * @return array<string, array{string, list<mixed>}>
     */
    public static function aksiPengubah(): array
    {
        return [
            'tambah' => ['tambah', []],
            'ubah' => ['ubah', ['target']],
            'simpan' => ['simpan', []],
            'bukaKataSandi' => ['bukaKataSandi', ['target']],
            'simpanKataSandi' => ['simpanKataSandi', []],
            'konfirmasiHapus' => ['konfirmasiHapus', ['target']],
            'hapus' => ['hapus', []],
        ];
    }

    #[DataProvider('bukanAdministrator')]
    public function test_halaman_administrasi_ditolak_lewat_url(Peran $peran): void
    {
        $this->actingAs(User::factory()->peran($peran)->create());

        $this->get('/pengguna')->assertForbidden();
        $this->get('/log-aktivitas')->assertForbidden();
    }

    public function test_tamu_dialihkan_ke_halaman_masuk(): void
    {
        $this->get('/pengguna')->assertRedirect(route('login'));
        $this->get('/log-aktivitas')->assertRedirect(route('login'));
    }

    #[DataProvider('bukanAdministrator')]
    public function test_komponen_pengguna_tidak_dapat_dimuat(Peran $peran): void
    {
        $this->actingAs(User::factory()->peran($peran)->create());

        Livewire::test('pages::pengguna.index')->assertForbidden();
        Livewire::test('pages::log-aktivitas.index')->assertForbidden();
    }

    /**
     * Mensimulasikan request yang dipalsukan: snapshot komponen yang sah
     * (didapat saat masih Administrator) dikirim ulang oleh seorang Viewer.
     *
     * @param  list<mixed>  $argumen
     */
    #[DataProvider('aksiPengubah')]
    public function test_viewer_tidak_dapat_memanggil_aksi_secara_langsung(string $aksi, array $argumen): void
    {
        $admin = User::factory()->administrator()->create();
        $viewer = User::factory()->viewer()->create();
        $target = User::factory()->operator()->create(['name' => 'Target Asli']);
        $hashLama = $target->password;

        $argumen = array_map(fn ($nilai) => $nilai === 'target' ? $target->id : $nilai, $argumen);

        $komponen = Livewire::actingAs($admin)
            ->test('pages::pengguna.index')
            ->call('ubah', $target->id)
            ->set('name', 'Diubah Viewer')
            ->set('peran', 'administrator')
            ->set('password', 'disisipkan-penyerang-123')
            ->set('password_confirmation', 'disisipkan-penyerang-123');

        $this->actingAs($viewer);

        $komponen->call($aksi, ...$argumen)->assertForbidden();

        $target->refresh();

        $this->assertSame('Target Asli', $target->name);
        $this->assertSame(Peran::Operator, $target->peran);
        $this->assertSame($hashLama, $target->password);
        $this->assertSame(3, User::query()->count());
        $this->assertSame(Peran::Viewer, $viewer->refresh()->peran);
    }

    public function test_viewer_tidak_dapat_mengubah_properti_komponen_secara_langsung(): void
    {
        $admin = User::factory()->administrator()->create();
        $viewer = User::factory()->viewer()->create();

        $komponen = Livewire::actingAs($admin)->test('pages::pengguna.index');

        $this->actingAs($viewer);

        $komponen->set('cari', 'apa saja')->assertForbidden();
    }

    #[DataProvider('bukanAdministrator')]
    public function test_tidak_ada_rute_http_untuk_mengubah_pengguna(Peran $peran): void
    {
        $pelaku = User::factory()->peran($peran)->create();
        $target = User::factory()->operator()->create(['name' => 'Target Asli']);

        $this->actingAs($pelaku);

        $muatan = ['name' => 'Diubah', 'peran' => 'administrator', 'password' => 'x', 'password_confirmation' => 'x'];

        foreach (['post', 'put', 'patch', 'delete'] as $metode) {
            foreach (['/pengguna', "/pengguna/{$target->id}"] as $jalur) {
                $status = $this->{$metode}($jalur, $muatan)->getStatusCode();

                $this->assertContains($status, [403, 404, 405], "{$metode} {$jalur} menjawab {$status}");
            }
        }

        $this->assertSame('Target Asli', $target->refresh()->name);
        $this->assertSame(Peran::Operator, $target->peran);
        $this->assertSame(2, User::query()->count());
    }

    public function test_viewer_tidak_dapat_menaikkan_perannya_lewat_halaman_profil(): void
    {
        $viewer = User::factory()->viewer()->create();

        $this->actingAs($viewer);

        Livewire::test('pages::settings.profile')
            ->set('name', 'Nama Sendiri')
            ->call('updateProfileInformation')
            ->assertHasNoErrors();

        $this->assertSame('Nama Sendiri', $viewer->refresh()->name);
        $this->assertSame(Peran::Viewer, $viewer->peran);

        $this->expectException(PublicPropertyNotFoundException::class);

        Livewire::test('pages::settings.profile')->set('peran', 'administrator');
    }

    public function test_menu_administrasi_tidak_ditampilkan_kepada_viewer(): void
    {
        $this->actingAs(User::factory()->viewer()->create())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee(route('pengguna.index'))
            ->assertDontSee(route('log-aktivitas.index'));

        $this->actingAs(User::factory()->administrator()->create())
            ->get(route('dashboard'))
            ->assertSee(route('pengguna.index'))
            ->assertSee(route('log-aktivitas.index'));
    }
}
