<?php

namespace App\Enums;

enum AksiAktivitas: string
{
    case Masuk = 'auth.masuk';
    case GagalMasuk = 'auth.gagal-masuk';
    case TerkunciSementara = 'auth.terkunci-sementara';
    case Keluar = 'auth.keluar';
    case DuaFaktorDiaktifkan = 'auth.dua-faktor-diaktifkan';
    case DuaFaktorDinonaktifkan = 'auth.dua-faktor-dinonaktifkan';
    case AksesDitolak = 'akses.ditolak';
    case PenggunaDibuat = 'pengguna.dibuat';
    case PenggunaDiubah = 'pengguna.diubah';
    case PeranDiubah = 'pengguna.peran-diubah';
    case KataSandiDiubah = 'pengguna.kata-sandi-diubah';
    case PenggunaDihapus = 'pengguna.dihapus';
    case OpdDibuat = 'opd.dibuat';
    case OpdDiubah = 'opd.diubah';
    case OpdDihapus = 'opd.dihapus';
    case PerangkatDibuat = 'perangkat.dibuat';
    case PerangkatDiubah = 'perangkat.diubah';
    case PerangkatDihapus = 'perangkat.dihapus';
    case TiangDibuat = 'tiang.dibuat';
    case TiangDiubah = 'tiang.diubah';
    case TiangDihapus = 'tiang.dihapus';
    case TitikJaringanDibuat = 'titik-jaringan.dibuat';
    case TitikJaringanDiubah = 'titik-jaringan.diubah';
    case TitikJaringanDihapus = 'titik-jaringan.dihapus';
    case SegmenKabelDibuat = 'segmen-kabel.dibuat';
    case SegmenKabelDiubah = 'segmen-kabel.diubah';
    case SegmenKabelDihapus = 'segmen-kabel.dihapus';
    case TiketDibuat = 'tiket.dibuat';
    case TiketDiubah = 'tiket.diubah';
    case TiketDihapus = 'tiket.dihapus';
    case ImporCsv = 'impor.csv';
    case LaporanDiekspor = 'laporan.diekspor';

    public function label(): string
    {
        return match ($this) {
            self::Masuk => 'Masuk',
            self::GagalMasuk => 'Gagal masuk',
            self::TerkunciSementara => 'Terkunci sementara',
            self::Keluar => 'Keluar',
            self::DuaFaktorDiaktifkan => '2FA diaktifkan',
            self::DuaFaktorDinonaktifkan => '2FA dinonaktifkan',
            self::AksesDitolak => 'Akses ditolak',
            self::PenggunaDibuat => 'Pengguna dibuat',
            self::PenggunaDiubah => 'Pengguna diubah',
            self::PeranDiubah => 'Peran diubah',
            self::KataSandiDiubah => 'Kata sandi diubah',
            self::PenggunaDihapus => 'Pengguna dihapus',
            self::OpdDibuat => 'OPD dibuat',
            self::OpdDiubah => 'OPD diubah',
            self::OpdDihapus => 'OPD dihapus',
            self::PerangkatDibuat => 'Perangkat dibuat',
            self::PerangkatDiubah => 'Perangkat diubah',
            self::PerangkatDihapus => 'Perangkat dihapus',
            self::TiangDibuat => 'Tiang dibuat',
            self::TiangDiubah => 'Tiang diubah',
            self::TiangDihapus => 'Tiang dihapus',
            self::TitikJaringanDibuat => 'Titik jaringan dibuat',
            self::TitikJaringanDiubah => 'Titik jaringan diubah',
            self::TitikJaringanDihapus => 'Titik jaringan dihapus',
            self::SegmenKabelDibuat => 'Segmen kabel dibuat',
            self::SegmenKabelDiubah => 'Segmen kabel diubah',
            self::SegmenKabelDihapus => 'Segmen kabel dihapus',
            self::TiketDibuat => 'Tiket dibuat',
            self::TiketDiubah => 'Tiket diubah',
            self::TiketDihapus => 'Tiket dihapus',
            self::ImporCsv => 'Impor CSV',
            self::LaporanDiekspor => 'Laporan diekspor',
        };
    }

    /** Warna lencana Flux. */
    public function warna(): string
    {
        return match (true) {
            in_array($this, [self::GagalMasuk, self::TerkunciSementara, self::AksesDitolak], true) => 'red',
            in_array($this, [self::Masuk, self::Keluar], true) => 'zinc',
            in_array($this, [self::PeranDiubah, self::KataSandiDiubah, self::DuaFaktorDinonaktifkan], true),
            str_ends_with($this->value, '.dihapus') => 'amber',
            default => 'sky',
        };
    }
}
