<?php

namespace App\Policies;

use App\Enums\Izin;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Kebijakan bersama objek inventaris: melihat butuh `lihat-aset`, mengubah
 * butuh `kelola-aset`.
 */
abstract class KebijakanAset
{
    public function viewAny(User $pelaku): bool
    {
        return $pelaku->boleh(Izin::LihatAset);
    }

    public function view(User $pelaku, Model $objek): bool
    {
        return $pelaku->boleh(Izin::LihatAset);
    }

    public function create(User $pelaku): bool
    {
        return $pelaku->boleh(Izin::KelolaAset);
    }

    public function update(User $pelaku, Model $objek): bool
    {
        return $pelaku->boleh(Izin::KelolaAset);
    }

    public function delete(User $pelaku, Model $objek): bool
    {
        return $pelaku->boleh(Izin::KelolaAset);
    }
}
