<?php

namespace App\Policies;

use App\Enums\Izin;
use App\Models\Perangkat;
use App\Models\User;

class PerangkatPolicy
{
    public function viewAny(User $pelaku): bool
    {
        return $pelaku->boleh(Izin::LihatAset);
    }

    public function view(User $pelaku, Perangkat $perangkat): bool
    {
        return $pelaku->boleh(Izin::LihatAset);
    }

    public function create(User $pelaku): bool
    {
        return $pelaku->boleh(Izin::KelolaAset);
    }

    public function update(User $pelaku, Perangkat $perangkat): bool
    {
        return $pelaku->boleh(Izin::KelolaAset);
    }

    public function delete(User $pelaku, Perangkat $perangkat): bool
    {
        return $pelaku->boleh(Izin::KelolaAset);
    }
}
