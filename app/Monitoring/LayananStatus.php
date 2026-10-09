<?php

namespace App\Monitoring;

use App\Enums\AlasanStatus;
use App\Enums\KeluaranPemeriksaan;
use App\Enums\StatusMonitoring;
use App\Events\InsidenDibuka;
use App\Events\InsidenDitutup;
use App\Models\EndpointMonitoring;
use App\Models\HasilPemeriksaan;
use App\Models\Insiden;
use App\Models\RiwayatStatus;
use App\Models\StatusTerkini;
use App\Monitoring\Snmp\PenyimpanSnmp;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menyimpan hasil pemeriksaan dan memelihara status terkini, insiden, serta
 * riwayat status. Hanya menyentuh database: tidak pernah menghubungi
 * perangkat, sehingga aman dipanggil dari mana pun (kolektor lokal sekarang,
 * penerima hasil dari kolektor jarak jauh nanti).
 */
class LayananStatus
{
    public function __construct(private readonly PenyimpanSnmp $penyimpanSnmp) {}

    /**
     * Menerima satu hasil pemeriksaan.
     *
     * Hasil selalu disimpan, tetapi hanya mengubah status bila lebih baru
     * dari hasil terakhir yang diterapkan, endpoint tidak sedang dalam
     * pemeliharaan, dan cap waktunya masuk akal. Hasil dengan id_pemeriksaan
     * yang sudah ada diabaikan (pengiriman ulang).
     */
    public function terimaHasil(
        EndpointMonitoring $endpoint,
        HasilAdapter $hasil,
        CarbonInterface $diperiksaPada,
        string $idPemeriksaan,
        string $sumber,
        bool $simulasi,
        ?CarbonInterface $diterimaPada = null,
    ): HasilPemeriksaan {
        $diperiksaPada = CarbonImmutable::instance($diperiksaPada)->utc();
        $diterimaPada = CarbonImmutable::instance($diterimaPada ?? now())->utc();

        return DB::transaction(function () use ($endpoint, $hasil, $diperiksaPada, $idPemeriksaan, $sumber, $simulasi, $diterimaPada): HasilPemeriksaan {
            // Pemeliharaan berjangka yang masanya sudah habis diakhiri dahulu,
            // tanpa menunggu penyapu, supaya hasil ini dinilai seperti biasa.
            if ($endpoint->pemeliharaan && ! $endpoint->dalamPemeliharaan($diterimaPada)) {
                $this->selesaiPemeliharaan($endpoint, $endpoint->pemeliharaan_sampai ?? $diterimaPada);
            }

            // Mengunci baris status: hasil untuk endpoint yang sama diproses satu per satu.
            $status = $this->statusTerkunci($endpoint);

            $sudahAda = HasilPemeriksaan::query()->where('id_pemeriksaan', $idPemeriksaan)->first();

            if ($sudahAda !== null) {
                return $sudahAda;
            }

            $alasanDitolak = match (true) {
                $diperiksaPada->diffInSeconds($diterimaPada, absolute: false) < -1 * (int) config('monitoring.toleransi_waktu_depan_detik') => HasilPemeriksaan::WAKTU_DI_DEPAN,
                $endpoint->dalamPemeliharaan($diterimaPada) => HasilPemeriksaan::PEMELIHARAAN,
                $status->diperiksa_terakhir_pada !== null && $diperiksaPada->lessThanOrEqualTo($status->diperiksa_terakhir_pada) => HasilPemeriksaan::TERLAMBAT,
                default => null,
            };

            $baris = HasilPemeriksaan::create([
                'endpoint_id' => $endpoint->id,
                'id_pemeriksaan' => $idPemeriksaan,
                'diperiksa_pada' => $diperiksaPada,
                'diterima_pada' => $diterimaPada,
                'keluaran' => $hasil->keluaran,
                'latency_ms' => $hasil->latencyMs,
                'packet_loss_persen' => $hasil->packetLossPersen,
                'kode_galat' => $hasil->kodeGalat,
                'pesan' => $hasil->pesan,
                'keluaran_snmp' => $hasil->snmp?->keluaran,
                'kode_galat_snmp' => $hasil->snmp?->kodeGalat,
                'pesan_snmp' => $hasil->snmp?->pesan === null ? null : Str::limit($hasil->snmp->pesan, 480, '…'),
                'sumber' => $sumber,
                'diterapkan' => $alasanDitolak === null,
                'alasan_tidak_diterapkan' => $alasanDitolak,
                'simulasi' => $simulasi,
            ]);

            if ($alasanDitolak === null) {
                $this->nilai($endpoint, $status, $hasil, $baris);
            }

            return $baris;
        });
    }

