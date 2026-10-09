<?php

namespace Tests\Feature;

use App\Enums\AksiAktivitas;
use App\Models\LogAktivitas;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

class LogAktivitasTest extends TestCase
{
    use RefreshDatabase;

    private function logTerakhir(AksiAktivitas $aksi): LogAktivitas
    {
        return LogAktivitas::query()->where('aksi', $aksi)->orderByDesc('id')->firstOrFail();
    }

    public function test_masuk_dan_keluar_dicatat(): void
    {
        $pengguna = User::factory()->create();

        $this->post(route('login.store'), ['email' => $pengguna->email, 'password' => 'password']);

        $masuk = $this->logTerakhir(AksiAktivitas::Masuk);
        $this->assertSame($pengguna->id, $masuk->pelaku_id);
        $this->assertSame('viewer', $masuk->pelaku_peran);
        $this->assertSame('127.0.0.1', $masuk->alamat_ip);

        $this->post(route('logout'));

        $this->assertSame($pengguna->id, $this->logTerakhir(AksiAktivitas::Keluar)->pelaku_id);
    }

    public function test_gagal_masuk_dicatat_tanpa_kata_sandi(): void
    {
        $pengguna = User::factory()->create();

        $this->post(route('login.store'), ['email' => $pengguna->email, 'password' => 'tebakan-salah']);

        $log = $this->logTerakhir(AksiAktivitas::GagalMasuk);

        $this->assertNull($log->pelaku_id);
        $this->assertSame($pengguna->email, $log->rincian['email'] ?? null);
        $this->assertStringNotContainsString('tebakan-salah', (string) json_encode($log->getAttributes()));
    }

    public function test_perubahan_pengguna_oleh_administrator_dicatat(): void
    {
        $admin = User::factory()->administrator()->create(['name' => 'Admin Utama']);
        $target = User::factory()->viewer()->create(['name' => 'Nama Lama']);

        $this->actingAs($admin);

        Livewire::test('pages::pengguna.index')
            ->call('ubah', $target->id)
            ->set('name', 'Nama Baru')
            ->set('peran', 'operator')
            ->call('simpan')
            ->call('bukaKataSandi', $target->id)
            ->set('password', 'kata-sandi-baru-456')
            ->set('password_confirmation', 'kata-sandi-baru-456')
            ->call('simpanKataSandi')
            ->call('konfirmasiHapus', $target->id)
            ->call('hapus');

        $diubah = $this->logTerakhir(AksiAktivitas::PenggunaDiubah);
        $this->assertSame($admin->id, $diubah->pelaku_id);
        $this->assertSame('Admin Utama', $diubah->pelaku_nama);
        $this->assertSame($target->email, $diubah->subjek_label);
        $this->assertSame(['dari' => 'Nama Lama', 'menjadi' => 'Nama Baru'], $diubah->rincian['name'] ?? null);

        $peran = $this->logTerakhir(AksiAktivitas::PeranDiubah);
        $this->assertSame(['dari' => 'viewer', 'menjadi' => 'operator'], $peran->rincian);

        $kataSandi = $this->logTerakhir(AksiAktivitas::KataSandiDiubah);
        $this->assertSame($admin->id, $kataSandi->pelaku_id);
        $this->assertNull($kataSandi->rincian);

        $dihapus = $this->logTerakhir(AksiAktivitas::PenggunaDihapus);
        $this->assertSame($admin->id, $dihapus->pelaku_id);
        $this->assertSame($target->email, $dihapus->subjek_label);
    }

    public function test_pembuatan_pengguna_dicatat(): void
    {
        $admin = User::factory()->administrator()->create();

        $this->actingAs($admin);

        Livewire::test('pages::pengguna.index')
            ->call('tambah')
            ->set('name', 'Siti')
            ->set('email', 'siti@example.test')
            ->set('peran', 'teknisi')
            ->set('password', 'rahasia-panjang-123')
            ->set('password_confirmation', 'rahasia-panjang-123')
            ->call('simpan');

        $log = $this->logTerakhir(AksiAktivitas::PenggunaDibuat);

        $this->assertSame($admin->id, $log->pelaku_id);
        $this->assertSame('siti@example.test', $log->subjek_label);
        $this->assertSame(['peran' => 'teknisi'], $log->rincian);
    }

    public function test_perubahan_profil_dan_kata_sandi_sendiri_dicatat(): void
    {
        $pengguna = User::factory()->viewer()->create(['name' => 'Nama Lama']);

        $this->actingAs($pengguna);

        Livewire::test('pages::settings.profile')
            ->set('name', 'Nama Baru')
            ->call('updateProfileInformation');

        $this->assertSame($pengguna->id, $this->logTerakhir(AksiAktivitas::PenggunaDiubah)->pelaku_id);

        Livewire::test('pages::settings.security')
            ->set('current_password', 'password')
            ->set('password', 'kata-sandi-baru-456')
            ->set('password_confirmation', 'kata-sandi-baru-456')
            ->call('updatePassword');

        $this->assertSame($pengguna->id, $this->logTerakhir(AksiAktivitas::KataSandiDiubah)->pelaku_id);
    }

    public function test_akses_yang_ditolak_dicatat(): void
    {
        $viewer = User::factory()->viewer()->create();

        $this->actingAs($viewer)->get('/pengguna')->assertForbidden();

        $log = $this->logTerakhir(AksiAktivitas::AksesDitolak);

        $this->assertSame($viewer->id, $log->pelaku_id);
        $this->assertSame('GET', $log->rincian['metode'] ?? null);
        $this->assertSame('/pengguna', $log->rincian['jalur'] ?? null);
    }

    public function test_log_tetap_ada_setelah_pelakunya_dihapus(): void
    {
        $admin = User::factory()->administrator()->create();
        $pelaku = User::factory()->viewer()->create(['name' => 'Akan Dihapus']);

        $this->actingAs($pelaku)->get('/pengguna')->assertForbidden();
        $this->actingAs($admin);
        $pelaku->delete();

        $log = $this->logTerakhir(AksiAktivitas::AksesDitolak);

        $this->assertNull($log->pelaku_id);
        $this->assertSame('Akan Dihapus', $log->pelaku_nama);
    }

    public function test_log_tidak_dapat_diubah_atau_dihapus(): void
    {
        User::factory()->create();
        $log = LogAktivitas::query()->firstOrFail();

        try {
            $log->update(['pelaku_nama' => 'Dipalsukan']);
            $this->fail('Log seharusnya tidak dapat diubah.');
        } catch (LogicException) {
            // Diharapkan.
        }

        $this->expectException(LogicException::class);

        $log->delete();
    }

    public function test_administrator_dapat_melihat_dan_menyaring_log(): void
    {
        $admin = User::factory()->administrator()->create();
        $viewer = User::factory()->viewer()->create(['name' => 'Vina Viewer']);

        $this->actingAs($viewer)->get('/pengguna');
        $this->actingAs($admin);

        $this->get(route('log-aktivitas.index'))
            ->assertOk()
            ->assertSee('Akses ditolak')
            ->assertSee('Vina Viewer');

        Livewire::test('pages::log-aktivitas.index')
            ->set('aksi', AksiAktivitas::AksesDitolak->value)
            ->assertSee('/pengguna')
            ->assertDontSee($viewer->email)
            ->set('aksi', AksiAktivitas::Masuk->value)
            ->assertSee('Belum ada aktivitas yang cocok.');
    }
}
