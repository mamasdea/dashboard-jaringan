<?php

namespace Tests\Feature\Fo;

use App\Enums\StatusPemakaian;
use App\Fo\LayananHubungan;
use App\Models\CoreKabel;
use App\Models\KoneksiEndpoint;
use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\PortPerangkat;
use App\Models\PortTerminasi;
use App\Models\RiwayatHubungan;
use App\Models\Sambungan;
use App\Models\SegmenKabel;
use App\Models\Tiang;
use App\Models\TitikJaringan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Halaman rincian segmen kabel (rute tiang, jalur peta, core) dan titik
 * jaringan (sambungan, terminasi, koneksi endpoint, riwayat).
 */
class HalamanRincianTest extends TestCase
{
    use RefreshDatabase;

    private TitikJaringan $otb;

    private TitikJaringan $closure;

    private SegmenKabel $kabelA;

    private SegmenKabel $kabelB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->operator()->create(['name' => 'Operator Uji']));

        $this->otb = TitikJaringan::factory()->otb(4)->create(['kode' => 'OTB-A', 'lintang' => -7.3600, 'bujur' => 109.9000]);
        $this->closure = TitikJaringan::factory()->create(['kode' => 'CL', 'lintang' => -7.3600, 'bujur' => 109.9100]);
        $this->kabelA = SegmenKabel::factory()->antara($this->otb, $this->closure)->create(['kode' => 'KBL-A', 'jalur_peta' => null, 'panjang_ukur_m' => 1200]);
        $this->kabelB = SegmenKabel::factory()->antara($this->closure, TitikJaringan::factory()->otb(4)->create(['kode' => 'OTB-B']))->create(['kode' => 'KBL-B']);
    }

    private function core(SegmenKabel $segmen, int $nomor): CoreKabel
    {
        return $segmen->core()->where('nomor', $nomor)->firstOrFail();
    }

    private function port(int $nomor): PortTerminasi
    {
        return $this->otb->port()->where('nomor', $nomor)->firstOrFail();
    }

    public function test_halaman_rincian_dapat_dibuka(): void
    {
        $this->get(route('fo.kabel.rinci', $this->kabelA))
            ->assertOk()
            ->assertSee('KBL-A')
            ->assertSee('Panjang hasil pengukuran')
            ->assertSee('Panjang hasil perhitungan peta')
            ->assertSee('Belum ada jalur');

        $this->get(route('fo.titik.rinci', $this->closure))
            ->assertOk()
            ->assertSee('Sambungan core')
            ->assertSee('KBL-A')
            ->assertSee('KBL-B')
            ->assertSee('12 dari 12 core masih bebas di titik ini')
            ->assertDontSee('Port terminasi (');

        $this->get(route('fo.titik.rinci', $this->otb))->assertOk()->assertSee('Port terminasi (4)');
        $this->get('/fo/kabel/999999')->assertNotFound();
    }

    public function test_rute_tiang_dapat_ditambah_diurutkan_dan_dilepas(): void
    {
        [$t1, $t2, $t3] = Tiang::factory()->count(3)->sequence(['kode' => 'T1'], ['kode' => 'T2'], ['kode' => 'T3'])->create()->all();

        $komponen = Livewire::test('pages::fo.kabel-rinci', ['segmen' => $this->kabelA])
            ->set('kode_tiang_baru', 't1')->call('tambahTiang')
            ->set('kode_tiang_baru', 'T2')->call('tambahTiang')
            ->set('kode_tiang_baru', 'T3')->call('tambahTiang')
            ->assertHasNoErrors()
            ->assertSeeInOrder(['T1', 'T2', 'T3']);

        $this->assertSame(['T1', 'T2', 'T3'], $this->kabelA->tiang()->pluck('kode')->all());

        $komponen->call('geserTiang', $t3->id, -1);
        $this->assertSame(['T1', 'T3', 'T2'], $this->kabelA->tiang()->pluck('kode')->all());

        $komponen->call('geserTiang', $t1->id, 1)->call('geserTiang', $t1->id, -1)->call('geserTiang', $t1->id, -1);
        $this->assertSame(['T1', 'T3', 'T2'], $this->kabelA->tiang()->pluck('kode')->all(), 'Menggeser melewati batas diabaikan.');

        $komponen->call('lepasTiang', $t3->id);
        $this->assertSame(['T1', 'T2'], $this->kabelA->tiang()->pluck('kode')->all());
        $this->assertSame([1, 2], $this->kabelA->tiang()->get()->map(fn (Tiang $t) => $t->pivot->urutan)->all());

        $komponen
            ->set('kode_tiang_baru', 'TIDAK-ADA')->call('tambahTiang')->assertHasErrors(['kode_tiang_baru'])
            ->set('kode_tiang_baru', 'T1')->call('tambahTiang')->assertHasErrors(['hubungan'])
            ->assertSee('Riwayat rute')
            ->assertSee('Rute tiang KBL-A: T1 → T2');

        // Tiang yang sama boleh dilalui kabel lain.
        Livewire::test('pages::fo.kabel-rinci', ['segmen' => $this->kabelB])
            ->set('kode_tiang_baru', 'T2')->call('tambahTiang')->assertHasNoErrors();

        $this->assertSame(2, $t2->segmen()->count());
        $this->assertGreaterThanOrEqual(5, RiwayatHubungan::query()->where('segmen_kabel_id', $this->kabelA->id)->count());
    }

    public function test_jalur_peta_disimpan_dan_panjang_peta_dihitung_terpisah(): void
    {
        $komponen = Livewire::test('pages::fo.kabel-rinci', ['segmen' => $this->kabelA])
            ->set('jalur_teks', "-7.36, 109.90\n\n-7.36; 109.91\n")
            ->call('simpanJalur')
            ->assertHasNoErrors();

        $this->kabelA->refresh();
        $this->assertSame([[109.9, -7.36], [109.91, -7.36]], $this->kabelA->jalur_peta);
        $this->assertEqualsWithDelta(1102.8, (float) $this->kabelA->panjang_peta_m, 2.0);
        $this->assertSame('1200.00', $this->kabelA->panjang_ukur_m);
        $this->assertSame([], $this->kabelA->kekurangan());

        $komponen->assertSee('Selisih');

        $komponen->set('jalur_teks', "-7.36, 109.90\nbukan koordinat\n-97, 109.91")->call('simpanJalur')->assertHasErrors(['jalur_teks']);
        $komponen->set('jalur_teks', '-7.36, 109.90')->call('simpanJalur')->assertHasErrors(['jalur_teks']);
        $this->assertCount(2, (array) $this->kabelA->refresh()->jalur_peta);

        $komponen->set('jalur_teks', '')->call('simpanJalur')->assertHasNoErrors();
        $this->assertNull($this->kabelA->refresh()->jalur_peta);
        $this->assertNull($this->kabelA->panjang_peta_m);
    }

    public function test_jalur_dapat_disusun_dari_titik_dan_tiang(): void
    {
        $t1 = Tiang::factory()->create(['kode' => 'T1', 'lintang' => -7.3600, 'bujur' => 109.9030]);
        $t2 = Tiang::factory()->belumLengkap()->create(['kode' => 'T2']);
        $t3 = Tiang::factory()->create(['kode' => 'T3', 'lintang' => -7.3600, 'bujur' => 109.9070]);
        app(LayananHubungan::class)->aturRuteTiang($this->kabelA, [$t1->id, $t2->id, $t3->id]);

        $komponen = Livewire::test('pages::fo.kabel-rinci', ['segmen' => $this->kabelA])
            ->assertSee('tanpa koordinat')
            ->call('susunJalur');

        // Titik awal, T1, T3 (T2 tanpa koordinat dilewati), titik akhir; belum disimpan.
        $this->assertCount(4, explode("\n", $komponen->get('jalur_teks')));
        $this->assertNull($this->kabelA->refresh()->jalur_peta);

        $komponen->call('simpanJalur')->assertHasNoErrors();

        $this->assertCount(4, (array) $this->kabelA->refresh()->jalur_peta);
        $this->assertEqualsWithDelta(1102.8, (float) $this->kabelA->panjang_peta_m, 2.0);
    }

    public function test_core_dapat_ditandai_rusak_dari_rincian_segmen(): void
    {
        $core = $this->core($this->kabelA, 3);

        $komponen = Livewire::test('pages::fo.kabel-rinci', ['segmen' => $this->kabelA])
            ->assertSee('hijau')
            ->call('tandaiCore', $core->id);

        $this->assertSame(StatusPemakaian::Rusak, $core->refresh()->status);

        $komponen->call('tandaiCore', $core->id);
        $this->assertSame(StatusPemakaian::Tersedia, $core->refresh()->status);

        // Core segmen lain tidak dapat disentuh dari halaman ini.
        $komponen->call('tandaiCore', $this->core($this->kabelB, 1)->id)->assertNotFound();
    }

    public function test_sambungan_dibuat_dan_dilepas_dari_halaman_titik(): void
    {
        $a1 = $this->core($this->kabelA, 1);
        $b1 = $this->core($this->kabelB, 1);

        $komponen = Livewire::test('pages::fo.titik-rinci', ['titik' => $this->closure])
            ->set('sb_segmen_masuk', (string) $this->kabelA->id)
            ->set('sb_core_masuk', (string) $a1->id)
            ->set('sb_segmen_keluar', (string) $this->kabelB->id)
            ->set('sb_core_keluar', (string) $b1->id)
            ->set('sb_catatan', 'Fusion')
            ->call('sambung')
            ->assertHasNoErrors()
            ->assertSee('KBL-A core 1 (biru) disambung ke KBL-B core 1 (biru) di CL')
            ->assertSee('Operator Uji')
            ->assertSee('11 dari 12 core masih bebas di titik ini');

        $sambungan = Sambungan::query()->firstOrFail();
        $this->assertSame('Fusion', $sambungan->catatan);

        // Core yang sama tidak dapat dipakai lagi di titik ini.
        $komponen
            ->set('sb_core_masuk', (string) $a1->id)
            ->set('sb_core_keluar', (string) $this->core($this->kabelB, 2)->id)
            ->call('sambung')
            ->assertHasErrors(['hubungan']);

        $this->assertSame(1, Sambungan::query()->count());

        // Rincian segmen memperlihatkan pemakaian tiap ujung core.
        $this->get(route('fo.kabel.rinci', $this->kabelA))->assertSee('Sambung ke KBL-B core 1 (biru)');

        $komponen->call('lepasSambungan', $sambungan->id)->assertHasNoErrors()->assertSee('dilepas');

        $this->assertSame(0, Sambungan::query()->count());
        $this->assertSame(StatusPemakaian::Tersedia, $a1->refresh()->status);
    }

    public function test_core_dari_kabel_yang_tidak_berujung_di_titik_ditolak(): void
    {
        $asing = SegmenKabel::factory()->create(['kode' => 'KBL-ASING']);

        Livewire::test('pages::fo.titik-rinci', ['titik' => $this->closure])
            ->set('sb_core_masuk', (string) $this->core($asing, 1)->id)
            ->set('sb_core_keluar', (string) $this->core($this->kabelB, 1)->id)
            ->call('sambung')
            ->assertHasErrors(['sb_core_masuk']);

        Livewire::test('pages::fo.titik-rinci', ['titik' => $this->closure])
            ->call('sambung')
            ->assertHasErrors(['sb_core_masuk', 'sb_core_keluar']);

        $this->assertSame(0, Sambungan::query()->count());
    }

    public function test_terminasi_dan_koneksi_endpoint_lewat_port(): void
    {
        $perangkat = Perangkat::factory()->create(['kode_aset' => 'SW-01']);
        $a1 = $this->core($this->kabelA, 1);

        $komponen = Livewire::test('pages::fo.titik-rinci', ['titik' => $this->otb])
            ->set('tm_port', (string) $this->port(2)->id)
            ->set('tm_segmen', (string) $this->kabelA->id)
            ->set('tm_core', (string) $a1->id)
            ->call('terminasikan')
            ->assertHasNoErrors()
            ->assertSee('KBL-A core 1 (biru) diterminasi ke OTB-A port 2');

        $this->assertSame($a1->id, $this->port(2)->core_id);

        $komponen
            ->set('ep_perangkat', (string) $perangkat->id)
            ->set('ep_port_perangkat', ' Gi0/24 ')
            ->set('ep_port', (string) $this->port(2)->id)
            ->set('ep_catatan', 'Uplink')
            ->call('hubungkanEndpoint')
            ->assertHasNoErrors()
            ->assertSee('SW-01 port Gi0/24 dihubungkan ke OTB-A port 2');

        $portPerangkat = PortPerangkat::query()->where('perangkat_id', $perangkat->id)->firstOrFail();
        $koneksi = KoneksiEndpoint::query()->firstOrFail();

        $this->assertSame('Gi0/24', $portPerangkat->nama);
        $this->assertSame($this->port(2)->id, $koneksi->port_terminasi_id);
        $this->assertSame(StatusPemakaian::Terpakai, $this->port(2)->status);

        // Port perangkat yang sama tidak dapat dihubungkan ke port lain.
        $komponen
            ->set('ep_port_perangkat', 'Gi0/24')
            ->set('ep_port', (string) $this->port(3)->id)
            ->call('hubungkanEndpoint')
            ->assertHasErrors(['hubungan']);

        $komponen
            ->set('ep_port_perangkat', '')
            ->set('ep_port', '')
            ->call('hubungkanEndpoint')
            ->assertHasErrors(['ep_port_perangkat', 'ep_port']);

        $this->assertSame(1, KoneksiEndpoint::query()->count());
        $this->assertSame(1, PortPerangkat::query()->count());

        $komponen->call('lepasEndpoint', $koneksi->id)->call('lepasTerminasi', $this->port(2)->id)->assertHasNoErrors();

        $this->assertSame(0, KoneksiEndpoint::query()->count());
        $this->assertSame(StatusPemakaian::Tersedia, $this->port(2)->status);
        $this->assertSame(4, RiwayatHubungan::query()->where('titik_jaringan_id', $this->otb->id)->count());

        $komponen->call('tandaiPort', $this->port(1)->id);
        $this->assertSame(StatusPemakaian::Rusak, $this->port(1)->status);
    }

    public function test_koneksi_endpoint_langsung_ke_core_di_closure(): void
    {
        $perangkat = Perangkat::factory()->create(['kode_aset' => 'MC-01']);
        $b4 = $this->core($this->kabelB, 4);

        $komponen = Livewire::test('pages::fo.titik-rinci', ['titik' => $this->closure])
            ->assertSet('ep_lewat', 'core')
            ->set('ep_perangkat', (string) $perangkat->id)
            ->set('ep_port_perangkat', 'SFP1')
            ->set('ep_segmen', (string) $this->kabelB->id)
            ->set('ep_core', (string) $b4->id)
            ->call('hubungkanEndpoint')
            ->assertHasNoErrors()
            ->assertSee('MC-01 port SFP1 dihubungkan ke KBL-B core 4 (cokelat) di CL');

        $koneksi = KoneksiEndpoint::query()->firstOrFail();
        $this->assertSame($b4->id, $koneksi->core_id);
        $this->assertSame($this->closure->id, $koneksi->titik_jaringan_id);
        $this->assertSame(StatusPemakaian::Terpakai, $b4->refresh()->status);

        // Core itu tidak lagi dapat disambung di titik yang sama.
        $komponen
            ->set('sb_core_masuk', (string) $this->core($this->kabelA, 4)->id)
            ->set('sb_core_keluar', (string) $b4->id)
            ->call('sambung')
            ->assertHasErrors(['hubungan']);

        $komponen->call('lepasEndpoint', $koneksi->id)->assertHasNoErrors();
        $this->assertSame(StatusPemakaian::Tersedia, $b4->refresh()->status);
    }

    public function test_hubungan_titik_lain_tidak_dapat_dilepas_dari_halaman_ini(): void
    {
        $layanan = app(LayananHubungan::class);
        $sambungan = $layanan->sambung($this->closure, $this->core($this->kabelA, 1), $this->core($this->kabelB, 1));

        Livewire::test('pages::fo.titik-rinci', ['titik' => $this->otb])
            ->call('lepasSambungan', $sambungan->id)
            ->assertNotFound();

        $this->assertNotNull($sambungan->fresh());
    }

    public function test_perangkat_dan_opd_yang_terhubung_ke_fo_tidak_dapat_dihapus(): void
    {
        $opd = Opd::factory()->create();
        $perangkat = Perangkat::factory()->create(['opd_id' => $opd->id]);
        TitikJaringan::factory()->otb(2)->create(['opd_id' => $opd->id]);

        $koneksi = app(LayananHubungan::class)->hubungkanEndpoint(
            PortPerangkat::create(['perangkat_id' => $perangkat->id, 'nama' => 'Gi0/1']),
            port: $this->port(1),
        );

        Livewire::test('pages::perangkat.index')->call('konfirmasiHapus', $perangkat->id)->call('hapus')->assertHasErrors(['hapus']);
        $this->assertNotNull($perangkat->fresh());

        app(LayananHubungan::class)->lepasEndpoint($koneksi);

        Livewire::test('pages::perangkat.index')->call('konfirmasiHapus', $perangkat->id)->call('hapus')->assertHasNoErrors();
        $this->assertNull($perangkat->fresh());

        Livewire::test('pages::opd.index')->call('konfirmasiHapus', $opd->id)->call('hapus')->assertHasErrors(['hapus']);
        $this->assertNotNull($opd->fresh());
    }
}