    /**
     * Pekerjaan berkala yang bergantung pada waktu, bukan pada hasil baru:
     * mengakhiri pemeliharaan yang masanya habis dan menandai status yang
     * hasilnya sudah kedaluwarsa. Mengembalikan jumlah status yang berubah.
     */
    public function sapu(?CarbonInterface $pada = null): int
    {
        $pada = CarbonImmutable::instance($pada ?? now())->utc();
        $berubah = 0;

        EndpointMonitoring::query()
            ->where('pemeliharaan', true)
            ->whereNotNull('pemeliharaan_sampai')
            ->where('pemeliharaan_sampai', '<=', $pada)
            ->get()
            ->each(function (EndpointMonitoring $endpoint) use ($pada, &$berubah): void {
                $this->selesaiPemeliharaan($endpoint, $pada);
                $berubah++;
            });

        StatusTerkini::query()
            ->with('endpoint')
            ->whereIn('status', [StatusMonitoring::Normal, StatusMonitoring::KoneksiMenurun, StatusMonitoring::TidakTerjangkau])
            ->get()
            ->filter(fn (StatusTerkini $status) => $status->kedaluwarsa($pada))
            ->each(function (StatusTerkini $status) use ($pada, &$berubah): void {
                DB::transaction(function () use ($status, $pada, &$berubah): void {
                    $terkunci = $this->statusTerkunci($status->endpoint);

                    // Hasil baru mungkin masuk di antara pembacaan dan penguncian.
                    if (! in_array($terkunci->status, [StatusMonitoring::BelumDiketahui, StatusMonitoring::Pemeliharaan], true) && $terkunci->kedaluwarsa($pada)) {
                        // Dicatat pada saat hasil terakhir berhenti berlaku, bukan saat
                        // penyapu kebetulan berjalan: bila kolektor lama mati, selang
                        // itu tidak boleh tercatat sebagai status sebelumnya.
                        $habis = $terkunci->hasil_valid_terakhir_pada?->addSeconds($status->endpoint->kedaluwarsaDetik());

                        $this->jadikanKedaluwarsa($status->endpoint, $terkunci, $habis === null ? $pada : $habis->min($pada));
                        $berubah++;
                    }
                });
            });

        return $berubah;
    }

    /**
     * Selama pemeliharaan, hasil pemeriksaan tetap disimpan tetapi tidak
     * mengubah status dan tidak membuka insiden. Insiden yang sedang terbuka
     * ditutup dengan keterangan pemeliharaan.
     */
    public function mulaiPemeliharaan(EndpointMonitoring $endpoint, ?CarbonInterface $sampai = null, ?string $catatan = null, ?CarbonInterface $pada = null): void
    {
        $pada = CarbonImmutable::instance($pada ?? now())->utc();

        DB::transaction(function () use ($endpoint, $sampai, $catatan, $pada): void {
            $status = $this->statusTerkunci($endpoint);

            $endpoint->pemeliharaan = true;
            $endpoint->pemeliharaan_sampai = $sampai === null ? null : CarbonImmutable::instance($sampai)->utc();
            $endpoint->pemeliharaan_catatan = $catatan;
            $endpoint->save();

            $this->pindah($endpoint, $status, StatusMonitoring::Pemeliharaan, AlasanStatus::PemeliharaanMulai, $pada);
            $this->nolkanPenghitung($status);
            $status->save();
        });
    }

    /**
     * Setelah pemeliharaan, status "Belum diketahui" sampai ada hasil baru.
     */
    public function selesaiPemeliharaan(EndpointMonitoring $endpoint, ?CarbonInterface $pada = null): void
    {
        $pada = CarbonImmutable::instance($pada ?? now())->utc();

        DB::transaction(function () use ($endpoint, $pada): void {
            $status = $this->statusTerkunci($endpoint);

            $endpoint->pemeliharaan = false;
            $endpoint->pemeliharaan_sampai = null;
            $endpoint->pemeliharaan_catatan = null;
            // Diperiksa segera, tanpa menunggu jadwal berikutnya.
            $endpoint->berikutnya_pada = $pada;
            $endpoint->save();

            $this->pindah($endpoint, $status, StatusMonitoring::BelumDiketahui, AlasanStatus::PemeliharaanSelesai, $pada);
            $this->nolkanPenghitung($status);
            // Hasil dari sebelum pemeliharaan tidak boleh menjadi dasar status sesudahnya.
            $status->hasil_valid_terakhir_pada = null;
            $status->save();
        });
    }

