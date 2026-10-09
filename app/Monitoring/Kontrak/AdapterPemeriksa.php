<?php

namespace App\Monitoring\Kontrak;

use App\Monitoring\HasilAdapter;
use App\Monitoring\SasaranPemeriksaan;

/**
 * Kontrak pemeriksa endpoint. Adapter simulasi dan kolektor nyata (ICMP,
 * SNMP) sama-sama mengimplementasikannya, sehingga dapat ditukar lewat
 * `config('monitoring.adapter')` tanpa mengubah kolektor maupun aturan status.
 *
 * Kewajiban implementasi:
 * - Mengembalikan HasilAdapter::gagalPerangkat() bila pemeriksaan selesai dan
 *   endpoint tidak menjawab (termasuk tidak ada balasan sampai batas waktu
 *   pemeriksaan).
 * - Mengembalikan HasilAdapter::galatKolektor(), atau melempar exception, bila
 *   pemeriksaan itu sendiri tidak dapat dijalankan (utilitas tidak ada, soket
 *   gagal dibuka, kredensial tidak terbaca). Jangan melaporkannya sebagai
 *   kegagalan perangkat.
 * - Menghormati $sasaran->batasWaktuDetik; kolektor tidak dapat menghentikan
 *   adapter yang macet, hanya menandai hasilnya sebagai galat kolektor.
 * - Tidak menulis ke database; penyimpanan dan penilaian status dilakukan
 *   App\Monitoring\LayananStatus.
 */
interface AdapterPemeriksa
{
    /**
     * Nama singkat untuk kolom `hasil_pemeriksaan.sumber`, mis. "simulasi" atau "icmp".
     */
    public function nama(): string;

    /**
     * Benar bila hasilnya bukan dari jaringan nyata; hasil dan status akan
     * ditandai SIMULASI sampai ke tampilan.
     */
    public function simulasi(): bool;

    public function periksa(SasaranPemeriksaan $sasaran): HasilAdapter;
}
