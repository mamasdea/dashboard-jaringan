<?php

namespace Tests\Feature\Fo;

use App\Enums\AksiAktivitas;
use App\Enums\StatusData;
use App\Fo\LayananHubungan;
use App\Models\LogAktivitas;
use App\Models\Opd;
use App\Models\SegmenKabel;
use App\Models\Tiang;
use App\Models\TitikJaringan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Halaman daftar tiang, titik jaringan, dan segmen kabel: data yang belum
 * lengkap tetap dapat disimpan dengan status "Perlu dilengkapi".
 */
class PendataanBertahapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Tiang::DISK_FOTO);

        $this->actingAs(User::factory()->operator()->create());
    }

    public function test_tiang_dapat_disimpan_hanya_dengan_kode_lalu_dilengkapi(): void
    {
        Livewire::test('pages::fo.tiang')
            ->call('tambah')
            ->set('kode', 'tg-001')
            ->call('simpan')
            ->assertHasNoErrors();

        $tiang = Tiang::query()->where('kode', 'TG-001')->firstOrFail();
        $this->assertSame(StatusData::PerluDilengkapi, $tiang->status_data);

        $this->get(route('fo.tiang'))
            ->assertOk()
            ->assertSee('TG-001')
            ->assertSee('Perlu dilengkapi')
            ->assertSee('Belum ada: koordinat, pemilik, kondisi');

        Livewire::test('pages::fo.tiang')
            ->call('ubah', $tiang->id)
            ->set('lintang', '-7,3589')
            ->set('bujur', '109.9032')
            ->set('pemilik', 'pln')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame(['kondisi'], $tiang->refresh()->kekurangan());

        Livewire::test('pages::fo.tiang')
            ->call('ubah', $tiang->id)
            ->set('kondisi', 'baik')
            ->set('foto', UploadedFile::fake()->image('tiang.jpg'))
            ->call('simpan')
            ->assertHasNoErrors();

        $tiang->refresh();
        $this->assertSame(StatusData::Lengkap, $tiang->status_data);
        $this->assertSame('-7.358900', $tiang->lintang);
        Storage::disk(Tiang::DISK_FOTO)->assertExists((string) $tiang->foto_path);
        $this->get(route('fo.tiang.foto', $tiang))->assertOk();
        $this->assertTrue(LogAktivitas::query()->where('aksi', AksiAktivitas::TiangDiubah)->exists());
    }

    public function test_validasi_dan_filter_tiang(): void
    {
        Tiang::factory()->create(['kode' => 'TG-LENGKAP', 'pemilik' => 'telkom', 'kondisi' => 'miring']);
        Tiang::factory()->belumLengkap()->create(['kode' => 'TG-KOSONG']);

        Livewire::test('pages::fo.tiang')
            ->call('tambah')
            ->set('kode', 'tg-lengkap')
            ->set('lintang', '-99')
            ->set('pemilik', 'tetangga')
            ->call('simpan')
            ->assertHasErrors(['kode', 'lintang', 'bujur', 'pemilik']);

        Livewire::test('pages::fo.tiang')
            ->set('status', 'perlu_dilengkapi')->assertSee('TG-KOSONG')->assertDontSee('TG-LENGKAP')
            ->set('status', 'lengkap')->assertSee('TG-LENGKAP')->assertDontSee('TG-KOSONG')
            ->set('status', '')
            ->set('pemilik_saring', 'telkom')->assertSee('TG-LENGKAP')->assertDontSee('TG-KOSONG')
            ->set('pemilik_saring', '')
            ->set('kondisi_saring', 'miring')->assertSee('TG-LENGKAP')->assertDontSee('TG-KOSONG')
            ->set('kondisi_saring', '')
            ->set('cari', 'kosong')->assertSee('TG-KOSONG')->assertDontSee('TG-LENGKAP');
    }

    public function test_tiang_yang_dipakai_tidak_dapat_dihapus(): void
    {
        $tiang = Tiang::factory()->create();
        $segmen = SegmenKabel::factory()->create();
        app(LayananHubungan::class)->aturRuteTiang($segmen, [$tiang->id]);

        Livewire::test('pages::fo.tiang')
            ->call('konfirmasiHapus', $tiang->id)
            ->call('hapus')
            ->assertHasErrors(['hapus']);

        $this->assertNotNull($tiang->fresh());

        app(LayananHubungan::class)->aturRuteTiang($segmen, []);

        Livewire::test('pages::fo.tiang')
            ->call('konfirmasiHapus', $tiang->id)
            ->call('hapus')
            ->assertHasNoErrors();

        $this->assertNull($tiang->fresh());
    }

    public function test_titik_jaringan_dapat_disimpan_dengan_kode_dan_jenis_saja(): void
    {
        $tiang = Tiang::factory()->create(['kode' => 'TG-010']);
        $opd = Opd::factory()->create();

        Livewire::test('pages::fo.titik')
            ->call('tambah')
            ->set('kode', 'odp-01')
            ->set('jenis', 'odp')
            ->call('simpan')
            ->assertHasNoErrors();

        $odp = TitikJaringan::query()->where('kode', 'ODP-01')->firstOrFail();
        $this->assertSame(StatusData::PerluDilengkapi, $odp->status_data);
        $this->assertSame(0, $odp->port()->count());

        Livewire::test('pages::fo.titik')
            ->call('ubah', $odp->id)
            ->set('kode_tiang', 'tg-010')
            ->set('kapasitas_port', '8')
            ->call('simpan')
            ->assertHasNoErrors();

        $odp->refresh();
        $this->assertSame($tiang->id, $odp->tiang_id);
        $this->assertSame(8, $odp->port()->count());
        $this->assertSame(StatusData::Lengkap, $odp->status_data);

        Livewire::test('pages::fo.titik')
            ->call('tambah')
            ->set('kode', 'otb-01')
            ->set('jenis', 'otb')
            ->set('opd_id', (string) $opd->id)
            ->set('kapasitas_port', '12')
            ->call('simpan')
            ->assertHasNoErrors();

        Livewire::test('pages::fo.titik')
            ->call('tambah')
            ->set('kode', 'odp-01')
            ->set('jenis', 'tiang')
            ->set('kode_tiang', 'TIDAK-ADA')
            ->set('kapasitas_port', '9999')
            ->call('simpan')
            ->assertHasErrors(['kode', 'jenis', 'kode_tiang', 'kapasitas_port']);

        Livewire::test('pages::fo.titik')
            ->set('jenis_saring', 'otb')->assertSee('OTB-01')->assertDontSee('ODP-01')
            ->set('jenis_saring', '')
            ->set('status', 'lengkap')->assertSee('ODP-01');
    }

    public function test_kapasitas_port_tidak_dapat_dikurangi_di_bawah_port_terpakai(): void
    {
        $otb = TitikJaringan::factory()->otb(12)->create();
        $segmen = SegmenKabel::factory()->antara($otb, TitikJaringan::factory()->create())->create();
        app(LayananHubungan::class)->terminasikan(
            $otb->port()->where('nomor', 7)->firstOrFail(),
            $segmen->core()->where('nomor', 1)->firstOrFail(),
        );

        foreach (['4', ''] as $kapasitas) {
            Livewire::test('pages::fo.titik')
                ->call('ubah', $otb->id)
                ->set('kapasitas_port', $kapasitas)
                ->call('simpan')
                ->assertHasErrors(['kapasitas_port']);
        }

        $this->assertSame(12, $otb->port()->count());

        Livewire::test('pages::fo.titik')
            ->call('ubah', $otb->id)
            ->set('kapasitas_port', '8')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame(8, $otb->port()->count());

        Livewire::test('pages::fo.titik')
            ->call('konfirmasiHapus', $otb->id)
            ->call('hapus')
            ->assertHasErrors(['hapus']);
    }

    public function test_segmen_kabel_dapat_disimpan_hanya_dengan_kode_lalu_dilengkapi(): void
    {
        TitikJaringan::factory()->otb()->create(['kode' => 'OTB-A']);
        TitikJaringan::factory()->create(['kode' => 'CL-01']);

        Livewire::test('pages::fo.kabel')
            ->call('tambah')
            ->set('kode', 'bb-01')
            ->call('simpan')
            ->assertHasNoErrors();

        $segmen = SegmenKabel::query()->where('kode', 'BB-01')->firstOrFail();
        $this->assertSame(StatusData::PerluDilengkapi, $segmen->status_data);
        $this->assertSame(0, $segmen->core()->count());

        $this->get(route('fo.kabel'))->assertOk()->assertSee('BB-01')->assertSee('Perlu dilengkapi');

        Livewire::test('pages::fo.kabel')
            ->call('ubah', $segmen->id)
            ->set('klasifikasi', 'backbone')
            ->set('jumlah_core', '24')
            ->set('kode_titik_awal', 'otb-a')
            ->set('kode_titik_akhir', 'cl-01')
            ->set('panjang_ukur_m', '1250,5')
            ->call('simpan')
            ->assertHasNoErrors();

        $segmen->refresh();
        $this->assertSame('backbone', $segmen->klasifikasi?->value);
        $this->assertSame(24, $segmen->core()->count());
        $this->assertSame('OTB-A', $segmen->titikAwal?->kode);
        $this->assertSame('1250.50', $segmen->panjang_ukur_m);
        $this->assertNull($segmen->panjang_peta_m);
        // Jalur peta diisi di halaman rincian.
        $this->assertSame(['jalur peta'], $segmen->kekurangan());

        Livewire::test('pages::fo.kabel')
            ->call('tambah')
            ->set('kode', 'bb-01')
            ->set('klasifikasi', 'feeder')
            ->set('jumlah_core', '0')
            ->set('kode_titik_awal', 'OTB-A')
            ->set('kode_titik_akhir', 'OTB-A')
            ->set('panjang_ukur_m', '-5')
            ->call('simpan')
            ->assertHasErrors(['kode', 'klasifikasi', 'jumlah_core', 'kode_titik_akhir', 'panjang_ukur_m']);

        Livewire::test('pages::fo.kabel')
            ->call('tambah')
            ->set('kode', 'ds-09')
            ->set('kode_titik_awal', 'TIDAK-ADA')
            ->call('simpan')
            ->assertHasErrors(['kode_titik_awal']);

        SegmenKabel::factory()->create(['kode' => 'DS-01', 'klasifikasi' => 'distribusi']);

        Livewire::test('pages::fo.kabel')
            ->set('klasifikasi_saring', 'backbone')->assertSee('BB-01')->assertDontSee('DS-01')
            ->set('klasifikasi_saring', '')
            ->set('status', 'lengkap')->assertSee('DS-01')->assertDontSee('BB-01');
    }

    public function test_segmen_yang_terhubung_dilindungi_dari_perubahan_yang_memutus(): void
    {
        $awal = TitikJaringan::factory()->otb(4)->create(['kode' => 'OTB-A']);
        $akhir = TitikJaringan::factory()->create(['kode' => 'CL-01']);
        TitikJaringan::factory()->create(['kode' => 'CL-02']);
        $segmen = SegmenKabel::factory()->antara($awal, $akhir)->create(['kode' => 'KBL', 'jumlah_core' => 12]);

        app(LayananHubungan::class)->terminasikan(
            $awal->port()->where('nomor', 1)->firstOrFail(),
            $segmen->core()->where('nomor', 8)->firstOrFail(),
        );

        // Mengurangi atau mengosongkan jumlah core di bawah core 8.
        foreach (['6', ''] as $jumlah) {
            Livewire::test('pages::fo.kabel')->call('ubah', $segmen->id)->set('jumlah_core', $jumlah)->call('simpan')->assertHasErrors(['jumlah_core']);
        }

        // Mengganti atau mengosongkan titik awal yang core-nya terpakai.
        foreach (['CL-02', ''] as $kode) {
            Livewire::test('pages::fo.kabel')->call('ubah', $segmen->id)->set('kode_titik_awal', $kode)->call('simpan')->assertHasErrors(['kode_titik_awal']);
        }

        Livewire::test('pages::fo.kabel')->call('konfirmasiHapus', $segmen->id)->call('hapus')->assertHasErrors(['hapus']);

        $segmen->refresh();
        $this->assertSame(12, $segmen->core()->count());
        $this->assertSame($awal->id, $segmen->titik_awal_id);

        // Perubahan yang tidak memutus tetap boleh: ujung yang bebas dan jumlah core di atas 8.
        Livewire::test('pages::fo.kabel')
            ->call('ubah', $segmen->id)
            ->set('kode_titik_akhir', 'CL-02')
            ->set('jumlah_core', '8')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame('CL-02', $segmen->refresh()->titikAkhir?->kode);
        $this->assertSame(8, $segmen->core()->count());
    }
}
