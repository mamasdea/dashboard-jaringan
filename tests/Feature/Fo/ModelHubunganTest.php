<?php

namespace Tests\Feature\Fo;

use App\Enums\AksiHubungan;
use App\Enums\JenisHubungan;
use App\Enums\StatusData;
use App\Enums\StatusPemakaian;
use App\Fo\LayananHubungan;
use App\Models\CoreKabel;
use App\Models\KoneksiEndpoint;
use App\Models\Perangkat;
use App\Models\PortPerangkat;
use App\Models\PortTerminasi;
use App\Models\RiwayatHubungan;
use App\Models\Sambungan;
use App\Models\SegmenKabel;
use App\Models\Tiang;
use App\Models\TitikJaringan;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

/**
 * Model data FO dan aturan koneksinya, di tingkat layanan dan database.
 *
 *   OTB-A ==[kabel A, 12 core]== CL (closure) ==[kabel B, 12 core]== OTB-B
 */
class ModelHubunganTest extends TestCase
{
    use RefreshDatabase;

    private LayananHubungan $layanan;

    private TitikJaringan $otbA;

    private TitikJaringan $closure;

    private TitikJaringan $otbB;

    private SegmenKabel $kabelA;

    private SegmenKabel $kabelB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->layanan = app(LayananHubungan::class);
        $this->otbA = TitikJaringan::factory()->otb(12)->create(['kode' => 'OTB-A']);
        $this->closure = TitikJaringan::factory()->create(['kode' => 'CL']);
        $this->otbB = TitikJaringan::factory()->otb(12)->create(['kode' => 'OTB-B']);
        $this->kabelA = SegmenKabel::factory()->antara($this->otbA, $this->closure)->create(['kode' => 'KBL-A']);
        $this->kabelB = SegmenKabel::factory()->antara($this->closure, $this->otbB)->create(['kode' => 'KBL-B']);
    }

    private function core(SegmenKabel $segmen, int $nomor): CoreKabel
    {
        return $segmen->core()->where('nomor', $nomor)->firstOrFail();
    }

    private function port(TitikJaringan $titik, int $nomor): PortTerminasi
    {
        return $titik->port()->where('nomor', $nomor)->firstOrFail();
    }

    private function portPerangkat(string $nama = 'Gi0/1'): PortPerangkat
    {
        return PortPerangkat::create(['perangkat_id' => Perangkat::factory()->create()->id, 'nama' => $nama]);
    }

    private function pesanGagal(callable $aksi): string
    {
        try {
            $aksi();
        } catch (ValidationException $e) {
            return $e->validator->errors()->first('hubungan');
        }

        $this->fail('Seharusnya ditolak.');
    }

    public function test_core_dibuat_otomatis_dengan_nomor_tube_dan_warna(): void
    {
        $segmen = SegmenKabel::factory()->create(['jumlah_core' => 24]);

        $this->assertSame(24, $segmen->core()->count());
        $this->assertSame(['biru', 'oranye', 'hijau'], $segmen->core()->limit(3)->pluck('warna')->all());

        $core13 = $this->core($segmen, 13);
        $this->assertSame('biru', $core13->warna);
        $this->assertSame(2, $core13->tube);
        $this->assertSame('toska', $this->core($segmen, 12)->warna);
        $this->assertSame(StatusPemakaian::Tersedia, $core13->status);

        $segmen->update(['jumlah_core' => 12]);
        $this->assertSame(12, $segmen->core()->count());

        $segmen->update(['jumlah_core' => 48]);
        $this->assertSame(range(1, 48), $segmen->core()->pluck('nomor')->all());
    }

    public function test_port_terminasi_dibuat_dari_kapasitas(): void
    {
        $this->assertSame(range(1, 12), $this->otbA->port()->pluck('nomor')->all());
        $this->assertSame(0, $this->closure->port()->count());

        $this->otbA->update(['kapasitas_port' => 24]);
        $this->assertSame(24, $this->otbA->port()->count());
    }

    public function test_klasifikasi_adalah_atribut_segmen(): void
    {
        $backbone = SegmenKabel::factory()->create(['klasifikasi' => 'backbone']);

        $this->assertSame('Backbone', $backbone->klasifikasi?->label());
        $this->assertSame('Distribusi', $this->kabelA->klasifikasi?->label());

        $this->expectException(QueryException::class);

        DB::table('segmen_kabel')->where('id', $backbone->id)->update(['klasifikasi' => 'feeder']);
    }

    public function test_kabel_melewati_banyak_tiang_berurutan_dan_tiang_dilalui_banyak_kabel(): void
    {
        [$t1, $t2, $t3] = Tiang::factory()->count(3)->sequence(['kode' => 'T1'], ['kode' => 'T2'], ['kode' => 'T3'])->create()->all();

        $this->layanan->aturRuteTiang($this->kabelA, [$t3->id, $t1->id, $t2->id]);
        $this->layanan->aturRuteTiang($this->kabelB, [$t2->id, $t3->id]);

        $this->assertSame(['T3', 'T1', 'T2'], $this->kabelA->tiang()->pluck('kode')->all());
        $this->assertSame([1, 2, 3], $this->kabelA->tiang()->get()->map(fn (Tiang $t) => $t->pivot->urutan)->all());
        $this->assertEqualsCanonicalizing(['KBL-A', 'KBL-B'], $t2->segmen()->pluck('kode')->all());

        // Urutan dapat diubah, termasuk menukar posisi.
        $this->layanan->aturRuteTiang($this->kabelA, [$t1->id, $t2->id, $t3->id]);
        $this->assertSame(['T1', 'T2', 'T3'], $this->kabelA->tiang()->pluck('kode')->all());

        $riwayat = RiwayatHubungan::query()->where('segmen_kabel_id', $this->kabelA->id)->orderBy('id')->get();
        $this->assertSame([AksiHubungan::Dibuat, AksiHubungan::Diubah], $riwayat->pluck('aksi')->all());
        $this->assertSame(['tiang' => ['T3', 'T1', 'T2']], $riwayat[1]->sebelum);
        $this->assertSame(['tiang' => ['T1', 'T2', 'T3']], $riwayat[1]->sesudah);

        $this->assertStringContainsString('dua kali', $this->pesanGagal(fn () => $this->layanan->aturRuteTiang($this->kabelA, [$t1->id, $t1->id])));

        // Tiang yang masih dilalui kabel tidak dapat dihapus.
        $this->expectException(QueryException::class);

        $t1->delete();
    }

    public function test_panjang_peta_dihitung_dan_terpisah_dari_panjang_ukur(): void
    {
        $segmen = SegmenKabel::factory()->create([
            'panjang_ukur_m' => 1250.5,
            // Sekitar 0,01 derajat bujur di lintang -7,36: kurang lebih 1,1 km.
            'jalur_peta' => [[109.90, -7.36], [109.91, -7.36]],
        ]);

        $this->assertSame('1250.50', $segmen->refresh()->panjang_ukur_m);
        $this->assertEqualsWithDelta(1102.8, (float) $segmen->panjang_peta_m, 2.0);

        // Mengubah panjang ukur tidak menyentuh panjang peta, dan sebaliknya.
        $petaLama = $segmen->panjang_peta_m;
        $segmen->update(['panjang_ukur_m' => 1300]);
        $this->assertSame($petaLama, $segmen->refresh()->panjang_peta_m);

        $segmen->update(['jalur_peta' => [[109.90, -7.36], [109.91, -7.36], [109.91, -7.37]]]);
        $this->assertGreaterThan((float) $petaLama + 1000, (float) $segmen->refresh()->panjang_peta_m);
        $this->assertSame('1300.00', $segmen->panjang_ukur_m);

        $segmen->update(['jalur_peta' => null]);
        $this->assertNull($segmen->refresh()->panjang_peta_m);

        // panjang_peta_m tidak dapat diisi lewat mass assignment.
        $segmen->update(['panjang_peta_m' => 5]);
        $this->assertNull($segmen->refresh()->panjang_peta_m);
    }

    public function test_sambungan_menandai_core_terpakai_dan_tercatat_di_riwayat(): void
    {
        $this->actingAs($operator = User::factory()->operator()->create(['name' => 'Operator Uji']));

        $masuk = $this->core($this->kabelA, 1);
        $keluar = $this->core($this->kabelB, 1);

        $sambungan = $this->layanan->sambung($this->closure, $masuk, $keluar, 'Uji');

        $this->assertSame(StatusPemakaian::Terpakai, $masuk->refresh()->status);
        $this->assertSame(StatusPemakaian::Terpakai, $keluar->refresh()->status);
        $this->assertSame(2, DB::table('pemakaian_ujung_core')->where('sumber', 'sambungan')->count());

        $riwayat = RiwayatHubungan::query()->latest('id')->firstOrFail();
        $this->assertSame(JenisHubungan::Sambungan, $riwayat->jenis);
        $this->assertSame(AksiHubungan::Dibuat, $riwayat->aksi);
        $this->assertSame($operator->id, $riwayat->pelaku_id);
        $this->assertSame($this->closure->id, $riwayat->titik_jaringan_id);
        $this->assertSame('KBL-A core 1 (biru) disambung ke KBL-B core 1 (biru) di CL', $riwayat->ringkasan);
        $this->assertNull($riwayat->sebelum);
        $this->assertSame($masuk->id, $riwayat->sesudah['core_masuk_id'] ?? null);

        $this->layanan->lepasSambungan($sambungan);

        $this->assertSame(StatusPemakaian::Tersedia, $masuk->refresh()->status);
        $this->assertSame(0, DB::table('pemakaian_ujung_core')->count());

        $dihapus = RiwayatHubungan::query()->latest('id')->firstOrFail();
        $this->assertSame(AksiHubungan::Dihapus, $dihapus->aksi);
        $this->assertSame($masuk->id, $dihapus->sebelum['core_masuk_id'] ?? null);
        $this->assertNull($dihapus->sesudah);
    }

    public function test_ujung_core_tidak_dapat_dipakai_dua_kali(): void
    {
        $a1 = $this->core($this->kabelA, 1);
        $b1 = $this->core($this->kabelB, 1);
        $b2 = $this->core($this->kabelB, 2);

        $this->layanan->sambung($this->closure, $a1, $b1);

        // Core masuk yang sama ke core keluar lain.
        $this->assertStringContainsString('sudah dipakai di CL', $this->pesanGagal(fn () => $this->layanan->sambung($this->closure, $a1, $b2)));
        // Core yang sudah menjadi keluar, dipakai sebagai masuk.
        $this->assertStringContainsString('sudah dipakai di CL', $this->pesanGagal(fn () => $this->layanan->sambung($this->closure, $b1, $this->core($this->kabelA, 2))));
        // Core disambung ke dirinya sendiri.
        $this->assertStringContainsString('tidak boleh sama', $this->pesanGagal(fn () => $this->layanan->sambung($this->closure, $b2, $b2)));
        // Kabel yang tidak berujung di titik tersebut.
        $this->assertStringContainsString('tidak berujung di OTB-A', $this->pesanGagal(fn () => $this->layanan->sambung($this->otbA, $a1, $b2)));

        $this->assertSame(1, Sambungan::query()->count());

        // Ujung lain dari core yang sama (di OTB-A) masih bebas.
        $this->layanan->terminasikan($this->port($this->otbA, 1), $a1);

        $this->assertSame(2, DB::table('pemakaian_ujung_core')->where('core_id', $a1->id)->count());
    }

    public function test_core_tidak_dapat_disambung_sekaligus_diterminasi_di_titik_yang_sama(): void
    {
        $segmenPendek = SegmenKabel::factory()->antara($this->otbA, $this->otbB)->create(['kode' => 'KBL-C']);
        $c1 = $this->core($segmenPendek, 1);
        $a1 = $this->core($this->kabelA, 1);

        $this->layanan->terminasikan($this->port($this->otbA, 1), $c1);

        $this->assertStringContainsString('sudah dipakai di OTB-A', $this->pesanGagal(fn () => $this->layanan->sambung($this->otbA, $c1, $a1)));
        $this->assertStringContainsString('sudah dipakai di OTB-A', $this->pesanGagal(fn () => $this->layanan->terminasikan($this->port($this->otbA, 2), $c1)));
        $this->assertStringContainsString('sudah berisi core lain', $this->pesanGagal(fn () => $this->layanan->terminasikan($this->port($this->otbA, 1), $a1)));
        $this->assertStringContainsString('sudah dipakai di OTB-A', $this->pesanGagal(
            fn () => $this->layanan->hubungkanEndpoint($this->portPerangkat(), core: $c1, titik: $this->otbA),
        ));
    }

    public function test_database_menolak_pemakaian_ganda_tanpa_melalui_layanan(): void
    {
        $a1 = $this->core($this->kabelA, 1);
        $b1 = $this->core($this->kabelB, 1);
        $b2 = $this->core($this->kabelB, 2);

        Sambungan::create(['titik_jaringan_id' => $this->closure->id, 'core_masuk_id' => $a1->id, 'core_keluar_id' => $b1->id]);

        foreach ([
            fn () => Sambungan::create(['titik_jaringan_id' => $this->closure->id, 'core_masuk_id' => $a1->id, 'core_keluar_id' => $b2->id]),
            fn () => Sambungan::create(['titik_jaringan_id' => $this->closure->id, 'core_masuk_id' => $b2->id, 'core_keluar_id' => $a1->id]),
            fn () => DB::table('koneksi_endpoint')->insert([
                'port_perangkat_id' => $this->portPerangkat()->id, 'core_id' => $b1->id, 'titik_jaringan_id' => $this->closure->id,
            ]),
        ] as $aksi) {
            try {
                // Savepoint, supaya transaksi uji tetap dapat dipakai setelah ditolak.
                DB::transaction($aksi);
                $this->fail('Database seharusnya menolak pemakaian ganda.');
            } catch (UniqueConstraintViolationException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, Sambungan::query()->count());
        $this->assertSame(2, DB::table('pemakaian_ujung_core')->count());

        // Buku besar ikut bersih saat sambungan dihapus langsung.
        Sambungan::query()->firstOrFail()->delete();
        $this->assertSame(0, DB::table('pemakaian_ujung_core')->count());
    }

    public function test_terminasi_dan_koneksi_endpoint_lewat_port(): void
    {
        $a1 = $this->core($this->kabelA, 1);
        $port = $this->port($this->otbA, 3);
        $pp = $this->portPerangkat('Gi0/24');

        $this->layanan->terminasikan($port, $a1);
        $koneksi = $this->layanan->hubungkanEndpoint($pp, port: $port, catatan: 'Uplink');

        $this->assertSame(StatusPemakaian::Terpakai, $port->refresh()->status);
        $this->assertSame($a1->id, $port->core_id);
        $this->assertSame($pp->id, $port->koneksiEndpoint?->port_perangkat_id);
        $this->assertSame([JenisHubungan::Terminasi, JenisHubungan::KoneksiEndpoint], RiwayatHubungan::query()->orderBy('id')->pluck('jenis')->all());

        // Port perangkat dan port terminasi masing-masing hanya satu koneksi.
        $this->assertStringContainsString('sudah terhubung ke tempat lain', $this->pesanGagal(fn () => $this->layanan->hubungkanEndpoint($pp, port: $this->port($this->otbA, 4))));
        $this->assertStringContainsString('sudah terhubung ke port perangkat lain', $this->pesanGagal(fn () => $this->layanan->hubungkanEndpoint($this->portPerangkat('Gi0/2'), port: $port)));
        $this->assertStringContainsString('tepat satu sasaran', $this->pesanGagal(fn () => $this->layanan->hubungkanEndpoint($this->portPerangkat('Gi0/3'))));

        $this->layanan->lepasEndpoint($koneksi);
        $this->assertSame(StatusPemakaian::Terpakai, $port->refresh()->status, 'Port masih berisi core.');

        $this->layanan->lepasTerminasi($port);
        $this->assertSame(StatusPemakaian::Tersedia, $port->refresh()->status);
        $this->assertSame(StatusPemakaian::Tersedia, $a1->refresh()->status);
        $this->assertSame(0, KoneksiEndpoint::query()->count());
        $this->assertSame(4, RiwayatHubungan::query()->count());
    }

    public function test_koneksi_endpoint_langsung_ke_core(): void
    {
        $b5 = $this->core($this->kabelB, 5);

        $koneksi = $this->layanan->hubungkanEndpoint($this->portPerangkat(), core: $b5, titik: $this->otbB);

        $this->assertSame($this->otbB->id, $koneksi->titik_jaringan_id);
        $this->assertSame(StatusPemakaian::Terpakai, $b5->refresh()->status);
        $this->assertStringContainsString('sudah dipakai di OTB-B', $this->pesanGagal(fn () => $this->layanan->terminasikan($this->port($this->otbB, 1), $b5)));

        // Tepat satu sasaran dijaga database juga.
        $this->expectException(QueryException::class);

        DB::table('koneksi_endpoint')->insert(['port_perangkat_id' => $this->portPerangkat('x')->id]);
    }

    public function test_core_dan_port_rusak_tidak_dapat_dihubungkan(): void
    {
        $a1 = $this->core($this->kabelA, 1);
        $port = $this->port($this->otbA, 1);

        $this->layanan->tandaiCore($a1, true);
        $this->assertStringContainsString('ditandai rusak', $this->pesanGagal(fn () => $this->layanan->terminasikan($port, $a1)));

        $this->layanan->tandaiCore($a1, false);
        $this->layanan->tandaiPort($port, true);
        $this->assertStringContainsString('ditandai rusak', $this->pesanGagal(fn () => $this->layanan->terminasikan($port, $a1)));

        // Core terpakai yang ditandai rusak kembali "terpakai" saat dipulihkan.
        $this->layanan->tandaiPort($port, false);
        $this->layanan->terminasikan($port, $a1);
        $this->layanan->tandaiCore($a1, true);
        $this->assertSame(StatusPemakaian::Rusak, $a1->refresh()->status);
        $this->layanan->tandaiCore($a1, false);
        $this->assertSame(StatusPemakaian::Terpakai, $a1->refresh()->status);
    }

    public function test_objek_yang_masih_terhubung_tidak_dapat_dihapus(): void
    {
        $this->layanan->sambung($this->closure, $this->core($this->kabelA, 1), $this->core($this->kabelB, 1));

        foreach ([fn () => $this->kabelA->delete(), fn () => $this->closure->delete()] as $aksi) {
            try {
                DB::transaction($aksi);
                $this->fail('Seharusnya ditolak database.');
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertNotNull($this->kabelA->fresh());
    }

    public function test_status_data_mengikuti_kelengkapan(): void
    {
        $tiang = Tiang::create(['kode' => 'TG-BARU']);

        $this->assertSame(StatusData::PerluDilengkapi, $tiang->status_data);
        $this->assertSame(['koordinat', 'pemilik', 'kondisi'], $tiang->kekurangan());

        $tiang->update(['lintang' => -7.36, 'bujur' => 109.9, 'pemilik' => 'pln', 'kondisi' => 'baik']);
        $this->assertSame(StatusData::Lengkap, $tiang->refresh()->status_data);

        $segmen = SegmenKabel::create(['kode' => 'SK-BARU']);
        $this->assertSame(StatusData::PerluDilengkapi, $segmen->status_data);
        $this->assertCount(6, $segmen->kekurangan());
        $this->assertSame(0, $segmen->core()->count());
        $this->assertSame(StatusData::Lengkap, $this->kabelA->status_data);

        $otb = TitikJaringan::create(['kode' => 'OTB-BARU', 'jenis' => 'otb']);
        $this->assertSame(['lokasi (koordinat, tiang, atau OPD)', 'kapasitas port'], $otb->kekurangan());

        $closure = TitikJaringan::create(['kode' => 'CL-BARU', 'jenis' => 'closure', 'tiang_id' => $tiang->id]);
        $this->assertSame(StatusData::Lengkap, $closure->status_data);

        $this->assertSame(1, SegmenKabel::query()->perluDilengkapi()->count());
    }

    public function test_riwayat_tidak_dapat_diubah_atau_dihapus(): void
    {
        $this->layanan->sambung($this->closure, $this->core($this->kabelA, 1), $this->core($this->kabelB, 1));
        $riwayat = RiwayatHubungan::query()->firstOrFail();

        try {
            $riwayat->update(['ringkasan' => 'Dipalsukan']);
            $this->fail('Riwayat seharusnya tidak dapat diubah.');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(LogicException::class);

        $riwayat->delete();
    }
}
