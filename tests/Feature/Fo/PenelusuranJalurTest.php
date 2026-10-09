<?php

namespace Tests\Feature\Fo;

use App\Fo\AnalisisDampak;
use App\Fo\Jalur;
use App\Fo\LayananHubungan;
use App\Fo\PenelusuranJalur;
use App\Models\CoreKabel;
use App\Models\EndpointMonitoring;
use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\PortPerangkat;
use App\Models\PortTerminasi;
use App\Models\SegmenKabel;
use App\Models\Tiang;
use App\Models\Tiket;
use App\Models\TitikJaringan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * Penelusuran jalur dan potensi terdampak, dari inventaris saja.
 *
 *   SW-PUSAT ── OTB-A ══K1══ CL ══K2══ OTB-B ── SW-B      (lengkap)
 *                 │          ╚══K3══ OTB-C (port tanpa perangkat)
 *                 ╚══K4══ (ujung lain belum dicatat) ── SW-D lewat OTB-A port 3
 *
 * Tiang TG-1 dilalui K1, TG-2 dilalui K2; CL berada di TG-1.
 */
class PenelusuranJalurTest extends TestCase
{
    use RefreshDatabase;

    private LayananHubungan $hubungan;

    private Opd $opdPusat;

    private Opd $opdB;

    private Opd $opdD;

    private Perangkat $swPusat;

    private Perangkat $swB;

    private Perangkat $swD;

    private TitikJaringan $otbA;

    private TitikJaringan $cl;

    private TitikJaringan $otbB;

    private TitikJaringan $otbC;

    private SegmenKabel $k1;

    private SegmenKabel $k2;

    private SegmenKabel $k3;

    private SegmenKabel $k4;

    private Tiang $tg1;

    private Tiang $tg2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hubungan = app(LayananHubungan::class);

        $this->opdPusat = Opd::factory()->create(['nama' => 'Dinas Pusat']);
        $this->opdB = Opd::factory()->create(['nama' => 'Dinas B']);
        $this->opdD = Opd::factory()->create(['nama' => 'Dinas D']);
        $this->swPusat = Perangkat::factory()->create(['opd_id' => $this->opdPusat->id, 'kode_aset' => 'SW-PUSAT']);
        $this->swB = Perangkat::factory()->switchUtama()->create(['opd_id' => $this->opdB->id, 'kode_aset' => 'SW-B']);
        $this->swD = Perangkat::factory()->switchUtama()->create(['opd_id' => $this->opdD->id, 'kode_aset' => 'SW-D']);

        $this->tg1 = Tiang::factory()->create(['kode' => 'TG-1']);
        $this->tg2 = Tiang::factory()->create(['kode' => 'TG-2']);

        $this->otbA = TitikJaringan::factory()->otb(4)->create(['kode' => 'OTB-A']);
        $this->cl = TitikJaringan::factory()->create(['kode' => 'CL', 'tiang_id' => $this->tg1->id]);
        $this->otbB = TitikJaringan::factory()->otb(4)->create(['kode' => 'OTB-B']);
        $this->otbC = TitikJaringan::factory()->otb(4)->create(['kode' => 'OTB-C']);

        $this->k1 = SegmenKabel::factory()->antara($this->otbA, $this->cl)->create(['kode' => 'K1']);
        $this->k2 = SegmenKabel::factory()->antara($this->cl, $this->otbB)->create(['kode' => 'K2']);
        $this->k3 = SegmenKabel::factory()->antara($this->cl, $this->otbC)->create(['kode' => 'K3']);
        $this->k4 = SegmenKabel::factory()->create(['kode' => 'K4', 'titik_awal_id' => $this->otbA->id, 'titik_akhir_id' => null]);

        $this->hubungan->aturRuteTiang($this->k1, [$this->tg1->id]);
        $this->hubungan->aturRuteTiang($this->k2, [$this->tg2->id]);

