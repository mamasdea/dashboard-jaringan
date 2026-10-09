<?php

namespace Tests\Feature\Peta;

use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\SegmenKabel;
use App\Models\Tiang;
use App\Models\TitikJaringan;
use App\Models\User;
use App\Support\KodeQr;
use App\Support\TautanAset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class QrDanLabelTest extends TestCase
{
    use RefreshDatabase;

    private Opd $opd;

    private Perangkat $perangkat;

    private Tiang $tiang;

    private TitikJaringan $titik;

    private SegmenKabel $segmen;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://jaringan.wonosobokab.example.test/']);

        $this->opd = Opd::factory()->create(['kode' => 'DINKES', 'nama' => 'Dinas Kesehatan', 'kontak_teknis_telepon' => '0286 555001']);
        $this->perangkat = Perangkat::factory()->create([
            'opd_id' => $this->opd->id, 'kode_aset' => 'SW-DINKES-01', 'nama' => 'Switch Utama Dinkes',
            'ip_manajemen' => '198.51.100.77', 'nomor_seri' => 'SERI-123',
        ]);
        $this->tiang = Tiang::factory()->create(['kode' => 'TG-001']);
        $this->titik = TitikJaringan::factory()->otb(2)->create(['kode' => 'OTB-01']);
        $this->segmen = SegmenKabel::factory()->create(['kode' => 'BB-01']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function aset(): array
    {
        return [
            'opd' => ['opd', 'DINKES'],
            'perangkat' => ['perangkat', 'SW-DINKES-01'],
            'tiang' => ['tiang', 'TG-001'],
            'titik' => ['titik', 'OTB-01'],
            'kabel' => ['kabel', 'BB-01'],
        ];
    }

    private function model(string $jenis): Model
    {
        return match ($jenis) {
            'opd' => $this->opd,
            'perangkat' => $this->perangkat,
            'tiang' => $this->tiang,
            'titik' => $this->titik,
            default => $this->segmen,
        };
    }

    #[DataProvider('aset')]
    public function test_isi_qr_hanya_alamat_halaman_detail(string $jenis, string $kode): void
    {
        $isi = TautanAset::url($this->model($jenis));

        // APP_URL + jenis + kode; tanpa query string, token, atau IP perangkat.
        $this->assertSame("https://jaringan.wonosobokab.example.test/aset/{$jenis}/{$kode}", $isi);
        $this->assertNull(parse_url($isi, PHP_URL_QUERY));
        $this->assertNull(parse_url($isi, PHP_URL_USER));
        $this->assertNull(parse_url($isi, PHP_URL_FRAGMENT));
        $this->assertStringNotContainsString('198.51.100.77', $isi);

        $svg = KodeQr::svg($isi);

        $this->assertStringStartsWith('<svg', $svg);
        $this->assertStringNotContainsString('<?xml', $svg);
    }

    public function test_qr_berbeda_untuk_isi_yang_berbeda(): void
    {
        $this->assertNotSame(KodeQr::svg(TautanAset::url($this->tiang)), KodeQr::svg(TautanAset::url($this->perangkat)));
        $this->assertSame(KodeQr::svg('https://contoh.test/a'), KodeQr::svg('https://contoh.test/a'));
    }

    #[DataProvider('aset')]
    public function test_halaman_detail_mengikuti_autentikasi_dan_hak_akses(string $jenis, string $kode): void
    {
        $jalur = "/aset/{$jenis}/{$kode}";

        // Tamu yang memindai QR diarahkan masuk, lalu kembali ke halaman yang dituju.
        $this->get($jalur)->assertRedirect(route('login'));
        $this->assertSame(url($jalur), session('url.intended'));

        $this->actingAs(User::factory()->viewer()->create());
        $this->get($jalur)->assertForbidden();
        // Viewer tidak dapat membedakan kode yang ada dari yang tidak ada.
        $this->get("/aset/{$jenis}/TIDAK-ADA")->assertForbidden();

        $this->actingAs(User::factory()->teknisi()->create());

        $jawaban = $this->get($jalur);

        match ($jenis) {
            'titik' => $jawaban->assertRedirect(route('fo.titik.rinci', $this->titik)),
            'kabel' => $jawaban->assertRedirect(route('fo.kabel.rinci', $this->segmen)),
            default => $jawaban->assertOk()->assertSee($kode)->assertSee('data-test="kartu-qr"', false),
        };

        $this->get("/aset/{$jenis}/TIDAK-ADA")->assertNotFound();
        $this->get('/aset/'.$jenis.'/'.mb_strtolower($kode))->assertStatus($jawaban->getStatusCode());
    }

    public function test_jenis_aset_yang_tidak_dikenal_tidak_ditemukan(): void
    {
        $this->actingAs(User::factory()->administrator()->create());

        $this->get('/aset/pengguna/1')->assertNotFound();
        $this->get('/label/pengguna?kode=1')->assertNotFound();
        $this->get('/aset/tiang/TG-001%20OR%201')->assertNotFound();
    }

    public function test_halaman_detail_perangkat_dan_tiang(): void
    {
        $this->actingAs(User::factory()->teknisi()->create());

        $this->get('/aset/perangkat/SW-DINKES-01')
            ->assertOk()
            ->assertSee('Switch Utama Dinkes')
            ->assertSee('DINKES — Dinas Kesehatan')
            // Rincian teknis hanya di halaman yang sudah lolos hak akses, bukan di QR.
            ->assertSee('198.51.100.77')
            ->assertSee('data-qr-isi="https://jaringan.wonosobokab.example.test/aset/perangkat/SW-DINKES-01"', false)
            ->assertSee('Cetak label')
            ->assertSee('Lihat di peta');

        $this->get('/aset/tiang/TG-001')->assertOk()->assertSee('Pemilik')->assertSee('Kabel yang lewat');
        $this->get('/aset/opd/DINKES')->assertOk()->assertSee('Kontak teknis')->assertSee('0286 555001');
    }

    public function test_halaman_rincian_titik_dan_segmen_memuat_qr(): void
    {
        $this->actingAs(User::factory()->teknisi()->create());

        $this->get(route('fo.titik.rinci', $this->titik))
            ->assertOk()
            ->assertSee('data-qr-isi="https://jaringan.wonosobokab.example.test/aset/titik/OTB-01"', false);

        $this->get(route('fo.kabel.rinci', $this->segmen))
            ->assertOk()
            ->assertSee('data-qr-isi="https://jaringan.wonosobokab.example.test/aset/kabel/BB-01"', false);
    }

    public function test_label_cetak_berisi_kode_dan_qr_tanpa_data_sensitif(): void
    {
        Perangkat::factory()->simulasi()->create(['opd_id' => $this->opd->id, 'kode_aset' => 'SIM-SW-02', 'ip_manajemen' => '198.51.100.88']);

        $this->actingAs(User::factory()->teknisi()->create());

        $jawaban = $this->get('/label/perangkat?kode=sw-dinkes-01, SIM-SW-02,TIDAK-ADA,<script>')->assertOk();
        $isi = (string) $jawaban->getContent();

        $jawaban
            ->assertSee('SW-DINKES-01')
            ->assertSee('SIM-SW-02')
            ->assertSee('SIMULASI')
            ->assertSee('Tidak ditemukan: TIDAK-ADA.')
            ->assertSee('data-qr-isi="https://jaringan.wonosobokab.example.test/aset/perangkat/SW-DINKES-01"', false)
            ->assertDontSee('<script>', false);

        $this->assertSame(2, substr_count($isi, 'data-test="label-aset"'));
        $this->assertSame(2, substr_count($isi, '<svg'));

        // Yang tercetak adalah isi lembar label; bilah tombol dan catatan kaki disembunyikan saat mencetak.
        $lembar = substr($isi, (int) strpos($isi, '<div class="lembar">'), (int) strpos($isi, '<p class="catatan">') - (int) strpos($isi, '<div class="lembar">'));

        $this->assertStringContainsString('SW-DINKES-01', $lembar);

        // Label tidak memuat IP, nomor seri, nama perangkat, kontak, token, maupun query string.
        foreach (['198.51.100', 'SERI-123', 'Switch Utama Dinkes', '0286 555001', 'token', 'password', 'csrf', '?'] as $terlarang) {
            $this->assertStringNotContainsStringIgnoringCase($terlarang, $lembar);
        }

        // IP dan nomor seri juga tidak ada di bagian lain halaman.
        foreach (['198.51.100', 'SERI-123', '0286 555001'] as $terlarang) {
            $this->assertStringNotContainsString($terlarang, $isi);
        }

        $this->get('/label/tiang?kode=TG-001')->assertOk()->assertSee('TG-001')->assertSee('Tiang');
        $this->get('/label/tiang')->assertOk()->assertSee('Tidak ada aset untuk dicetak');
    }

    public function test_label_mengikuti_hak_akses(): void
    {
        $this->get('/label/perangkat?kode=SW-DINKES-01')->assertRedirect(route('login'));

        $this->actingAs(User::factory()->viewer()->create());

        $this->get('/label/perangkat?kode=SW-DINKES-01')->assertForbidden();
        $this->get('/label/tiang?kode=TG-001')->assertForbidden();
    }

    public function test_daftar_menyediakan_cetak_label_untuk_aset_yang_tampil(): void
    {
        $this->actingAs(User::factory()->teknisi()->create());

        $this->get(route('perangkat.index'))->assertOk()->assertSee('data-test="cetak-label"', false)->assertSee('kode=SW-DINKES-01', false);
        $this->get(route('fo.tiang'))->assertOk()->assertSee('data-test="cetak-label"', false)->assertSee('kode=TG-001', false);
        $this->get(route('fo.titik'))->assertOk()->assertSee('data-test="cetak-label"', false);
    }

    public function test_tautan_peta_memuat_fokus_aset(): void
    {
        $this->assertStringEndsWith('/peta?fokus=tiang%3ATG-001', TautanAset::urlPeta($this->tiang));
        $this->assertStringEndsWith('/peta?fokus=kabel%3ABB-01', TautanAset::urlPeta($this->segmen));
    }
}
