<?php

namespace App\Policies;

use App\Enums\Izin;
use App\Models\Opd;
use App\Models\User;

class OpdPolicy
{
    public function viewAny(User $pelaku): bool
    {
        return $pelaku->boleh(Izin::LihatAset);
    }

    public function view(User $pelaku, Opd $opd): bool
    {
        return $pelaku->boleh(Izin::LihatAset);
    }

    public function create(User $pelaku): bool
    {
        return $pelaku->boleh(Izin::KelolaAset);
    }

    public function update(User $pelaku, Opd $opd): bool
    {
        return $pelaku->boleh(Izin::KelolaAset);
    }

    public function delete(User $pelaku, Opd $opd): bool
    {
        return $pelaku->boleh(Izin::KelolaAset);
    }
}