        // Jalur lengkap: SW-PUSAT - OTB-A/1 - K1/1 - CL - K2/1 - OTB-B/1 - SW-B.
        $this->hubungan->terminasikan($this->port($this->otbA, 1), $this->core($this->k1, 1));
        $this->hubungan->sambung($this->cl, $this->core($this->k1, 1), $this->core($this->k2, 1));
        $this->hubungan->terminasikan($this->port($this->otbB, 1), $this->core($this->k2, 1));
        $this->hubungan->hubungkanEndpoint($this->portPerangkat($this->swPusat, 'Te1/0/1'), port: $this->port($this->otbA, 1));
        $this->hubungan->hubungkanEndpoint($this->portPerangkat($this->swB, 'Gi0/1'), port: $this->port($this->otbB, 1));

        // Core terpakai tanpa perangkat di kedua ujung: OTB-A/2 - K1/2 - CL - K3/1 - OTB-C/1.
        $this->hubungan->terminasikan($this->port($this->otbA, 2), $this->core($this->k1, 2));
        $this->hubungan->sambung($this->cl, $this->core($this->k1, 2), $this->core($this->k3, 1));
        $this->hubungan->terminasikan($this->port($this->otbC, 1), $this->core($this->k3, 1));

        // Kabel yang ujung lainnya belum dicatat: SW-D - OTB-A/3 - K4/1 - ?
        $this->hubungan->terminasikan($this->port($this->otbA, 3), $this->core($this->k4, 1));
        $this->hubungan->hubungkanEndpoint($this->portPerangkat($this->swD, 'Gi0/24'), port: $this->port($this->otbA, 3));
    }

    private function core(SegmenKabel $segmen, int $nomor): CoreKabel
    {
        return $segmen->core()->where('nomor', $nomor)->firstOrFail();
    }

    private function port(TitikJaringan $titik, int $nomor): PortTerminasi
    {
        return $titik->port()->where('nomor', $nomor)->firstOrFail();
    }

    private function portPerangkat(Perangkat $perangkat, string $nama): PortPerangkat
    {
        return PortPerangkat::firstOrCreate(['perangkat_id' => $perangkat->id, 'nama' => $nama]);
    }

    /**
     * @return list<string>
     */
    private function namaOpd(SegmenKabel|TitikJaringan|Tiang $aset): array
    {
        return array_map(fn (array $baris) => $baris['opd']->nama, app(AnalisisDampak::class)->untuk($aset)->opd);
    }

    public function test_jalur_lengkap_ditelusuri_dari_perangkat_sampai_perangkat_seberang(): void
    {
        $hasil = app(PenelusuranJalur::class)->dariPerangkat($this->swB);

        $this->assertSame([], $hasil['keterbatasan']);
        $this->assertCount(1, $hasil['jalur']);

        $jalur = $hasil['jalur'][0];

        $this->assertTrue($jalur->lengkap());
        $this->assertSame([], $jalur->keterbatasan);
        $this->assertSame('SW-B', $jalur->ujungAwal?->perangkat->kode_aset);
        $this->assertSame('SW-PUSAT', $jalur->ujungAkhir?->perangkat->kode_aset);
        $this->assertEqualsCanonicalizing([$this->k1->id, $this->k2->id], $jalur->segmenIds);
        $this->assertEqualsCanonicalizing([$this->otbA->id, $this->cl->id, $this->otbB->id], $jalur->titikIds);
        $this->assertSame([
            [Jalur::PERANGKAT, 'SW-B port Gi0/1'],
            [Jalur::TITIK, 'OTB-B'],
            [Jalur::PORT, 'OTB-B port 1'],
            [Jalur::KABEL, 'K2 core 1 (biru)'],
            [Jalur::TITIK, 'CL'],
            [Jalur::SAMBUNGAN, 'Sambungan di CL'],
            [Jalur::KABEL, 'K1 core 1 (biru)'],
            [Jalur::TITIK, 'OTB-A'],
            [Jalur::PORT, 'OTB-A port 1'],
            [Jalur::PERANGKAT, 'SW-PUSAT port Te1/0/1'],
        ], array_map(fn (array $l) => [$l['jenis'], $l['label']], $jalur->langkah));
    }

    public function test_jalur_yang_sama_ditemukan_dari_arah_mana_pun(): void
    {
        $penelusuran = app(PenelusuranJalur::class);

        $dariB = $penelusuran->dariPerangkat($this->swB)['jalur'][0];
        $dariPusat = $penelusuran->dariPerangkat($this->swPusat)['jalur'][0];
        $dariTengah = $penelusuran->melaluiCore($this->core($this->k1, 1));

        $this->assertSame($dariB->kunci(), $dariPusat->kunci());
        $this->assertSame($dariB->kunci(), $dariTengah?->kunci());
        $this->assertTrue($dariTengah->lengkap());
        $this->assertSame('SW-PUSAT', $dariTengah->ujungAwal?->perangkat->kode_aset);
        $this->assertSame('SW-B', $dariTengah->ujungAkhir?->perangkat->kode_aset);
    }

    public function test_core_yang_tidak_dipakai_tidak_menghasilkan_jalur(): void
    {
        $this->assertNull(app(PenelusuranJalur::class)->melaluiCore($this->core($this->k1, 5)));
    }

    public function test_perangkat_tanpa_port_tercatat_dilaporkan_sebagai_keterbatasan(): void
    {
        $hasil = app(PenelusuranJalur::class)->dariPerangkat(Perangkat::factory()->create(['kode_aset' => 'SW-BARU']));

        $this->assertSame([], $hasil['jalur']);
        $this->assertStringContainsString('SW-BARU', $hasil['keterbatasan'][0]);
    }

    public function test_port_perangkat_yang_belum_dihubungkan_dilaporkan(): void
    {
        $jalur = app(PenelusuranJalur::class)->dariPort($this->portPerangkat($this->swB, 'Gi0/2'));

        $this->assertFalse($jalur->lengkap());
        $this->assertNull($jalur->ujungAkhir);
        $this->assertStringContainsString('belum dihubungkan ke port terminasi atau core', $jalur->keterbatasan[0]);
    }

    public function test_port_terminasi_tanpa_core_dilaporkan(): void
    {
        $this->hubungan->hubungkanEndpoint($this->portPerangkat($this->swB, 'Gi0/3'), port: $this->port($this->otbB, 4));

        $jalur = app(PenelusuranJalur::class)->dariPort($this->portPerangkat($this->swB, 'Gi0/3'));

        $this->assertFalse($jalur->lengkap());
        $this->assertStringContainsString('OTB-B port 4 belum diterminasi ke core', $jalur->keterbatasan[0]);
    }

    public function test_kabel_tanpa_ujung_seberang_menghentikan_penelusuran(): void
    {
        $jalur = app(PenelusuranJalur::class)->dariPerangkat($this->swD)['jalur'][0];

        $this->assertFalse($jalur->lengkap());
        $this->assertSame('SW-D', $jalur->ujungAwal?->perangkat->kode_aset);
        $this->assertNull($jalur->ujungAkhir);
        $this->assertSame(['Ujung lain kabel K4 belum dicatat.'], $jalur->keterbatasan);
    }

    public function test_ujung_core_yang_belum_dicatat_menghentikan_penelusuran(): void
    {
        $this->hubungan->lepasSambungan($this->cl->sambungan()->where('core_masuk_id', $this->core($this->k1, 1)->id)->firstOrFail());

        $jalur = app(PenelusuranJalur::class)->dariPerangkat($this->swPusat)['jalur'][0];

        $this->assertFalse($jalur->lengkap());
        $this->assertNull($jalur->ujungAkhir);
        $this->assertStringContainsString('Ujung K1 core 1 (biru) di CL belum dicatat', $jalur->keterbatasan[0]);
    }

    public function test_koneksi_langsung_ke_core_ikut_ditelusuri(): void
    {
        $sw = Perangkat::factory()->create(['kode_aset' => 'SW-LANGSUNG', 'opd_id' => $this->opdD->id]);
        $this->hubungan->terminasikan($this->port($this->otbB, 2), $this->core($this->k2, 2));
        $this->hubungan->hubungkanEndpoint($this->portPerangkat($sw, 'sfp1'), core: $this->core($this->k2, 2), titik: $this->cl);
        $this->hubungan->hubungkanEndpoint($this->portPerangkat($this->swB, 'Gi0/2'), port: $this->port($this->otbB, 2));

        $jalur = app(PenelusuranJalur::class)->dariPerangkat($sw)['jalur'][0];

        $this->assertTrue($jalur->lengkap());
        $this->assertSame('SW-B', $jalur->ujungAkhir?->perangkat->kode_aset);
        $this->assertSame([$this->k2->id], $jalur->segmenIds);
    }

    public function test_potensi_terdampak_untuk_kabel(): void
    {
        $hasil = app(AnalisisDampak::class)->untuk($this->k2);

        $this->assertSame(['Dinas B', 'Dinas Pusat'], array_map(fn (array $b) => $b['opd']->nama, $hasil->opd));
        $this->assertTrue($hasil->opd[0]['lengkap']);
        $this->assertSame(['SW-B'], array_map(fn (Perangkat $p) => $p->kode_aset, $hasil->opd[0]['perangkat']));
        $this->assertSame(1, $hasil->jumlahJalur());
        $this->assertSame(11, $hasil->coreTidakTerpakai);
        $this->assertSame([], $hasil->jalurTanpaPerangkat);
        $this->assertNotContains('Dinas D', $this->namaOpd($this->k2));
    }

    public function test_core_terpakai_yang_tidak_sampai_ke_perangkat_dilaporkan(): void
    {
        $hasil = app(AnalisisDampak::class)->untuk($this->k1);

        $this->assertSame(['Dinas B', 'Dinas Pusat'], array_map(fn (array $b) => $b['opd']->nama, $hasil->opd));
        $this->assertCount(1, $hasil->jalurTanpaPerangkat);
        $this->assertStringContainsString('1 core terpakai tidak dapat ditelusuri sampai perangkat mana pun', implode(' ', $hasil->keterbatasan));
    }

    public function test_jalur_sebelah_tetap_menghasilkan_opd_dengan_tanda_belum_lengkap(): void
    {
        $hasil = app(AnalisisDampak::class)->untuk($this->k4);

        $this->assertSame(['Dinas D'], array_map(fn (array $b) => $b['opd']->nama, $hasil->opd));
        $this->assertFalse($hasil->opd[0]['lengkap']);

        $keterbatasan = implode(' ', $hasil->keterbatasan);
        $this->assertStringContainsString('Data kabel K4 belum lengkap', $keterbatasan);
        $this->assertStringContainsString('1 jalur tidak tertelusur penuh', $keterbatasan);
    }

    public function test_potensi_terdampak_untuk_titik_dan_tiang(): void
    {
        // Closure CL: semua kabel yang berujung di sana.
        $this->assertSame(['Dinas B', 'Dinas Pusat'], $this->namaOpd($this->cl));
        $this->assertCount(3, app(AnalisisDampak::class)->untuk($this->cl)->segmen);

        // OTB-A: K1 dan K4.
        $this->assertSame(['Dinas B', 'Dinas D', 'Dinas Pusat'], $this->namaOpd($this->otbA));

        // TG-2 hanya dilalui K2.
        $this->assertSame(['Dinas B', 'Dinas Pusat'], $this->namaOpd($this->tg2));
        $this->assertCount(1, app(AnalisisDampak::class)->untuk($this->tg2)->segmen);

        // TG-1 dilalui K1 dan menjadi tempat CL, sehingga K2 dan K3 ikut diperiksa.
        $tg1 = app(AnalisisDampak::class)->untuk($this->tg1);
        $this->assertCount(3, $tg1->segmen);
        $this->assertStringContainsString('2 segmen kabel belum memiliki rute tiang', implode(' ', $tg1->keterbatasan));
    }

    public function test_aset_tanpa_kabel_dan_opd_yang_belum_terhubung_dilaporkan(): void
    {
        Perangkat::factory()->switchUtama()->create();

        $hasil = app(AnalisisDampak::class)->untuk(Tiang::factory()->create(['kode' => 'TG-KOSONG']));

        $this->assertSame([], $hasil->opd);

        $keterbatasan = implode(' ', $hasil->keterbatasan);
        $this->assertStringContainsString('Belum ada kabel yang dicatat melewati TG-KOSONG', $keterbatasan);
        $this->assertStringContainsString('1 OPD memiliki switch utama yang belum dicatat terhubung ke FO', $keterbatasan);
    }

    /**
     * Hasil hanya bergantung pada inventaris: status monitoring tidak
     * mengubahnya, dan menghitungnya tidak menghubungi perangkat.
     */
    public function test_hasil_tidak_bergantung_pada_monitoring_dan_tidak_memeriksa_jaringan(): void
    {
        Process::fake();

        $sebelum = $this->namaOpd($this->k2);
        $hasilPemeriksaan = DB::table('hasil_pemeriksaan')->count();

        $this->swB->update(['ip_manajemen' => '198.51.100.77', 'monitoring_aktif' => true]);
        $endpoint = EndpointMonitoring::query()->where('perangkat_id', $this->swB->id)->firstOrFail();
        DB::table('status_terkini')->where('endpoint_id', $endpoint->id)->update(['status' => 'tidak_terjangkau']);

        $this->assertSame($sebelum, $this->namaOpd($this->k2));

        $this->actingAs(User::factory()->operator()->create())
            ->get(route('fo.penelusuran', ['jenis' => 'kabel', 'kode' => 'K2']))
            ->assertOk();

        Process::assertNothingRan();
        $this->assertSame($hasilPemeriksaan, DB::table('hasil_pemeriksaan')->count());
    }

    public function test_halaman_potensi_terdampak_berlabel_dan_menampilkan_keterbatasan(): void
    {
        $this->actingAs(User::factory()->teknisi()->create());

        $this->get(route('fo.penelusuran', ['jenis' => 'kabel', 'kode' => 'k1']))
            ->assertOk()
            ->assertSee('Potensi terdampak berdasarkan inventaris')
            ->assertSee('Dinas B')
            ->assertSee('Keterbatasan data')
            ->assertSee('1 core terpakai tidak dapat ditelusuri sampai perangkat mana pun')
            ->assertSee('tidak menunjukkan bahwa kabel putus')
            ->assertDontSee('Dinas D');

        $this->get(route('fo.penelusuran', ['jenis' => 'tiang', 'kode' => 'TG-2']))
            ->assertOk()->assertSee('Potensi terdampak berdasarkan inventaris')->assertSee('Dinas B');

        $this->get(route('fo.penelusuran', ['jenis' => 'titik', 'kode' => 'OTB-A']))
            ->assertOk()->assertSee('Dinas D')->assertSee('Jalur belum lengkap');

        $this->get(route('fo.penelusuran', ['jenis' => 'kabel', 'kode' => 'TIDAK-ADA']))
            ->assertOk()->assertSee('data-test="aset-tidak-ditemukan"', false)->assertDontSee('data-test="potensi-terdampak"', false);
    }

    public function test_halaman_jalur_perangkat_menampilkan_langkah_dan_keterbatasan(): void
    {
        $this->actingAs(User::factory()->operator()->create());

        $this->get(route('fo.penelusuran', ['jenis' => 'perangkat', 'kode' => 'SW-B']))
            ->assertOk()
            ->assertSeeInOrder(['SW-B port Gi0/1', 'OTB-B port 1', 'K2 core 1 (biru)', 'Sambungan di CL', 'K1 core 1 (biru)', 'SW-PUSAT port Te1/0/1'])
            ->assertSee('Tercatat dari ujung ke ujung')
            ->assertDontSee('data-test="keterbatasan-jalur"', false);

        $this->get(route('fo.penelusuran', ['jenis' => 'perangkat', 'kode' => 'SW-D']))
            ->assertOk()
            ->assertSee('Jalur belum lengkap')
            ->assertSee('Ujung lain kabel K4 belum dicatat.');
    }

    public function test_viewer_dan_tamu_tidak_dapat_membuka_penelusuran(): void
    {
        $this->get(route('fo.penelusuran'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->viewer()->create())
            ->get(route('fo.penelusuran', ['jenis' => 'kabel', 'kode' => 'K1']))
            ->assertForbidden();
    }

    public function test_rincian_tiket_menampilkan_jalur_dan_potensi_terdampak(): void
    {
        $tiket = Tiket::factory()->create(['perangkat_id' => $this->swD->id, 'opd_id' => $this->opdD->id, 'segmen_kabel_id' => $this->k2->id]);

        $this->actingAs(User::factory()->operator()->create())
            ->get(route('tiket.rinci', $tiket))
            ->assertOk()
            ->assertSee('Jalur FO perangkat')
            ->assertSee('Ujung lain kabel K4 belum dicatat.')
            ->assertSee('Potensi terdampak berdasarkan inventaris')
            ->assertSee('Dinas B');
    }
}
