<?php

namespace App\Observers;

use App\Enums\AksiAktivitas;
use App\Enums\Peran;
use App\Models\User;
use App\Support\PencatatAktivitas;

class UserObserver
{
    /**
     * Kolom profil yang perubahannya dicatat beserta nilai lama dan baru.
     */
    private const array KOLOM_PROFIL = ['name', 'email'];

    public function __construct(private PencatatAktivitas $pencatat) {}

    public function created(User $user): void
    {
        $this->pencatat->catat(AksiAktivitas::PenggunaDibuat, $user, [
            'peran' => $user->peran->value,
        ]);
    }

    public function updated(User $user): void
    {
        $perubahan = [];

        foreach (self::KOLOM_PROFIL as $kolom) {
            if ($user->wasChanged($kolom)) {
                $perubahan[$kolom] = ['dari' => $user->getOriginal($kolom), 'menjadi' => $user->getAttribute($kolom)];
            }
        }

        if ($perubahan !== []) {
            $this->pencatat->catat(AksiAktivitas::PenggunaDiubah, $user, $perubahan);
        }

        if ($user->wasChanged('peran')) {
            $peranLama = $user->getOriginal('peran');

            $this->pencatat->catat(AksiAktivitas::PeranDiubah, $user, [
                'dari' => $peranLama instanceof Peran ? $peranLama->value : $peranLama,
                'menjadi' => $user->peran->value,
            ]);
        }

        if ($user->wasChanged('password')) {
            $this->pencatat->catat(AksiAktivitas::KataSandiDiubah, $user);
        }
    }

    public function deleted(User $user): void
    {
        $this->pencatat->catat(AksiAktivitas::PenggunaDihapus, $user, [
            'peran' => $user->peran->value,
        ]);
    }
}
