<?php

namespace Tests\Feature\Console;

use App\Enums\Peran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BuatPenggunaTest extends TestCase
{
    use RefreshDatabase;

    public function test_pengguna_dapat_dibuat_lewat_perintah_konsol(): void
    {
        $this->artisan('pengguna:buat', ['--nama' => 'Admin Diskominfo', '--email' => 'admin@example.test', '--peran' => 'administrator'])
            ->expectsQuestion('Kata sandi', 'rahasia-panjang-123')
            ->expectsQuestion('Ulangi kata sandi', 'rahasia-panjang-123')
            ->assertSuccessful();

        $pengguna = User::query()->where('email', 'admin@example.test')->firstOrFail();

        $this->assertSame('Admin Diskominfo', $pengguna->name);
        $this->assertSame(Peran::Administrator, $pengguna->peran);
        $this->assertTrue(Hash::check('rahasia-panjang-123', $pengguna->password));
    }

    public function test_pengguna_tidak_dibuat_jika_konfirmasi_kata_sandi_berbeda(): void
    {
        $this->artisan('pengguna:buat', ['--nama' => 'Admin Diskominfo', '--email' => 'admin@example.test', '--peran' => 'administrator'])
            ->expectsQuestion('Kata sandi', 'rahasia-panjang-123')
            ->expectsQuestion('Ulangi kata sandi', 'berbeda-456')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_email_yang_sudah_terpakai_ditolak(): void
    {
        User::factory()->create(['email' => 'admin@example.test']);

        $this->artisan('pengguna:buat', ['--nama' => 'Admin Lain', '--email' => 'admin@example.test', '--peran' => 'administrator'])
            ->expectsQuestion('Kata sandi', 'rahasia-panjang-123')
            ->expectsQuestion('Ulangi kata sandi', 'rahasia-panjang-123')
            ->assertFailed();

        $this->assertDatabaseCount('users', 1);
    }

    public function test_peran_yang_tidak_dikenal_ditolak(): void
    {
        $this->artisan('pengguna:buat', ['--nama' => 'Admin', '--email' => 'admin@example.test', '--peran' => 'superadmin'])
            ->expectsQuestion('Kata sandi', 'rahasia-panjang-123')
            ->expectsQuestion('Ulangi kata sandi', 'rahasia-panjang-123')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_peran_dapat_diubah_lewat_perintah_konsol(): void
    {
        $pengguna = User::factory()->viewer()->create(['email' => 'budi@example.test']);

        $this->artisan('pengguna:peran', ['email' => 'budi@example.test', 'peran' => 'administrator'])->assertSuccessful();
        $this->assertSame(Peran::Administrator, $pengguna->refresh()->peran);

        $this->artisan('pengguna:peran', ['email' => 'budi@example.test', 'peran' => 'superadmin'])->assertFailed();
        $this->artisan('pengguna:peran', ['email' => 'tidak-ada@example.test', 'peran' => 'viewer'])->assertFailed();
        $this->assertSame(Peran::Administrator, $pengguna->refresh()->peran);
    }

    public function test_pendaftaran_mandiri_tidak_tersedia(): void
    {
        $this->get('/register')->assertNotFound();
    }
}
