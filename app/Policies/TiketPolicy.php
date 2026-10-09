<?php

namespace App\Policies;

use App\Enums\Izin;
use App\Models\Tiket;
use App\Models\User;

/**
 * `kelola-tiket` berlaku untuk semua tiket. `tangani-tiket` hanya berlaku
 * untuk tiket yang ditugaskan kepada pelaku.
 */
class TiketPolicy
{
    public function viewAny(User $pelaku): bool
    {
        return $pelaku->boleh(Izin::KelolaTiket) || $pelaku->boleh(Izin::TanganiTiket);
    }

    public function view(User $pelaku, Tiket $tiket): bool
    {
        return $pelaku->boleh(Izin::KelolaTiket) || $this->ditugaskan($pelaku, $tiket);
    }

    public function create(User $pelaku): bool
    {
        return $pelaku->boleh(Izin::KelolaTiket);
    }

    /**
     * Mengubah data tiket, menugaskan, membatalkan, dan membuka kembali.
     */
    public function update(User $pelaku, Tiket $tiket): bool
    {
        return $pelaku->boleh(Izin::KelolaTiket);
    }

    /**
     * Mengerjakan tiket: status pekerjaan, penyebab, tindakan, catatan, foto.
     * Teknisi hanya pada tiket miliknya yang masih terbuka.
     */
    public function tangani(User $pelaku, Tiket $tiket): bool
    {
        return $pelaku->boleh(Izin::KelolaTiket)
            || ($this->ditugaskan($pelaku, $tiket) && $tiket->status->terbuka());
    }

    private function ditugaskan(User $pelaku, Tiket $tiket): bool
    {
        return $pelaku->boleh(Izin::TanganiTiket) && $tiket->teknisi_id !== null && $tiket->teknisi_id === $pelaku->id;
    }
}
