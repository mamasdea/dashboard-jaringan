<?php

namespace App\Monitoring;

use App\Models\DetakKolektor;
use App\Models\EndpointMonitoring;
use App\Monitoring\Kontrak\AdapterPemeriksa;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Menjalankan pemeriksaan yang sudah jatuh tempo. Dipanggil oleh perintah
 * `monitoring:kolektor`, tidak pernah oleh request web.
 *
 * Pemeriksaan bertumpuk dicegah dengan klaim: sebelum memeriksa, kolektor
 * menandai endpoint dalam satu pernyataan UPDATE yang atomik. Kolektor lain
 * (atau putaran berikutnya dari kolektor yang sama) melewati endpoint yang
 * masih diklaim. Klaim yang terlalu tua dianggap milik kolektor yang mati dan
 * boleh diambil alih.
 */
class Kolektor
{
    public function __construct(
        private readonly AdapterPemeriksa $adapter,
        private readonly LayananStatus $layanan,
    ) {}

    /**
     * Satu putaran: menyapu status berbasis waktu, lalu memeriksa endpoint
     * yang jatuh tempo.
     *
     * @return array{disapu: int, diperiksa: int}
     */
    public function jalankanSekali(): array
    {
        $disapu = $this->layanan->sapu();
        $klaim = (string) Str::uuid();
        $diperiksa = 0;

        foreach ($this->klaim($klaim) as $endpoint) {
            $this->periksa($endpoint, $klaim);
            $diperiksa++;
        }

        $this->catatDetak($diperiksa);

        return ['disapu' => $disapu, 'diperiksa' => $diperiksa];
    }

    /**
     * Tanda hidup proses ini, dibaca dashboard untuk menilai kesehatan kolektor.
     */
    private function catatDetak(int $diperiksa): void
    {
        $sekarang = CarbonImmutable::now()->utc();
        $detak = DetakKolektor::query()->firstOrNew(['nama' => gethostname().':'.getmypid()]);

        $detak->fill([
            'adapter' => $this->adapter->nama(),
            'simulasi' => $this->adapter->simulasi(),
            'mulai_pada' => $detak->exists ? $detak->mulai_pada : $sekarang,
            'detak_pada' => $sekarang,
            'putaran' => $detak->putaran + 1,
            'diperiksa_putaran_terakhir' => $diperiksa,
        ])->save();

        // Proses yang sudah lama mati tidak perlu disimpan selamanya.
        DetakKolektor::query()->where('detak_pada', '<', $sekarang->subDay())->delete();
    }

    /**
     * Mengklaim endpoint yang jatuh tempo dan belum diklaim.
     *
     * @return list<EndpointMonitoring>
     */
    public function klaim(string $klaim, ?int $batas = null): array
    {
        $sekarang = CarbonImmutable::now()->utc();

        $baris = DB::select(
            'UPDATE endpoint_monitoring
                SET sedang_diperiksa_sejak = ?, klaim = ?
              WHERE id IN (
                    SELECT id FROM endpoint_monitoring
                     WHERE aktif
                       AND NOT pemeliharaan
                       AND berikutnya_pada <= ?
                       AND (sedang_diperiksa_sejak IS NULL OR sedang_diperiksa_sejak < ?)
                     ORDER BY berikutnya_pada, id
                     LIMIT ?
                       FOR UPDATE SKIP LOCKED
                    )
            RETURNING id',
            [
                $sekarang->toDateTimeString(),
                $klaim,
                $sekarang->toDateTimeString(),
                $sekarang->subSeconds((int) config('monitoring.batas_klaim_detik'))->toDateTimeString(),
                $batas ?? (int) config('monitoring.ukuran_tumpak'),
            ],
        );

        $ids = collect($baris)->pluck('id')->map(intval(...))->all();

        return array_values(EndpointMonitoring::query()->with('profilSnmp')->whereKey($ids)->orderBy('id')->get()->all());
    }

    /**
     * Memeriksa satu endpoint yang sudah diklaim, lalu melepas klaimnya.
     */
    public function periksa(EndpointMonitoring $endpoint, string $klaim): void
    {
        $diperiksaPada = CarbonImmutable::now()->utc();

        try {
            $this->layanan->terimaHasil(
                $endpoint,
                $this->jalankanAdapter($endpoint),
                $diperiksaPada,
                (string) Str::uuid(),
                $this->adapter->nama(),
                $this->adapter->simulasi(),
            );
        } finally {
            // Hanya pemilik klaim yang melepasnya; klaim yang sudah diambil alih dibiarkan.
            EndpointMonitoring::query()
                ->whereKey($endpoint->id)
                ->where('klaim', $klaim)
                ->update([
                    'sedang_diperiksa_sejak' => null,
                    'klaim' => null,
                    'berikutnya_pada' => $diperiksaPada->addSeconds($endpoint->interval_detik),
                ]);
        }
    }

    /**
     * Galat dan keterlambatan adapter adalah kegagalan kolektor, bukan bukti
     * bahwa endpoint tidak terjangkau.
     */
    private function jalankanAdapter(EndpointMonitoring $endpoint): HasilAdapter
    {
        $sasaran = SasaranPemeriksaan::dari($endpoint);
        $mulai = hrtime(true);

        try {
            $hasil = $this->adapter->periksa($sasaran);
        } catch (Throwable $e) {
            return HasilAdapter::galatKolektor('galat_adapter', $e::class.': '.$e->getMessage());
        }

        $lama = (hrtime(true) - $mulai) / 1e9;

        if ($lama > $sasaran->batasWaktuDetik) {
            return HasilAdapter::galatKolektor('batas_waktu_kolektor', sprintf('Pemeriksaan berjalan %.1f detik, melewati batas %.1f detik.', $lama, $sasaran->batasWaktuDetik));
        }

        return $hasil;
    }
}
