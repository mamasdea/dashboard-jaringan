<?php

namespace App\Enums;

enum Peran: string
{
    case Administrator = 'administrator';
    case Operator = 'operator';
    case Teknisi = 'teknisi';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Administrator => 'Administrator',
            self::Operator => 'Operator Diskominfo',
            self::Teknisi => 'Teknisi',
            self::Viewer => 'Viewer',
        };
    }

    public function keterangan(): string
    {
        return match ($this) {
            self::Administrator => 'Mengelola pengguna dan seluruh konfigurasi.',
            self::Operator => 'Mengelola aset, endpoint, dan tiket.',
            self::Teknisi => 'Melihat aset dan menangani tiket yang ditugaskan.',
            self::Viewer => 'Hanya melihat dashboard dan laporan.',
        };
    }

    /**
     * Satu-satunya sumber matriks hak akses.
     *
     * @return list<Izin>
     */
    public function izin(): array
    {
        return match ($this) {
            self::Administrator => Izin::cases(),
            self::Operator => [
                Izin::KelolaAset,
                Izin::KelolaEndpoint,
                Izin::KelolaTiket,
                Izin::LihatAset,
                Izin::LihatDashboard,
                Izin::LihatLaporan,
            ],
            self::Teknisi => [
                Izin::LihatAset,
                Izin::TanganiTiket,
                Izin::LihatDashboard,
            ],
            self::Viewer => [
                Izin::LihatDashboard,
                Izin::LihatLaporan,
            ],
        };
    }

    public function boleh(Izin $izin): bool
    {
        return in_array($izin, $this->izin(), true);
    }
}
