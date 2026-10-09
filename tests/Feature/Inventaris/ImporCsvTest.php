<?php

namespace Tests\Feature\Inventaris;

use App\Enums\AksiAktivitas;
use App\Impor\PengimporCsv;
use App\Models\LogAktivitas;
use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

class ImporCsvTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->operator()->create());
    }

    private function csv(string $isi, string $nama = 'data.csv'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nama, $isi);
    }

    private function jalur(string $isi): string
    {
        $jalur = tempnam(sys_get_temp_dir(), 'csv');

        if ($jalur === false) {
            throw new RuntimeException('Tidak dapat membuat berkas sementara.');
        }

        file_put_contents($jalur, $isi);

        return $jalur;
    }

    public function test_halaman_impor_dapat_dibuka(): void
    {
        $this->get(route('impor', 'opd'))->assertOk()->assertSee('Impor OPD dari CSV')->assertSee('kontak_teknis_nama');
        $this->get(route('impor', 'perangkat'))->assertOk()->assertSee('Impor Perangkat dari CSV')->assertSee('kode_opd');
        $this->get('/impor/lainnya')->assertNotFound();
    }

    public function test_pratinjau_melaporkan_kesalahan_per_baris_tanpa_menyimpan(): void
    {
        Opd::factory()->create(['kode' => 'SETDA', 'nama' => 'Sekretariat Lama']);

        $isi = implode("\n", [
            'kode,nama,lintang,bujur,kontak_teknis_email,aktif',
            'DINKES,Dinas Kesehatan,-7.36,109.91,dinkes@example.test,ya',
            'SETDA,Sekretariat Daerah,,,,ya',
            ',Tanpa Kode,,,,ya',
            'DISDIK,Dinas Pendidikan,-97,109.9,bukan-email,mungkin',
            'DINKES,Dinas Kesehatan Ganda,,,,ya',
            '',
        ]);

        $komponen = Livewire::test('pages::impor.index', ['jenis' => 'opd'])
            ->set('berkas', $this->csv($isi))
            ->call('pratinjau')
            ->assertHasNoErrors()
            ->assertSet('selesai', false)
            ->assertSee('Kesalahan per baris')
            ->assertSee('Kunci DINKES sudah muncul di baris 2.');

        $hasil = $komponen->get('hasil');

        $this->assertSame(['total' => 5, 'baru' => 1, 'perbarui' => 1, 'galat' => 3], $hasil['jumlah']);
        $this->assertSame([4, 5, 6], array_column($hasil['galat'], 'nomor'));
        $this->assertSame(['Kode wajib diisi.'], $hasil['galat'][0]['galat']);
        $this->assertCount(3, $hasil['galat'][1]['galat']);
        $this->assertSame(['baru', 'perbarui'], array_column($hasil['contoh'], 'tindakan'));

        // Pratinjau tidak menyimpan apa pun.
        $this->assertSame(1, Opd::query()->count());
        $this->assertSame('Sekretariat Lama', Opd::query()->where('kode', 'SETDA')->value('nama'));
        $this->assertFalse(LogAktivitas::query()->where('aksi', AksiAktivitas::ImporCsv)->exists());
    }

    public function test_impor_menyimpan_baris_valid_dan_melewati_yang_bermasalah(): void
    {
        Opd::factory()->create(['kode' => 'SETDA', 'nama' => 'Sekretariat Lama', 'alamat' => 'Alamat lama']);

        $isi = implode("\n", [
            'kode,nama,aktif',
            'dinkes,Dinas Kesehatan,ya',
            'SETDA,Sekretariat Daerah,tidak',
            'DISDIK,,ya',
        ]);

        Livewire::test('pages::impor.index', ['jenis' => 'opd'])
            ->set('berkas', $this->csv($isi))
            ->call('pratinjau')
            ->call('impor')
            ->assertHasNoErrors()
            ->assertSet('selesai', true)
            ->assertSee('Impor selesai');

        $this->assertSame(2, Opd::query()->count());
        $this->assertSame('Dinas Kesehatan', Opd::query()->where('kode', 'DINKES')->value('nama'));

        $setda = Opd::query()->where('kode', 'SETDA')->firstOrFail();
        $this->assertSame('Sekretariat Daerah', $setda->nama);
        $this->assertFalse($setda->aktif);
        // Kolom yang tidak ada di berkas tidak dikosongkan.
        $this->assertSame('Alamat lama', $setda->alamat);

        $log = LogAktivitas::query()->where('aksi', AksiAktivitas::ImporCsv)->firstOrFail();
        $this->assertSame('opd', $log->rincian['jenis'] ?? null);
        $this->assertSame(1, $log->rincian['galat'] ?? null);
    }

    public function test_titik_koma_bom_dan_desimal_koma_diterima(): void
    {
        $isi = "\xEF\xBB\xBF".implode("\r\n", [
            'Kode;Nama;Lintang;Bujur;Alamat',
            'DINKES;"Dinas Kesehatan; Kabupaten";-7,36;109,91;"Jl. Contoh, No. 5"',
        ]);

        $hasil = PengimporCsv::untuk('opd')->impor($this->jalur($isi));

        $this->assertSame([], $hasil['galat_berkas']);
        $this->assertSame(1, $hasil['jumlah']['baru']);

        $opd = Opd::query()->where('kode', 'DINKES')->firstOrFail();
        $this->assertSame('Dinas Kesehatan; Kabupaten', $opd->nama);
        $this->assertSame('-7.360000', $opd->lintang);
        $this->assertSame('Jl. Contoh, No. 5', $opd->alamat);
    }

    public function test_judul_kolom_yang_salah_dilaporkan(): void
    {
        $hasil = PengimporCsv::untuk('opd')->pratinjau($this->jalur("kode,nama_opd,nama_opd\nA,B,C\n"));

        $this->assertSame(0, $hasil['jumlah']['total']);
        $this->assertStringContainsString('Kolom muncul lebih dari sekali: nama_opd', implode(' ', $hasil['galat_berkas']));
        $this->assertStringContainsString('Kolom tidak dikenal: nama_opd', implode(' ', $hasil['galat_berkas']));
        $this->assertStringContainsString('Kolom wajib tidak ada: nama', implode(' ', $hasil['galat_berkas']));

        $kosong = PengimporCsv::untuk('opd')->pratinjau($this->jalur("kode,nama\n"));
        $this->assertSame(['Berkas tidak berisi baris data.'], $kosong['galat_berkas']);

        Livewire::test('pages::impor.index', ['jenis' => 'opd'])
            ->set('berkas', $this->csv("kode,nama_opd\nA,B\n"))
            ->call('pratinjau')
            ->assertSee('Kolom tidak dikenal: nama_opd');
    }

    public function test_baris_dengan_kolom_berlebih_dilaporkan(): void
    {
        $hasil = PengimporCsv::untuk('opd')->pratinjau($this->jalur("kode,nama\nA,Dinas A\nB,Dinas B,lebih,lagi\n"));

        $this->assertSame(['total' => 2, 'baru' => 1, 'perbarui' => 0, 'galat' => 1], $hasil['jumlah']);
        $this->assertSame(3, $hasil['baris'][1]['nomor']);
        $this->assertStringContainsString('Jumlah kolom (4) melebihi judul (2)', $hasil['baris'][1]['galat'][0]);
    }

    public function test_impor_perangkat(): void
    {
        $dinkes = Opd::factory()->create(['kode' => 'DINKES']);
        $setda = Opd::factory()->create(['kode' => 'SETDA']);
        $utamaLama = Perangkat::factory()->switchUtama()->create(['opd_id' => $setda->id, 'kode_aset' => 'SW-SETDA-LAMA']);
        Perangkat::factory()->create(['opd_id' => $dinkes->id, 'kode_aset' => 'AP-DINKES-01', 'nama' => 'AP Lama', 'ip_manajemen' => '198.51.100.50']);

        $isi = implode("\n", [
            'kode_aset,nama,kode_opd,jenis,ip_manajemen,monitoring_aktif,switch_utama,simulasi',
            'SW-DINKES-01,Switch Utama Dinkes,dinkes,Switch,198.51.100.1,ya,ya,',       // 2 valid
            'SW-DINKES-02,Switch Kedua Dinkes,DINKES,switch,198.51.100.2,tidak,ya,',     // 3 utama kedua
            'RT-DINKES-01,Router Dinkes,DINKES,router,198.51.100.1,tidak,tidak,',        // 4 IP ganda
            'SW-X-01,Switch X,TIDAKADA,switch,198.51.100.4,tidak,tidak,',                // 5 OPD tak dikenal
            'SW-Y-01,Switch Y,DINKES,kulkas,bukan-ip,ya,tidak,',                         // 6 jenis & IP
            'SW-Z-01,Switch Z,DINKES,switch,,ya,tidak,',                                 // 7 monitoring tanpa IP
            'SW-SETDA-BARU,Switch Baru Setda,SETDA,switch,198.51.100.7,tidak,ya,',       // 8 sudah ada utama
            'AP-DINKES-01,AP Baru,DINKES,access_point,198.51.100.50,tidak,tidak,',       // 9 perbarui
            'SIM-01,Perangkat Contoh,DINKES,lainnya,,tidak,tidak,ya',                    // 10 simulasi
        ]);

        $pratinjau = PengimporCsv::untuk('perangkat')->pratinjau($this->jalur($isi));

        $this->assertSame(['total' => 9, 'baru' => 2, 'perbarui' => 1, 'galat' => 6], $pratinjau['jumlah']);
        $this->assertSame(2, Perangkat::query()->count());

        $galat = collect($pratinjau['baris'])->keyBy('nomor')->map(fn (array $b) => implode(' ', $b['galat']));

        $this->assertStringContainsString('sudah memiliki switch utama (SW-DINKES-01)', $galat[3]);
        $this->assertStringContainsString('IP manajemen sudah dipakai perangkat lain.', $galat[4]);
        $this->assertStringContainsString('OPD dengan kode TIDAKADA tidak ditemukan.', $galat[5]);
        $this->assertStringContainsString('Jenis yang dipilih tidak dikenal.', $galat[6]);
        $this->assertStringContainsString('IP manajemen harus berupa alamat IP yang valid.', $galat[6]);
        $this->assertStringContainsString('IP manajemen wajib diisi bila monitoring diaktifkan.', $galat[7]);
        $this->assertStringContainsString('sudah memiliki switch utama (SW-SETDA-LAMA)', $galat[8]);

        $hasil = PengimporCsv::untuk('perangkat')->impor($this->jalur($isi));

        $this->assertSame($pratinjau['jumlah'], $hasil['jumlah']);
        $this->assertSame(4, Perangkat::query()->count());

        $utama = Perangkat::query()->where('kode_aset', 'SW-DINKES-01')->firstOrFail();
        $this->assertSame($dinkes->id, $utama->opd_id);
        $this->assertTrue($utama->switch_utama);
        $this->assertTrue($utama->monitoring_aktif);
        $this->assertTrue($utamaLama->refresh()->switch_utama);
        $this->assertSame('AP Baru', Perangkat::query()->where('kode_aset', 'AP-DINKES-01')->value('nama'));

        $simulasi = Perangkat::query()->where('kode_aset', 'SIM-01')->firstOrFail();
        $this->assertTrue($simulasi->simulasi);
        $this->assertSame('[SIMULASI] Perangkat Contoh', $simulasi->nama);
    }

    public function test_berkas_bukan_csv_ditolak(): void
    {
        Livewire::test('pages::impor.index', ['jenis' => 'opd'])
            ->set('berkas', UploadedFile::fake()->image('foto.png'))
            ->call('pratinjau')
            ->assertHasErrors(['berkas'])
            ->assertSet('hasil', null);

        Livewire::test('pages::impor.index', ['jenis' => 'opd'])
            ->call('pratinjau')
            ->assertHasErrors(['berkas']);
    }

    public function test_templat_dan_laporan_kesalahan_dapat_diunduh(): void
    {
        Livewire::test('pages::impor.index', ['jenis' => 'perangkat'])
            ->call('unduhTemplat')
            ->assertFileDownloaded('templat-perangkat.csv');

        $templat = PengimporCsv::untuk('perangkat')->templat();

        $this->assertStringContainsString('kode_aset,nama,kode_opd', $templat);
        $this->assertStringNotContainsStringIgnoringCase('snmp', $templat);
        $this->assertStringNotContainsStringIgnoringCase('community', $templat);

        Livewire::test('pages::impor.index', ['jenis' => 'opd'])
            ->set('berkas', $this->csv("kode,nama\nA,\n"))
            ->call('pratinjau')
            ->call('unduhGalat')
            ->assertFileDownloaded('kesalahan-impor-opd.csv');
    }

    public function test_templat_dapat_diimpor_kembali(): void
    {
        Opd::factory()->create(['kode' => 'SIM-DINKES']);

        foreach (PengimporCsv::JENIS as $jenis) {
            $hasil = PengimporCsv::untuk($jenis)->pratinjau($this->jalur(PengimporCsv::untuk($jenis)->templat()));

            $this->assertSame([], $hasil['galat_berkas'], $jenis);
            $this->assertSame(0, $hasil['jumlah']['galat'], $jenis.': '.json_encode($hasil['baris']));
        }
    }
}
