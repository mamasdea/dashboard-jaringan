<?php

namespace Tests\Feature\Inventaris;

use App\Enums\AksiAktivitas;
use App\Enums\JenisPerangkat;
use App\Enums\PenempatanPerangkat;
use App\Models\LogAktivitas;
use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class PerangkatTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private Opd $opd;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Perangkat::DISK_FOTO);

        $this->operator = User::factory()->operator()->create();
        $this->opd = Opd::factory()->create(['kode' => 'DINKES', 'nama' => 'Dinas Kesehatan']);
        $this->actingAs($this->operator);
    }

    private function formulirTerisi(): Testable
    {
        return Livewire::test('pages::perangkat.index')
            ->call('tambah')
            ->set('opd_id', (string) $this->opd->id)
            ->set('kode_aset', 'sw-dinkes-01')
            ->set('nama', 'Switch Utama Dinkes')
            ->set('jenis', 'switch')
            ->set('merek', 'Merek Uji')
            ->set('model', 'M-24')
            ->set('nomor_seri', 'SN123')
            ->set('ip_manajemen', '198.51.100.10')
            ->set('port_uplink', 'GigabitEthernet0/1');
    }

    public function test_operator_dapat_menambah_perangkat(): void
    {
        $this->formulirTerisi()
            ->set('penempatan', 'diskominfo')
            ->set('lokasi', 'Ruang NOC')
            ->set('monitoring_aktif', true)
            ->set('switch_utama', true)
            ->set('catatan', 'Catatan uji')
            ->call('simpan')
            ->assertHasNoErrors();

        $perangkat = Perangkat::query()->where('kode_aset', 'SW-DINKES-01')->firstOrFail();

        $this->assertSame($this->opd->id, $perangkat->opd_id);
        $this->assertSame(PenempatanPerangkat::Diskominfo, $perangkat->penempatan);
        $this->assertSame(JenisPerangkat::Switch, $perangkat->jenis);
        $this->assertSame('198.51.100.10', $perangkat->ip_manajemen);
        $this->assertSame('GigabitEthernet0/1', $perangkat->port_uplink);
        $this->assertTrue($perangkat->monitoring_aktif);
        $this->assertTrue($perangkat->switch_utama);
        $this->assertFalse($perangkat->simulasi);

        $log = LogAktivitas::query()->where('aksi', AksiAktivitas::PerangkatDibuat)->firstOrFail();
        $this->assertSame($this->operator->id, $log->pelaku_id);
    }

    public function test_validasi_perangkat(): void
    {
        Perangkat::factory()->create(['opd_id' => $this->opd->id, 'kode_aset' => 'SW-LAMA', 'ip_manajemen' => '198.51.100.99']);

        Livewire::test('pages::perangkat.index')
            ->call('tambah')
            ->set('opd_id', '999999')
            ->set('kode_aset', 'sw-lama')
            ->set('nama', '')
            ->set('jenis', 'kulkas')
            ->set('penempatan', 'bulan')
            ->set('ip_manajemen', '300.1.1.1')
            ->call('simpan')
            ->assertHasErrors(['opd_id', 'kode_aset', 'nama', 'jenis', 'penempatan', 'ip_manajemen']);

        $this->formulirTerisi()
            ->set('ip_manajemen', '198.51.100.99')
            ->call('simpan')
            ->assertHasErrors(['ip_manajemen']);

        $this->assertSame(1, Perangkat::query()->count());
    }

    public function test_monitoring_membutuhkan_ip_manajemen(): void
    {
        $this->formulirTerisi()
            ->set('ip_manajemen', '')
            ->set('monitoring_aktif', true)
            ->call('simpan')
            ->assertHasErrors(['ip_manajemen']);

        $perangkat = Perangkat::factory()->create(['ip_manajemen' => '198.51.100.20']);

        $this->expectException(QueryException::class);

        Perangkat::query()->whereKey($perangkat->id)->update(['monitoring_aktif' => true, 'ip_manajemen' => null]);
    }

    public function test_penanda_switch_utama_hanya_untuk_switch(): void
    {
        $this->formulirTerisi()
            ->set('jenis', 'router')
            ->set('switch_utama', true)
            ->call('simpan')
            ->assertHasErrors(['switch_utama']);

        $this->assertSame(0, Perangkat::query()->count());
    }

    public function test_satu_opd_memiliki_beberapa_perangkat_dengan_satu_switch_utama(): void
    {
        $lama = Perangkat::factory()->switchUtama()->create(['opd_id' => $this->opd->id]);
        $opdLain = Opd::factory()->create();
        $utamaOpdLain = Perangkat::factory()->switchUtama()->create(['opd_id' => $opdLain->id]);

        $this->formulirTerisi()
            ->set('switch_utama', true)
            ->call('simpan')
            ->assertHasNoErrors();

        $baru = Perangkat::query()->where('kode_aset', 'SW-DINKES-01')->firstOrFail();

        $this->assertSame(2, $this->opd->perangkat()->count());
        $this->assertTrue($baru->switch_utama);
        $this->assertFalse($lama->refresh()->switch_utama);
        $this->assertTrue($utamaOpdLain->refresh()->switch_utama);
        $this->assertTrue($this->opd->switchUtama()->firstOrFail()->is($baru));

        $this->expectException(QueryException::class);

        Perangkat::query()->whereKey($lama->id)->update(['switch_utama' => true]);
    }

    public function test_operator_dapat_mengubah_perangkat(): void
    {
        $perangkat = Perangkat::factory()->create(['opd_id' => $this->opd->id, 'nama' => 'Nama Lama']);
        $opdBaru = Opd::factory()->create();

        Livewire::test('pages::perangkat.index')
            ->call('ubah', $perangkat->id)
            ->assertSet('kode_aset', $perangkat->kode_aset)
            ->set('nama', 'Nama Baru')
            ->set('opd_id', (string) $opdBaru->id)
            ->call('simpan')
            ->assertHasNoErrors();

        $perangkat->refresh();

        $this->assertSame('Nama Baru', $perangkat->nama);
        $this->assertSame($opdBaru->id, $perangkat->opd_id);
        $this->assertTrue(LogAktivitas::query()->where('aksi', AksiAktivitas::PerangkatDiubah)->exists());
    }

    public function test_foto_disimpan_di_disk_privat_dan_dapat_diganti_serta_dihapus(): void
    {
        $this->formulirTerisi()
            ->set('foto', UploadedFile::fake()->image('switch.jpg', 800, 600))
            ->call('simpan')
            ->assertHasNoErrors();

        $perangkat = Perangkat::query()->where('kode_aset', 'SW-DINKES-01')->firstOrFail();
        $fotoPertama = $perangkat->foto_path;

        $this->assertNotNull($fotoPertama);
        $this->assertStringStartsWith(Perangkat::FOLDER_FOTO.'/', $fotoPertama);
        Storage::disk(Perangkat::DISK_FOTO)->assertExists($fotoPertama);
        $this->get(route('perangkat.foto', $perangkat))->assertOk();

        Livewire::test('pages::perangkat.index')
            ->call('ubah', $perangkat->id)
            ->set('foto', UploadedFile::fake()->image('baru.png'))
            ->call('simpan')
            ->assertHasNoErrors();

        $fotoKedua = $perangkat->refresh()->foto_path;

        $this->assertNotSame($fotoPertama, $fotoKedua);
        Storage::disk(Perangkat::DISK_FOTO)->assertMissing($fotoPertama);
        Storage::disk(Perangkat::DISK_FOTO)->assertExists((string) $fotoKedua);

        Livewire::test('pages::perangkat.index')
            ->call('ubah', $perangkat->id)
            ->set('hapusFoto', true)
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertNull($perangkat->refresh()->foto_path);
        Storage::disk(Perangkat::DISK_FOTO)->assertMissing((string) $fotoKedua);
        $this->get(route('perangkat.foto', $perangkat))->assertNotFound();
    }

    public function test_berkas_bukan_gambar_ditolak_sebagai_foto(): void
    {
        $this->formulirTerisi()
            ->set('foto', UploadedFile::fake()->create('skrip.php', 10, 'text/x-php'))
            ->call('simpan')
            ->assertHasErrors(['foto']);

        $this->assertSame(0, Perangkat::query()->count());
    }

    public function test_menghapus_perangkat_ikut_menghapus_fotonya(): void
    {
        $perangkat = Perangkat::factory()->create(['opd_id' => $this->opd->id]);
        $perangkat->foto_path = UploadedFile::fake()->image('x.jpg')->store(Perangkat::FOLDER_FOTO, Perangkat::DISK_FOTO);
        $perangkat->save();
        $foto = (string) $perangkat->foto_path;

        Livewire::test('pages::perangkat.index')
            ->call('konfirmasiHapus', $perangkat->id)
            ->call('hapus')
            ->assertHasNoErrors();

        $this->assertNull($perangkat->fresh());
        Storage::disk(Perangkat::DISK_FOTO)->assertMissing($foto);
        $this->assertTrue(LogAktivitas::query()->where('aksi', AksiAktivitas::PerangkatDihapus)->exists());
    }

    public function test_pencarian_dan_filter(): void
    {
        $opdLain = Opd::factory()->create(['kode' => 'DISDIK', 'nama' => 'Dinas Pendidikan']);

        Perangkat::factory()->switchUtama()->dipantau()->create([
            'opd_id' => $this->opd->id, 'kode_aset' => 'SW-A', 'nama' => 'Switch Alfa',
            'nomor_seri' => 'SERI-AAA', 'ip_manajemen' => '198.51.100.11',
        ]);
        Perangkat::factory()->create([
            'opd_id' => $opdLain->id, 'kode_aset' => 'RT-B', 'nama' => 'Router Beta', 'jenis' => JenisPerangkat::Router,
            'penempatan' => PenempatanPerangkat::Diskominfo, 'nomor_seri' => 'SERI-BBB', 'ip_manajemen' => '198.51.100.22',
        ]);

        Livewire::test('pages::perangkat.index')
            ->assertSee('SW-A')->assertSee('RT-B')
            ->set('cari', 'beta')->assertSee('RT-B')->assertDontSee('SW-A')
            ->set('cari', 'seri-aaa')->assertSee('SW-A')->assertDontSee('RT-B')
            ->set('cari', '100.22')->assertSee('RT-B')->assertDontSee('SW-A')
            ->set('cari', '')
            ->set('opd', (string) $this->opd->id)->assertSee('SW-A')->assertDontSee('RT-B')
            ->set('opd', '')
            ->set('jenis_saring', 'router')->assertSee('RT-B')->assertDontSee('SW-A')
            ->set('jenis_saring', '')
            ->set('penempatan_saring', 'diskominfo')->assertSee('RT-B')->assertDontSee('SW-A')
            ->set('penempatan_saring', '')
            ->set('monitoring', 'aktif')->assertSee('SW-A')->assertDontSee('RT-B')
            ->set('monitoring', 'nonaktif')->assertSee('RT-B')->assertDontSee('SW-A')
            ->set('monitoring', 'utama')->assertSee('SW-A')->assertDontSee('RT-B');
    }

    public function test_daftar_dipaginasi(): void
    {
        foreach (range(1, 17) as $i) {
            Perangkat::factory()->create(['opd_id' => $this->opd->id, 'kode_aset' => sprintf('AST-%02d', $i)]);
        }

        Livewire::test('pages::perangkat.index')
            ->assertSee('AST-15')
            ->assertDontSee('AST-16')
            ->call('gotoPage', 2)
            ->assertSee('AST-16');
    }

    public function test_rincian_perangkat_dapat_dilihat(): void
    {
        $perangkat = Perangkat::factory()->simulasi()->create(['opd_id' => $this->opd->id, 'catatan' => 'Catatan rahasia dapur']);

        $this->assertStringStartsWith('[SIMULASI] ', $perangkat->nama);

        Livewire::test('pages::perangkat.index')
            ->assertSee('data-test="lencana-simulasi"', false)
            ->assertDontSee('Catatan rahasia dapur')
            ->call('lihat', $perangkat->id)
            ->assertSee('Catatan rahasia dapur');
    }

    public function test_tabel_perangkat_tidak_memuat_kolom_kredensial(): void
    {
        foreach (Schema::getColumnListing('perangkat') as $kolom) {
            $this->assertDoesNotMatchRegularExpression(
                '/snmp|community|komunitas|kredensial|credential|password|sandi|secret|rahasia|token|auth|priv/i',
                $kolom,
                "Kolom {$kolom} tampak menyimpan kredensial; simpan di luar tabel perangkat.",
            );
        }
    }
}
