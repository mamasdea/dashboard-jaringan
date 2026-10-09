<?php

namespace Tests\Feature\Tiket;

use App\Enums\AksiAktivitas;
use App\Enums\JenisCatatanTiket;
use App\Enums\PrioritasTiket;
use App\Enums\StatusTiket;
use App\Models\CatatanTiket;
use App\Models\LogAktivitas;
use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\SegmenKabel;
use App\Models\Tiket;
use App\Models\User;
use App\Tiket\FormulirTiket;
use App\Tiket\LayananTiket;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LogicException;
use Tests\TestCase;

/**
 * Alur kerja tiket: dibuat, ditugaskan, dikerjakan, diselesaikan.
 */
class AlurTiketTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    private User $teknisi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->operator = User::factory()->operator()->create();
        $this->teknisi = User::factory()->teknisi()->create(['name' => 'Teknisi Satu']);
    }

    public function test_operator_membuat_tiket_dan_menugaskannya(): void
    {
        $opd = Opd::factory()->create();
        $perangkat = Perangkat::factory()->create(['opd_id' => $opd->id]);
        $kabel = SegmenKabel::factory()->create(['kode' => 'KBL-UJI']);
        $mulai = now()->subMinutes(30);

        Livewire::actingAs($this->operator)->test('pages::tiket.index')
            ->call('tambah')
            ->set('form.judul', 'Laporan lampu indikator mati')
            ->set('form.perangkat_id', (string) $perangkat->id)
            ->set('form.jenis_aset', 'kabel')
            ->set('form.kode_aset', 'kbl-uji')
            ->set('form.waktu_mulai', FormulirTiket::keIsian($mulai))
            ->set('form.prioritas', 'kritis')
            ->set('form.dugaan_penyebab', 'Mungkin adaptor')
            ->set('teknisi_id', (string) $this->teknisi->id)
            ->call('simpan')
            ->assertHasNoErrors()
            ->assertRedirect(route('tiket.rinci', Tiket::query()->sole()));

        $tiket = Tiket::query()->sole();

        $this->assertSame(Tiket::MANUAL, $tiket->sumber);
        $this->assertSame($opd->id, $tiket->opd_id, 'OPD diambil dari perangkat.');
        $this->assertSame($kabel->id, $tiket->segmen_kabel_id);
        $this->assertSame(PrioritasTiket::Kritis, $tiket->prioritas);
        $this->assertSame($this->teknisi->id, $tiket->teknisi_id);
        $this->assertSame(StatusTiket::Ditugaskan, $tiket->status);
        $this->assertSame($this->operator->id, $tiket->dibuat_oleh);
        $this->assertSame('Mungkin adaptor', $tiket->dugaan_penyebab);
        $this->assertNull($tiket->penyebab_terverifikasi);
        // Isian memakai zona tampilan; penyimpanan UTC.
        $this->assertSame($mulai->utc()->format('Y-m-d H:i'), $tiket->waktu_mulai->utc()->format('Y-m-d H:i'));
        $this->assertSame('UTC', DB::scalar("SELECT current_setting('TIMEZONE')"));

        $this->assertTrue(LogAktivitas::query()->where('aksi', AksiAktivitas::TiketDibuat)->where('subjek_id', $tiket->id)->exists());
        $this->assertTrue(LogAktivitas::query()->where('aksi', AksiAktivitas::TiketDiubah)->where('subjek_id', $tiket->id)->exists());
    }

    public function test_validasi_formulir(): void
    {
        $opdLain = Opd::factory()->create();
        $perangkat = Perangkat::factory()->create();

        Livewire::actingAs($this->operator)->test('pages::tiket.index')
            ->call('tambah')
            ->set('form.judul', '')
            ->call('simpan')
            ->assertHasErrors(['form.judul'])
            ->set('form.judul', 'Ada')
            ->set('form.jenis_aset', 'tiang')
            ->set('form.kode_aset', 'TIDAK-ADA')
            ->call('simpan')
            ->assertHasErrors(['form.kode_aset'])
            ->set('form.jenis_aset', '')
            ->set('form.kode_aset', '')
            ->set('form.opd_id', (string) $opdLain->id)
            ->set('form.perangkat_id', (string) $perangkat->id)
            ->call('simpan')
            ->assertHasErrors(['form.perangkat_id'])
            ->set('form.opd_id', '')
            ->set('form.waktu_mulai', FormulirTiket::keIsian(now()->addDay()))
            ->call('simpan')
            ->assertHasErrors(['form.waktu_mulai']);

        $this->assertSame(0, Tiket::query()->count());
    }

    public function test_teknisi_mengerjakan_dan_menyelesaikan_tiketnya(): void
    {
        $tiket = Tiket::factory()->untuk($this->teknisi)->create(['waktu_mulai' => now()->subHours(3)]);
        $pulih = now()->subMinutes(20);

        $uji = Livewire::actingAs($this->teknisi)->test('pages::tiket.rinci', ['tiket' => $tiket])
            ->assertOk()
            ->assertDontSee('data-test="ubah-tiket"', false)
            ->assertDontSee('data-test="penugasan"', false)
            ->assertDontSee('data-test="batalkan"', false)
            ->call('ubahStatus', 'dikerjakan')
            ->assertHasNoErrors();

        $this->assertSame(StatusTiket::Dikerjakan, $tiket->refresh()->status);

        // Tanpa waktu pemulihan tiket tidak dapat diselesaikan.
        $uji->set('waktu_pemulihan', '')->call('selesaikan')->assertHasErrors(['waktu_pemulihan']);
        $uji->set('waktu_pemulihan', FormulirTiket::keIsian($tiket->waktu_mulai->subHour()))->call('selesaikan')->assertHasErrors(['tiket']);
        $uji->set('waktu_pemulihan', FormulirTiket::keIsian(now()->addHour()))->call('selesaikan')->assertHasErrors(['tiket']);
        $this->assertSame(StatusTiket::Dikerjakan, $tiket->refresh()->status);

        $uji->set('waktu_pemulihan', FormulirTiket::keIsian($pulih))->call('selesaikan')->assertHasNoErrors();

        $tiket->refresh();
        $this->assertSame(StatusTiket::Selesai, $tiket->status);
        $this->assertSame($pulih->utc()->format('Y-m-d H:i'), $tiket->waktu_pemulihan->utc()->format('Y-m-d H:i'));
        $this->assertTrue($tiket->catatan()->where('isi', 'like', '%Dikerjakan → Selesai%')->where('pengguna_id', $this->teknisi->id)->exists());
    }

    public function test_dugaan_dan_penyebab_terverifikasi_disimpan_terpisah(): void
    {
        $tiket = Tiket::factory()->untuk($this->teknisi)->create(['dugaan_penyebab' => 'Kabel digigit tikus']);

        Livewire::actingAs($this->teknisi)->test('pages::tiket.rinci', ['tiket' => $tiket])
            ->assertSet('dugaan_penyebab', 'Kabel digigit tikus')
            ->assertSet('penyebab_terverifikasi', '')
            ->set('penyebab_terverifikasi', 'Adaptor switch rusak')
            ->call('simpanPenyebab')
            ->assertHasNoErrors();

        $tiket->refresh();
        $this->assertSame('Kabel digigit tikus', $tiket->dugaan_penyebab, 'Dugaan awal tetap tersimpan.');
        $this->assertSame('Adaptor switch rusak', $tiket->penyebab_terverifikasi);
        $this->assertTrue($tiket->catatan()->where('isi', 'Penyebab terverifikasi diperbarui.')->exists());
    }

    public function test_tindakan_dan_foto_masuk_linimasa(): void
    {
        Storage::fake(Tiket::DISK_FOTO);

        $tiket = Tiket::factory()->untuk($this->teknisi)->create();

        Livewire::actingAs($this->teknisi)->test('pages::tiket.rinci', ['tiket' => $tiket])
            ->set('jenis_catatan', 'tindakan')
            ->set('isi_catatan', 'Mengganti adaptor switch')
            ->set('foto', UploadedFile::fake()->image('adaptor.jpg', 400, 300))
            ->call('tambahCatatan')
            ->assertHasNoErrors()
            ->assertSet('isi_catatan', '')
            ->assertSee('Mengganti adaptor switch')
            ->set('isi_catatan', 'Bukan gambar')
            ->set('foto', UploadedFile::fake()->create('skrip.php', 10, 'text/plain'))
            ->call('tambahCatatan')
            ->assertHasErrors(['foto']);

        $catatan = $tiket->catatan()->sole();

        $this->assertSame(JenisCatatanTiket::Tindakan, $catatan->jenis);
        $this->assertSame('Teknisi Satu', $catatan->pengguna_nama);
        $this->assertStringStartsWith(Tiket::FOLDER_FOTO.'/', (string) $catatan->foto_path);
        Storage::disk(Tiket::DISK_FOTO)->assertExists($catatan->foto_path);

        // Foto hanya untuk yang boleh melihat tiketnya.
        $this->actingAs($this->teknisi)->get(route('tiket.foto', [$tiket, $catatan]))->assertOk();
        $this->actingAs(User::factory()->teknisi()->create())->get(route('tiket.foto', [$tiket, $catatan]))->assertForbidden();
        $this->actingAs(User::factory()->viewer()->create())->get(route('tiket.foto', [$tiket, $catatan]))->assertForbidden();
        $this->actingAs($this->operator)->get(route('tiket.foto', [Tiket::factory()->create(), $catatan]))->assertNotFound();

        $tiket->delete();
        Storage::disk(Tiket::DISK_FOTO)->assertMissing($catatan->foto_path);
    }

    public function test_catatan_tiket_tidak_dapat_diubah(): void
    {
        $catatan = app(LayananTiket::class)->catat(Tiket::factory()->create(), JenisCatatanTiket::Catatan, 'Asli');

        $this->expectException(LogicException::class);

        $catatan->update(['isi' => 'Diubah']);
    }

    public function test_operator_membatalkan_dan_membuka_kembali(): void
    {
        $tiket = Tiket::factory()->untuk($this->teknisi)->create();

        $uji = Livewire::actingAs($this->operator)->test('pages::tiket.rinci', ['tiket' => $tiket])
            ->call('ubahStatus', 'dibatalkan')
            ->assertHasNoErrors();

        $this->assertSame(StatusTiket::Dibatalkan, $tiket->refresh()->status);

        $uji->call('ubahStatus', 'baru')->assertHasNoErrors();

        $this->assertSame(StatusTiket::Ditugaskan, $tiket->refresh()->status, 'Dibuka kembali ke antrean teknisinya.');

        // Menyelesaikan hanya lewat aksi yang meminta waktu pemulihan.
        $uji->call('ubahStatus', 'selesai')->assertStatus(422);
    }

    public function test_penugasan_hanya_kepada_yang_boleh_menangani(): void
    {
        $tiket = Tiket::factory()->create();

        Livewire::actingAs($this->operator)->test('pages::tiket.rinci', ['tiket' => $tiket])
            ->set('teknisi_id', (string) User::factory()->viewer()->create()->id)
            ->call('tugaskan')
            ->assertHasErrors(['tiket'])
            ->set('teknisi_id', (string) $this->teknisi->id)
            ->call('tugaskan')
            ->assertHasNoErrors();

        $this->assertSame($this->teknisi->id, $tiket->refresh()->teknisi_id);
        $this->assertSame(StatusTiket::Ditugaskan, $tiket->status);
    }

    public function test_tiket_tanpa_teknisi_tidak_dapat_dikerjakan(): void
    {
        $tiket = Tiket::factory()->create();

        Livewire::actingAs($this->operator)->test('pages::tiket.rinci', ['tiket' => $tiket])
            ->call('ubahStatus', 'dikerjakan')
            ->assertHasErrors(['tiket']);

        $this->assertSame(StatusTiket::Baru, $tiket->refresh()->status);
    }

    public function test_database_menjaga_aturan_tiket(): void
    {
        $tiket = Tiket::factory()->create();

        foreach ([
            ['status' => 'selesai'],
            ['status' => 'entah'],
            ['prioritas' => 'darurat'],
            ['waktu_pemulihan' => $tiket->waktu_mulai->subDay()],
        ] as $ubah) {
            try {
                DB::transaction(fn () => DB::table('tiket')->where('id', $tiket->id)->update($ubah));
                $this->fail('Seharusnya ditolak: '.json_encode($ubah));
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_daftar_tiket_dan_penyaringnya(): void
    {
        Tiket::factory()->create(['judul' => 'Tiket terbuka']);
        Tiket::factory()->selesai()->create(['judul' => 'Tiket lama']);
        Tiket::factory()->create(['judul' => 'Milik teknisi', 'teknisi_id' => $this->teknisi->id, 'status' => 'ditugaskan']);

        Livewire::actingAs($this->operator)->test('pages::tiket.index')
            ->assertSee('Tiket terbuka')->assertSee('Milik teknisi')->assertDontSee('Tiket lama')
            ->set('status', 'semua')->assertSee('Tiket lama')
            ->set('status', 'selesai')->assertSee('Tiket lama')->assertDontSee('Tiket terbuka')
            ->set('status', '')->set('cari', 'teknisi')->assertSee('Milik teknisi')->assertDontSee('Tiket terbuka');

        // Teknisi hanya melihat tiket yang ditugaskan kepadanya.
        Livewire::actingAs($this->teknisi)->test('pages::tiket.index')
            ->assertSee('Milik teknisi')->assertDontSee('Tiket terbuka')
            ->assertDontSee('data-test="tambah-tiket"', false);

        $this->assertSame(0, CatatanTiket::query()->count());
    }
}
