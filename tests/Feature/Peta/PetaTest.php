<?php

namespace Tests\Feature\Peta;

use App\Enums\Peran;
use App\Fo\LayananHubungan;
use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\SegmenKabel;
use App\Models\Tiang;
use App\Models\TitikJaringan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PetaTest extends TestCase
{
    use RefreshDatabase;

    private Opd $opd;

    private Perangkat $utama;

    private Tiang $tiang;

    private TitikJaringan $otb;

    private TitikJaringan $closure;

    private SegmenKabel $backbone;

    private SegmenKabel $tanpaJalur;

    protected function setUp(): void
    {
        parent::setUp();

        $this->opd = Opd::factory()->create(['kode' => 'DINKES', 'nama' => 'Dinas Kesehatan', 'lintang' => -7.3600, 'bujur' => 109.9000]);
        $this->utama = Perangkat::factory()->switchUtama()->dipantau()->create([
            'opd_id' => $this->opd->id, 'kode_aset' => 'SW-DINKES-01', 'ip_manajemen' => '198.51.100.77',
            'nomor_seri' => 'SERI-RAHASIA-1', 'catatan' => 'Catatan internal perangkat',
        ]);
        Perangkat::factory()->create(['opd_id' => $this->opd->id, 'kode_aset' => 'AP-DINKES-02']);
        $this->tiang = Tiang::factory()->create(['kode' => 'TG-001', 'lintang' => -7.3610, 'bujur' => 109.9010]);
        Tiang::factory()->belumLengkap()->create(['kode' => 'TG-TANPA-POSISI']);
        $this->otb = TitikJaringan::factory()->otb(4)->create(['kode' => 'OTB-DINKES', 'opd_id' => $this->opd->id, 'lintang' => null, 'bujur' => null]);
        $this->closure = TitikJaringan::factory()->create(['kode' => 'CL-01', 'tiang_id' => $this->tiang->id, 'lintang' => null, 'bujur' => null]);
        $this->backbone = SegmenKabel::factory()->antara($this->otb, $this->closure)->create([
            'kode' => 'BB-01', 'klasifikasi' => 'backbone', 'jalur_peta' => [[109.9000, -7.3600], [109.9010, -7.3610]],
        ]);
        $this->tanpaJalur = SegmenKabel::factory()->antara($this->closure, TitikJaringan::factory()->create(['kode' => 'CL-02']))->create([
            'kode' => 'DS-01', 'klasifikasi' => 'distribusi', 'jalur_peta' => null,
        ]);
        app(LayananHubungan::class)->aturRuteTiang($this->backbone, [$this->tiang->id]);
    }

    /**
     * @return array<string, array{Peran}>
     */
    public static function yangBolehMelihat(): array
    {
        return ['administrator' => [Peran::Administrator], 'operator' => [Peran::Operator], 'teknisi' => [Peran::Teknisi]];
    }

    #[DataProvider('yangBolehMelihat')]
    public function test_halaman_peta_dapat_dibuka(Peran $peran): void
    {
        $this->actingAs(User::factory()->peran($peran)->create());

        $jawaban = $this->get(route('peta'))->assertOk()
            ->assertSee('Peta jaringan')
            ->assertSee('data-peta-kanvas', false)
            ->assertSee('Jalur backbone')
            ->assertSee('Jalur distribusi')
            ->assertSee('Terminasi/OTB')
            ->assertSee('Cari kode aset')
            ->assertSee('Dinas Kesehatan');

        $bolehUbah = $peran !== Peran::Teknisi;

        $bolehUbah
            ? $jawaban->assertSee('data-peta-mode-ubah', false)->assertSee('Gambar jalur kabel')
            : $jawaban->assertDontSee('data-peta-mode-ubah', false)->assertDontSee('Gambar jalur kabel');
    }

    public function test_viewer_dan_tamu_tidak_dapat_membuka_peta(): void
    {
        $this->get(route('peta'))->assertRedirect(route('login'));
        $this->get(route('peta.data'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->viewer()->create());

        $this->get(route('peta'))->assertForbidden();
        $this->get(route('peta.data'))->assertForbidden();
        Livewire::test('pages::peta.index')->assertForbidden();
        $this->get(route('dashboard'))->assertOk()->assertDontSee(route('peta'));
    }

    public function test_penyedia_tile_dapat_dikonfigurasi_dan_atribusi_dikirim_ke_peta(): void
    {
        $this->actingAs(User::factory()->teknisi()->create());

        $bawaan = Livewire::test('pages::peta.index')->instance()->konfigurasi;

        $this->assertSame('https://tile.openstreetmap.org/{z}/{x}/{y}.png', $bawaan['tile']['url']);
        $this->assertStringContainsString('OpenStreetMap', $bawaan['tile']['atribusi']);
        $this->assertFalse($bawaan['bolehUbah']);

        config([
            'peta.tile.url' => 'https://tile.intra.example.test/{z}/{x}/{y}.png',
            'peta.tile.atribusi' => 'Peta dasar: Bappeda Wonosobo',
            'peta.pusat.lintang' => -7.4,
            'peta.zoom' => 12,
        ]);

        $khusus = Livewire::test('pages::peta.index', ['fokus' => 'tiang:TG-001'])->instance()->konfigurasi;

        $this->assertSame('https://tile.intra.example.test/{z}/{x}/{y}.png', $khusus['tile']['url']);
        $this->assertSame('Peta dasar: Bappeda Wonosobo', $khusus['tile']['atribusi']);
        $this->assertSame([-7.4, 109.9032], $khusus['pusat']);
        $this->assertSame(12, $khusus['zoom']);
        $this->assertSame('tiang:TG-001', $khusus['fokus']);

        // Konfigurasi itu benar-benar sampai ke halaman.
        $this->get(route('peta'))->assertSee('tile.intra.example.test', false)->assertSee('Peta dasar: Bappeda Wonosobo', false);
    }

    public function test_data_peta_memuat_semua_jenis_aset(): void
    {
        $this->actingAs(User::factory()->teknisi()->create());

        $data = $this->getJson(route('peta.data'))->assertOk()->json();

        $this->assertSame(['opd', 'perangkat', 'tiang', 'titik', 'segmen'], array_keys($data));

        $opd = collect($data['opd'])->firstWhere('kode', 'DINKES');
        $this->assertSame([-7.36, 109.9], [$opd['lat'], $opd['lng']]);
        $this->assertSame('/aset/opd/DINKES', $opd['url']);

        // Hanya switch utama yang digambar, di lokasi OPD-nya.
        $this->assertSame(['SW-DINKES-01'], array_column($data['perangkat'], 'kode'));
        $this->assertSame([-7.36, 109.9], [$data['perangkat'][0]['lat'], $data['perangkat'][0]['lng']]);
        $this->assertSame([$this->opd->id], $data['perangkat'][0]['opd_ids']);

        $tiang = collect($data['tiang'])->keyBy('kode');
        $this->assertSame(-7.361, $tiang['TG-001']['lat']);
        $this->assertNull($tiang['TG-TANPA-POSISI']['lat']);
        $this->assertTrue($tiang['TG-TANPA-POSISI']['perlu_dilengkapi']);
        // Tiang ikut tersaring per OPD lewat kabel yang melewatinya.
        $this->assertSame([$this->opd->id], $tiang['TG-001']['opd_ids']);

        $titik = collect($data['titik'])->keyBy('kode');
        $this->assertSame(['otb', 'opd', -7.36], [$titik['OTB-DINKES']['jenis'], $titik['OTB-DINKES']['posisi_dari'], $titik['OTB-DINKES']['lat']]);
        $this->assertSame(['closure', 'tiang', -7.361], [$titik['CL-01']['jenis'], $titik['CL-01']['posisi_dari'], $titik['CL-01']['lat']]);
        $this->assertSame('sendiri', $titik['CL-02']['posisi_dari']);

        $segmen = collect($data['segmen'])->keyBy('kode');
        $this->assertSame('backbone', $segmen['BB-01']['klasifikasi']);
        // Leaflet memakai [lintang, bujur].
        $this->assertSame([[-7.36, 109.9], [-7.361, 109.901]], $segmen['BB-01']['jalur']);
        $this->assertSame([$this->opd->id], $segmen['BB-01']['opd_ids']);
        $this->assertSame('distribusi', $segmen['DS-01']['klasifikasi']);
        $this->assertSame([], $segmen['DS-01']['jalur']);
    }

    public function test_data_peta_tidak_memuat_ip_dan_data_sensitif(): void
    {
        $this->actingAs(User::factory()->administrator()->create());

        $mentah = (string) $this->getJson(route('peta.data'))->assertOk()->getContent();

        foreach (['198.51.100.77', 'SERI-RAHASIA-1', 'Catatan internal perangkat', 'ip_manajemen', 'kontak', 'password', 'token', 'snmp'] as $terlarang) {
            $this->assertStringNotContainsStringIgnoringCase($terlarang, $mentah);
        }
    }

    public function test_operator_dapat_menggeser_koordinat_aset(): void
    {
        $this->actingAs(User::factory()->operator()->create());

        $komponen = Livewire::test('pages::peta.index');

        $komponen->call('simpanKoordinat', 'tiang', $this->tiang->id, -7.3625554, 109.9033336)->assertReturned(['ok' => true, 'pesan' => 'Koordinat disimpan.']);
        $this->assertSame(['-7.362555', '109.903334'], [$this->tiang->refresh()->lintang, $this->tiang->bujur]);

        $komponen->call('simpanKoordinat', 'opd', $this->opd->id, -7.37, 109.91);
        $this->assertSame('-7.370000', $this->opd->refresh()->lintang);

        // Titik yang tadinya mengikuti posisi tiang kini punya koordinat sendiri.
        $komponen->call('simpanKoordinat', 'titik', $this->closure->id, -7.3611, 109.9011);
        $this->assertSame('-7.361100', $this->closure->refresh()->lintang);

        foreach ([[-97, 109.9], [-7.3, 190], ['abc', 109.9], [null, null]] as [$lintang, $bujur]) {
            $komponen->call('simpanKoordinat', 'tiang', $this->tiang->id, $lintang, $bujur)->assertReturned(['ok' => false, 'pesan' => 'Koordinat tidak valid.']);
        }

        $this->assertSame('-7.362555', $this->tiang->refresh()->lintang);

        // Perangkat tidak punya koordinat sendiri; jenis lain ditolak.
        $komponen->call('simpanKoordinat', 'perangkat', $this->utama->id, -7.3, 109.9)->assertNotFound();
    }

    public function test_operator_dapat_menggambar_dan_menyunting_jalur_kabel(): void
    {
        $this->actingAs(User::factory()->operator()->create());

        $komponen = Livewire::test('pages::peta.index');

        // Menggambar jalur segmen yang belum punya jalur.
        $komponen->call('simpanJalur', $this->tanpaJalur->id, [[-7.36, 109.90], [-7.36, 109.91]]);

        $this->tanpaJalur->refresh();
        $this->assertSame([[109.9, -7.36], [109.91, -7.36]], $this->tanpaJalur->jalur_peta);
        $this->assertEqualsWithDelta(1102.8, (float) $this->tanpaJalur->panjang_peta_m, 2.0);
        // Panjang hasil pengukuran tidak tersentuh.
        $this->assertSame('500.00', $this->tanpaJalur->panjang_ukur_m);

        // Menyunting jalur yang sudah ada.
        $komponen->call('simpanJalur', $this->backbone->id, [[-7.36, 109.90], [-7.3605, 109.9005], [-7.361, 109.901]]);
        $this->assertCount(3, (array) $this->backbone->refresh()->jalur_peta);

        foreach ([
            [[-7.36, 109.90]],
            'bukan daftar',
            [[-7.36, 109.90], [-97, 109.91]],
            [[-7.36, 109.90], ['x', 'y']],
            [[-7.36, 109.90], [-7.36]],
        ] as $jalur) {
            $komponen->call('simpanJalur', $this->backbone->id, $jalur)->assertReturned(fn (array $hasil) => $hasil['ok'] === false);
        }

        $this->assertCount(3, (array) $this->backbone->refresh()->jalur_peta);
    }

    /**
     * @return array<string, array{string, list<mixed>}>
     */
    public static function aksiPengubah(): array
    {
        return [
            'simpanKoordinat tiang' => ['simpanKoordinat', ['tiang', 'tiang', -7.5, 109.5]],
            'simpanKoordinat opd' => ['simpanKoordinat', ['opd', 'opd', -7.5, 109.5]],
            'simpanKoordinat titik' => ['simpanKoordinat', ['titik', 'closure', -7.5, 109.5]],
            'simpanJalur' => ['simpanJalur', ['backbone', [[-7.5, 109.5], [-7.6, 109.6]]]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function potret(): array
    {
        return [
            'tiang' => $this->tiang->refresh()->lintang,
            'opd' => $this->opd->refresh()->lintang,
            'closure' => $this->closure->refresh()->lintang,
            'jalur' => $this->backbone->refresh()->jalur_peta,
        ];
    }

    /**
     * @param  list<mixed>  $argumen
     */
    #[DataProvider('aksiPengubah')]
    public function test_teknisi_dapat_melihat_peta_tetapi_tidak_dapat_mengubah(string $aksi, array $argumen): void
    {
        $this->actingAs(User::factory()->teknisi()->create());
        $sebelum = $this->potret();

        Livewire::test('pages::peta.index')->assertOk()->call($aksi, ...$this->susun($aksi, $argumen))->assertForbidden();

        $this->assertSame($sebelum, $this->potret());
    }

    /**
     * Request palsu: snapshot komponen milik Operator dikirim ulang oleh Viewer.
     *
     * @param  list<mixed>  $argumen
     */
    #[DataProvider('aksiPengubah')]
    public function test_viewer_tidak_dapat_mengubah_peta_dengan_request_langsung(string $aksi, array $argumen): void
    {
        $operator = User::factory()->operator()->create();
        $viewer = User::factory()->viewer()->create();
        $sebelum = $this->potret();

        $uji = Livewire::actingAs($operator)->test('pages::peta.index');

        $this->actingAs($viewer);

        $uji->call($aksi, ...$this->susun($aksi, $argumen))->assertForbidden();

        $this->assertSame($sebelum, $this->potret());
    }

    /**
     * Mengganti nama objek di data provider dengan ID-nya.
     *
     * @param  list<mixed>  $argumen
     * @return list<mixed>
     */
    private function susun(string $aksi, array $argumen): array
    {
        if ($aksi === 'simpanJalur') {
            return [$this->backbone->id, $argumen[1]];
        }

        $id = match ($argumen[1]) {
            'tiang' => $this->tiang->id,
            'opd' => $this->opd->id,
            default => $this->closure->id,
        };

        return [$argumen[0], $id, $argumen[2], $argumen[3]];
    }
}
