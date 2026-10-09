<?php

namespace Tests\Feature\HakAkses;

use App\Enums\Izin;
use App\Enums\Peran;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MatriksIzinTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{Peran, list<Izin>}>
     */
    public static function matriks(): array
    {
        return [
            'administrator' => [Peran::Administrator, Izin::cases()],
            'operator' => [Peran::Operator, [
                Izin::KelolaAset, Izin::KelolaEndpoint, Izin::KelolaTiket,
                Izin::LihatAset, Izin::LihatDashboard, Izin::LihatLaporan,
            ]],
            'teknisi' => [Peran::Teknisi, [Izin::LihatAset, Izin::TanganiTiket, Izin::LihatDashboard]],
            'viewer' => [Peran::Viewer, [Izin::LihatDashboard, Izin::LihatLaporan]],
        ];
    }

    /**
     * @param  list<Izin>  $diizinkan
     */
    #[DataProvider('matriks')]
    public function test_gate_mengikuti_matriks_peran(Peran $peran, array $diizinkan): void
    {
        $pengguna = User::factory()->peran($peran)->create();

        foreach (Izin::cases() as $izin) {
            $this->assertSame(
                in_array($izin, $diizinkan, true),
                $pengguna->can($izin->value),
                "{$peran->value} terhadap {$izin->value}",
            );
        }
    }

    public function test_pengguna_baru_berperan_viewer_secara_bawaan(): void
    {
        $pengguna = User::create(['name' => 'Baru', 'email' => 'baru@example.test', 'password' => 'rahasia-panjang-123']);

        $this->assertSame(Peran::Viewer, $pengguna->refresh()->peran);
    }

    public function test_peran_tidak_dapat_diisi_lewat_mass_assignment(): void
    {
        $pengguna = User::factory()->viewer()->create();

        $pengguna->fill(['peran' => Peran::Administrator->value])->save();

        $this->assertSame(Peran::Viewer, $pengguna->refresh()->peran);
    }

    public function test_database_menolak_peran_yang_tidak_dikenal(): void
    {
        $pengguna = User::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $pengguna->id)->update(['peran' => 'superadmin']);
    }

    /**
     * @param  list<Izin>  $diizinkan
     */
    #[DataProvider('matriks')]
    public function test_semua_peran_dapat_membuka_dashboard(Peran $peran, array $diizinkan): void
    {
        $this->actingAs(User::factory()->peran($peran)->create())
            ->get(route('dashboard'))
            ->assertOk();
    }
}
