<?php

namespace App\Monitoring\Icmp;

use App\Monitoring\BatasPemeriksaan;
use App\Monitoring\HasilAdapter;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * Pemeriksaan ICMP lewat utilitas `ping` sistem.
 *
 * Perintah disusun sebagai larik argumen dan dijalankan tanpa shell, dengan
 * batas waktu. Satu-satunya nilai yang berasal dari data adalah alamat sasaran,
 * dan hanya alamat IP harfiah yang diterima.
 */
class PemeriksaIcmp
{
    public function periksa(string $alamat): HasilAdapter
    {
        if (filter_var($alamat, FILTER_VALIDATE_IP) === false) {
            return HasilAdapter::galatKolektor('alamat_tidak_valid', 'Sasaran ICMP harus berupa alamat IP.');
        }

        $jumlah = BatasPemeriksaan::jumlahPaketIcmp();
        $batasMs = BatasPemeriksaan::batasWaktuIcmpMs();

        try {
            $proses = Process::timeout((int) ceil($jumlah * ($batasMs / 1000 + 1)) + 2)
                ->run($this->perintah($alamat, $jumlah, $batasMs));
        } catch (ProcessTimedOutException) {
            return HasilAdapter::galatKolektor('batas_waktu_ping', 'Utilitas ping tidak selesai dalam batas waktu.');
        } catch (Throwable $e) {
            return HasilAdapter::galatKolektor('ping_tidak_dapat_dijalankan', $e->getMessage());
        }

        $keluaran = $proses->output();
        $hasil = self::urai($keluaran, $jumlah);

        if ($hasil !== null) {
            return $hasil;
        }

        // Tidak ada satu balasan pun. Bila ping sendiri berjalan normal, itu
        // kegagalan perangkat; bila tidak menghasilkan apa-apa, itu kegagalan kolektor.
        return trim($keluaran) === ''
            ? HasilAdapter::galatKolektor('ping_tidak_dapat_dijalankan', trim($proses->errorOutput()) ?: 'Utilitas ping tidak menghasilkan keluaran.')
            : HasilAdapter::gagalPerangkat('tanpa_jawaban', "Tidak ada balasan dari {$jumlah} paket ICMP.");
    }

    /**
     * Membaca keluaran ping Windows maupun Linux tanpa bergantung pada bahasa
     * sistem: setiap balasan memuat "TTL=" (atau "ttl=") dan waktu dalam ms.
     * Baris "Destination host unreachable" tidak memuat TTL, jadi tidak
     * terhitung sebagai balasan meskipun ringkasan Windows menghitungnya.
     *
     * @return HasilAdapter|null null bila tidak ada balasan sama sekali.
     */
    public static function urai(string $keluaran, int $jumlahDikirim): ?HasilAdapter
    {
        $waktu = [];
        $diterima = 0;

        foreach (preg_split('/\R/', $keluaran) ?: [] as $baris) {
            if (preg_match('/\bttl[=:]\s*\d+/i', $baris) !== 1) {
                continue;
            }

            $diterima++;

            if (preg_match('/(?:time|waktu|zeit|temps|tiempo)\s*([=<])\s*([\d.,]+)\s*ms/i', $baris, $cocok) === 1) {
                // "time<1ms" berarti di bawah satu milidetik.
                $nilai = (float) str_replace(',', '.', $cocok[2]);
                $waktu[] = $cocok[1] === '<' ? $nilai / 2 : $nilai;
            }
        }

        if ($diterima === 0) {
            return null;
        }

        $diterima = min($diterima, $jumlahDikirim);

        return HasilAdapter::berhasil(
            $waktu === [] ? 0.0 : round(array_sum($waktu) / count($waktu), 2),
            round(($jumlahDikirim - $diterima) / $jumlahDikirim * 100, 2),
        );
    }

    /**
     * @return list<string>
     */
    private function perintah(string $alamat, int $jumlah, int $batasMs): array
    {
        $utilitas = (string) config('monitoring.icmp.utilitas', 'ping');
        $ipv6 = str_contains($alamat, ':');

        if (PHP_OS_FAMILY === 'Windows') {
            return [$utilitas, $ipv6 ? '-6' : '-4', '-n', (string) $jumlah, '-w', (string) $batasMs, $alamat];
        }

        // Tanpa -q: baris tiap balasan dibutuhkan untuk dihitung.
        return [$utilitas, $ipv6 ? '-6' : '-4', '-n', '-c', (string) $jumlah, '-W', (string) max(1, (int) ceil($batasMs / 1000)), $alamat];
    }
}
