<?php

namespace Tests\Feature\HakAkses;

use App\Enums\Peran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class PengelolaanPenggunaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->administrator()->create();
        $this->actingAs($this->admin);
    }

    public function test_administrator_dapat_membuka_halaman_pengguna(): void
    {
        $lain = User::factory()->operator()->create(['name' => 'Budi Operator']);

        $this->get(route('pengguna.index'))
            ->assertOk()
            ->assertSee('Budi Operator')
            ->assertSee($lain->email)
            ->assertSee('Operator Diskominfo');
    }

    public function test_administrator_dapat_menambah_pengguna_dengan_peran(): void
    {
        Livewire::test('pages::pengguna.index')
            ->call('tambah')
            ->set('name', 'Siti Teknisi')
            ->set('email', 'siti@example.test')
            ->set('peran', 'teknisi')
            ->set('password', 'rahasia-panjang-123')
            ->set('password_confirmation', 'rahasia-panjang-123')
            ->call('simpan')
            ->assertHasNoErrors();

        $pengguna = User::query()->where('email', 'siti@example.test')->firstOrFail();

        $this->assertSame(Peran::Teknisi, $pengguna->peran);
        $this->assertTrue(Hash::check('rahasia-panjang-123', $pengguna->password));
    }

    public function test_peran_yang_tidak_dikenal_ditolak(): void
    {
        Livewire::test('pages::pengguna.index')
            ->call('tambah')
            ->set('name', 'Siti')
            ->set('email', 'siti@example.test')
            ->set('peran', 'superadmin')
            ->set('password', 'rahasia-panjang-123')
            ->set('password_confirmation', 'rahasia-panjang-123')
            ->call('simpan')
            ->assertHasErrors(['peran']);

        $this->assertDatabaseMissing('users', ['email' => 'siti@example.test']);
    }

    public function test_administrator_dapat_mengubah_data_dan_peran_pengguna_lain(): void
    {
        $target = User::factory()->viewer()->create();

        Livewire::test('pages::pengguna.index')
            ->call('ubah', $target->id)
            ->assertSet('email', $target->email)
            ->set('name', 'Nama Baru')
            ->set('peran', 'operator')
            ->call('simpan')
            ->assertHasNoErrors();

        $target->refresh();

        $this->assertSame('Nama Baru', $target->name);
        $this->assertSame(Peran::Operator, $target->peran);
    }

    public function test_mengubah_pengguna_tidak_mengubah_kata_sandinya(): void
    {
        $target = User::factory()->viewer()->create();
        $hashLama = $target->password;

        Livewire::test('pages::pengguna.index')
            ->call('ubah', $target->id)
            ->set('password', 'disisipkan-penyerang-123')
            ->set('password_confirmation', 'disisipkan-penyerang-123')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame($hashLama, $target->refresh()->password);
    }

    public function test_administrator_tidak_dapat_mengubah_peran_akun_sendiri(): void
    {
        Livewire::test('pages::pengguna.index')
            ->call('ubah', $this->admin->id)
            ->set('peran', 'viewer')
            ->call('simpan')
            ->assertHasErrors(['peran']);

        $this->assertSame(Peran::Administrator, $this->admin->refresh()->peran);
    }

    public function test_administrator_tidak_dapat_menghapus_akun_sendiri(): void
    {
        Livewire::test('pages::pengguna.index')
            ->call('konfirmasiHapus', $this->admin->id)
            ->assertForbidden();

        $this->assertNotNull($this->admin->fresh());
    }

    public function test_administrator_dapat_mengatur_ulang_kata_sandi(): void
    {
        $target = User::factory()->teknisi()->create();

        Livewire::test('pages::pengguna.index')
            ->call('bukaKataSandi', $target->id)
            ->set('password', 'kata-sandi-baru-456')
            ->set('password_confirmation', 'kata-sandi-baru-456')
            ->call('simpanKataSandi')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('kata-sandi-baru-456', $target->refresh()->password));
    }

    public function test_administrator_dapat_menghapus_pengguna_lain(): void
    {
        $target = User::factory()->viewer()->create();

        Livewire::test('pages::pengguna.index')
            ->call('konfirmasiHapus', $target->id)
            ->call('hapus')
            ->assertHasNoErrors();

        $this->assertNull($target->fresh());
    }

    public function test_id_target_tidak_dapat_diganti_dari_browser(): void
    {
        $target = User::factory()->viewer()->create();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Livewire::test('pages::pengguna.index')
            ->call('konfirmasiHapus', $target->id)
            ->set('idTarget', $this->admin->id);
    }

    public function test_pencarian_menyaring_daftar(): void
    {
        User::factory()->create(['name' => 'Ahmad Jaringan', 'email' => 'ahmad@example.test']);
        User::factory()->create(['name' => 'Rina Kabel', 'email' => 'rina@example.test']);

        Livewire::test('pages::pengguna.index')
            ->set('cari', 'ahmad')
            ->assertSee('ahmad@example.test')
            ->assertDontSee('rina@example.test');
    }

    public function test_pengguna_tidak_lagi_dapat_menghapus_akunnya_sendiri_dari_pengaturan(): void
    {
        $this->get(route('profile.edit'))
            ->assertOk()
            ->assertDontSee('data-test="delete-user-button"', false);
    }
}
