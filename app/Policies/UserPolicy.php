<?php

namespace App\Policies;

use App\Enums\Izin;
use App\Models\User;

class UserPolicy
{
    public function viewAny(User $pelaku): bool
    {
        return $pelaku->peran->boleh(Izin::KelolaPengguna);
    }

    public function create(User $pelaku): bool
    {
        return $pelaku->peran->boleh(Izin::KelolaPengguna);
    }

    public function update(User $pelaku, User $target): bool
    {
        return $pelaku->peran->boleh(Izin::KelolaPengguna);
    }

    /**
     * Peran akun sendiri tidak dapat diubah, sehingga selalu tersisa
     * setidaknya satu Administrator.
     */
    public function ubahPeran(User $pelaku, User $target): bool
    {
        return $pelaku->peran->boleh(Izin::KelolaPengguna) && $pelaku->isNot($target);
    }

    /**
     * Akun sendiri tidak dapat dihapus, dengan alasan yang sama.
     */
    public function delete(User $pelaku, User $target): bool
    {
        return $pelaku->peran->boleh(Izin::KelolaPengguna) && $pelaku->isNot($target);
    }
}
