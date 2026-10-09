<?php

namespace Tests\Feature\Inventaris;

use App\Enums\AksiAktivitas;
use App\Models\LogAktivitas;
use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class OpdTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->operator()->create();
        $this->actingAs($this->operator);
    }

    public function test_halaman_menampilkan_daftar_opd(): void
    {
        $opd = Opd::factory()->create(['kode' => 'DINKES', 'nama' => 'Dinas Kesehatan']);
        Perangkat::factory()->switchUtama()->create(['opd_id' => $opd->id, 'kode_aset' => 'SW-DINKES-01']);

        $this->get(route('opd.index'))
            ->assertOk()
            ->assertSee('DINKES')
            ->assertSee('Dinas Kesehatan')
            ->assertSee('Utama: SW-DINKES-01');
    }

    public function test_operator_dapat_menambah_opd(): void
    {
        Livewire::test('pages::opd.index')
            ->call('tambah')
            ->set('kode', ' dinkes ')
            ->set('nama', 'Dinas Kesehatan')
            ->set('alamat', 'Jl. Contoh No. 5')
            ->set('lintang', '-7,3623')
            ->set('bujur', '109.9114')
            ->set('kontak_teknis_nama', 'Budi')
            ->set('kontak_teknis_telepon', '0286 321000')
            ->set('kontak_teknis_email', 'budi@example.test')
            ->call('simpan')
            ->assertHasNoErrors();

        $opd = Opd::query()->where('kode', 'DINKES')->firstOrFail();

        $this->assertSame('Dinas Kesehatan', $opd->nama);
        $this->assertSame('-7.362300', $opd->lintang);
        $this->assertSame('109.911400', $opd->bujur);
        $this->assertTrue($opd->aktif);
        $this->assertFalse($opd->simulasi);

        $log = LogAktivitas::query()->where('aksi', AksiAktivitas::OpdDibuat)->firstOrFail();
        $this->assertSame($this->operator->id, $log->pelaku_id);
        $this->assertSame('DINKES — Dinas Kesehatan', $log->subjek_label);
    }

    public function test_validasi_opd(): void
    {
        Opd::factory()->create(['kode' => 'SETDA']);

        Livewire::test('pages::opd.index')
            ->call('tambah')
            ->set('kode', 'setda')
            ->set('nama', '')
            ->set('lintang', '-97')
            ->set('bujur', '')
            ->set('kontak_teknis_telepon', 'bukan nomor')
            ->set('kontak_teknis_email', 'bukan-email')
            ->call('simpan')
            ->assertHasErrors(['kode', 'nama', 'lintang', 'bujur', 'kontak_teknis_telepon', 'kontak_teknis_email']);

        Livewire::test('pages::opd.index')
            ->call('tambah')
            ->set('kode', 'ada spasi')
            ->set('nama', 'Sah')
            ->call('simpan')
            ->assertHasErrors(['kode']);

        $this->assertSame(1, Opd::query()->count());
    }

    public function test_operator_dapat_mengubah_dan_menonaktifkan_opd(): void
    {
        $opd = Opd::factory()->create(['nama' => 'Nama Lama']);

        Livewire::test('pages::opd.index')
            ->call('ubah', $opd->id)
            ->assertSet('kode', $opd->kode)
            ->set('nama', 'Nama Baru')
            ->set('aktif', false)
            ->call('simpan')
            ->assertHasNoErrors();

        $opd->refresh();

        $this->assertSame('Nama Baru', $opd->nama);
        $this->assertFalse($opd->aktif);

        $log = LogAktivitas::query()->where('aksi', AksiAktivitas::OpdDiubah)->firstOrFail();
        $this->assertSame(['dari' => 'Nama Lama', 'menjadi' => 'Nama Baru'], $log->rincian['nama'] ?? null);
    }

    public function test_opd_yang_masih_memiliki_perangkat_tidak_dapat_dihapus(): void
    {
        $opd = Opd::factory()->create();
        Perangkat::factory()->create(['opd_id' => $opd->id]);

        Livewire::test('pages::opd.index')
            ->call('konfirmasiHapus', $opd->id)
            ->call('hapus')
            ->assertHasErrors(['hapus']);

        $this->assertNotNull($opd->fresh());
    }

    public function test_opd_tanpa_perangkat_dapat_dihapus(): void
    {
        $opd = Opd::factory()->create();

        Livewire::test('pages::opd.index')
            ->call('konfirmasiHapus', $opd->id)
            ->call('hapus')
            ->assertHasNoErrors();

        $this->assertNull($opd->fresh());
        $this->assertTrue(LogAktivitas::query()->where('aksi', AksiAktivitas::OpdDihapus)->exists());
    }

    public function test_pencarian_dan_filter_status(): void
    {
        Opd::factory()->create(['kode' => 'DINKES', 'nama' => 'Dinas Kesehatan']);
        Opd::factory()->create(['kode' => 'DISDIK', 'nama' => 'Dinas Pendidikan']);
        Opd::factory()->nonaktif()->create(['kode' => 'ARSIP', 'nama' => 'Dinas Kearsipan']);

        Livewire::test('pages::opd.index')
            ->set('cari', 'kesehat')
            ->assertSee('DINKES')
            ->assertDontSee('DISDIK')
            ->set('cari', 'disdik')
            ->assertSee('Dinas Pendidikan')
            ->assertDontSee('Dinas Kesehatan')
            ->set('cari', '')
            ->set('status', 'nonaktif')
            ->assertSee('ARSIP')
            ->assertDontSee('DINKES')
            ->set('status', 'aktif')
            ->assertSee('DINKES')
            ->assertDontSee('ARSIP');
    }

    public function test_daftar_dipaginasi(): void
    {
        foreach (range(1, 17) as $i) {
            Opd::factory()->create(['kode' => sprintf('OPD-%02d', $i), 'nama' => sprintf('Dinas %02d', $i)]);
        }

        Livewire::test('pages::opd.index')
            ->assertSee('OPD-15')
            ->assertDontSee('OPD-16')
            ->call('gotoPage', 2)
            ->assertSee('OPD-16')
            ->assertDontSee('OPD-15');
    }

    public function test_opd_simulasi_selalu_berlabel(): void
    {
        $opd = Opd::factory()->simulasi()->create(['nama' => 'Dinas Contoh']);

        $this->assertSame('[SIMULASI] Dinas Contoh', $opd->nama);

        $this->get(route('opd.index'))->assertSee('data-test="lencana-simulasi"', false);

        $this->expectException(QueryException::class);

        DB::table('opd')->where('id', $opd->id)->update(['nama' => 'Tanpa Label']);
    }

    public function test_database_menolak_koordinat_setengah_terisi(): void
    {
        $opd = Opd::factory()->create();

        $this->expectException(QueryException::class);

        DB::table('opd')->where('id', $opd->id)->update(['lintang' => -7.3, 'bujur' => null]);
    }
}