    private function nilai(EndpointMonitoring $endpoint, StatusTerkini $status, HasilAdapter $hasil, HasilPemeriksaan $baris): void
    {
        $pada = $baris->diperiksa_pada;

        // Jeda terlalu panjang sejak hasil valid terakhir: keadaan lama tidak
        // lagi berlaku, jadi penilaian dimulai dari "Belum diketahui".
        if ($status->hasil_valid_terakhir_pada !== null
            && ! in_array($status->status, [StatusMonitoring::BelumDiketahui, StatusMonitoring::Pemeliharaan], true)
            && $status->kedaluwarsa($pada)) {
            $this->jadikanKedaluwarsa($endpoint, $status, $status->hasil_valid_terakhir_pada->addSeconds($endpoint->kedaluwarsaDetik()));
        }

        $keadaan = MesinStatus::terapkan(
            $status->status,
            $status->gagal_berturut,
            $status->berhasil_berturut,
            $status->galat_kolektor_berturut,
            $hasil,
            $endpoint->ambangLatencyMs(),
            $endpoint->ambangPacketLossPersen(),
            (int) config('monitoring.gagal_berturut'),
            (int) config('monitoring.pulih_berturut'),
        );

        $status->gagal_berturut = $keadaan['gagal'];
        $status->berhasil_berturut = $keadaan['berhasil'];
        $status->galat_kolektor_berturut = $keadaan['galatKolektor'];
        $status->diperiksa_terakhir_pada = $pada;
        $status->diterima_terakhir_pada = $baris->diterima_pada;
        $status->simulasi = $baris->simulasi;

        if ($hasil->keluaran !== KeluaranPemeriksaan::GalatKolektor) {
            $status->hasil_valid_terakhir_pada = $pada;
            $status->latency_ms = $hasil->latencyMs === null ? null : number_format($hasil->latencyMs, 2, '.', '');
            $status->packet_loss_persen = $hasil->packetLossPersen === null ? null : number_format($hasil->packetLossPersen, 2, '.', '');
        }

        if ($keadaan['alasan'] !== null) {
            $this->pindah($endpoint, $status, $keadaan['status'], $keadaan['alasan'], $pada, $baris);
        }

        // SNMP dicatat sendiri: ping yang berhasil dengan SNMP yang gagal tetap
        // "terjangkau", tetapi kegagalan SNMP-nya terlihat.
        if ($hasil->snmp !== null) {
            $status->snmp_keluaran = $hasil->snmp->keluaran;
            $status->snmp_kode_galat = $hasil->snmp->kodeGalat;

            if ($hasil->snmp->keluaran === KeluaranPemeriksaan::Berhasil) {
                $status->snmp_gagal_berturut = 0;
                $status->snmp_berhasil_terakhir_pada = $pada;
                $this->penyimpanSnmp->simpan($endpoint, $hasil->snmp, $pada, $baris->simulasi);
            } else {
                $status->snmp_gagal_berturut++;
            }
        }

        $status->save();
    }

    private function jadikanKedaluwarsa(EndpointMonitoring $endpoint, StatusTerkini $status, CarbonImmutable $pada): void
    {
        $this->pindah($endpoint, $status, StatusMonitoring::BelumDiketahui, AlasanStatus::Kedaluwarsa, $pada);
        $this->nolkanPenghitung($status);
        $status->save();
    }

    /**
     * Mengubah status, menambah riwayat, dan membuka atau menutup insiden.
     * Insiden hanya untuk "tidak terjangkau"; ia tetap terbuka selama status
     * belum diketahui, karena belum ada bukti bahwa endpoint sudah pulih.
     */
    private function pindah(
        EndpointMonitoring $endpoint,
        StatusTerkini $status,
        StatusMonitoring $menjadi,
        AlasanStatus $alasan,
        CarbonImmutable $pada,
        ?HasilPemeriksaan $baris = null,
    ): void {
        $dari = $status->status;

        if ($dari === $menjadi) {
            return;
        }

        $simulasi = $baris !== null ? $baris->simulasi : $status->simulasi;
        $insiden = Insiden::query()->where('endpoint_id', $endpoint->id)->whereNull('selesai_pada')->first();

        if ($menjadi === StatusMonitoring::TidakTerjangkau && $insiden === null) {
            $insiden = Insiden::create(['endpoint_id' => $endpoint->id, 'mulai_pada' => $pada, 'simulasi' => $simulasi]);
            event(new InsidenDibuka($insiden, $endpoint));
        } elseif ($insiden !== null
            && in_array($menjadi, [StatusMonitoring::Normal, StatusMonitoring::KoneksiMenurun, StatusMonitoring::Pemeliharaan], true)
            // "Menurun" karena pemeriksaan gagal bukan tanda pulih.
            && $alasan !== AlasanStatus::PemeriksaanGagal) {
            $insiden->selesai_pada = $pada->max($insiden->mulai_pada);
            $insiden->ditutup_karena = $menjadi === StatusMonitoring::Pemeliharaan ? Insiden::PEMELIHARAAN : Insiden::PULIH;
            $insiden->save();
            event(new InsidenDitutup($insiden, $endpoint));
        }

        RiwayatStatus::create([
            'endpoint_id' => $endpoint->id,
            'dari' => $dari,
            'menjadi' => $menjadi,
            'alasan' => $alasan,
            'pada' => $pada,
            'hasil_pemeriksaan_id' => $baris?->id,
            'insiden_id' => $insiden?->id,
            'simulasi' => $simulasi,
        ]);

        $status->status = $menjadi;
        $status->sejak = $pada;
    }

    private function nolkanPenghitung(StatusTerkini $status): void
    {
        $status->gagal_berturut = 0;
        $status->berhasil_berturut = 0;
        $status->galat_kolektor_berturut = 0;
    }

    private function statusTerkunci(EndpointMonitoring $endpoint): StatusTerkini
    {
        $status = StatusTerkini::query()->where('endpoint_id', $endpoint->id)->lockForUpdate()->first()
            ?? StatusTerkini::create(['endpoint_id' => $endpoint->id, 'sejak' => now(), 'simulasi' => $endpoint->simulasi]);

        return $status->setRelation('endpoint', $endpoint);
    }
}
