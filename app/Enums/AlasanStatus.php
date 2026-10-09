<?php

namespace App\Enums;

/**
 * Mengapa status sebuah endpoint berubah (kolom riwayat_status.alasan).
 */
enum AlasanStatus: string
{
    case HasilBaru = 'hasil_baru';
    case PemeriksaanGagal = 'pemeriksaan_gagal';
    case GagalBerturut = 'gagal_berturut';
    case Pulih = 'pulih';
    case Kualitas = 'kualitas';
    case Kedaluwarsa = 'kedaluwarsa';
    case PemeliharaanMulai = 'pemeliharaan_mulai';
    case PemeliharaanSelesai = 'pemeliharaan_selesai';

    public function label(): string
    {
        return match ($this) {
            self::HasilBaru => 'Hasil pemeriksaan pertama setelah status tidak diketahui',
            self::PemeriksaanGagal => 'Pemeriksaan gagal, belum mencapai batas berturut-turut',
            self::GagalBerturut => 'Pemeriksaan gagal berturut-turut mencapai batas',
            self::Pulih => 'Pemeriksaan berhasil berturut-turut mencapai batas',
            self::Kualitas => 'Latency atau packet loss melewati atau kembali di bawah ambang',
            self::Kedaluwarsa => 'Tidak ada hasil pemeriksaan baru',
            self::PemeliharaanMulai => 'Pemeliharaan dimulai',
            self::PemeliharaanSelesai => 'Pemeliharaan selesai',
        };
    }
}
