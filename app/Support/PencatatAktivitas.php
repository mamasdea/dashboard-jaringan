<?php

namespace App\Support;

use App\Contracts\SubjekAktivitas;
use App\Enums\AksiAktivitas;
use App\Models\LogAktivitas;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class PencatatAktivitas
{
    /**
     * Kunci yang tidak boleh masuk ke rincian log.
     */
    private const array KUNCI_RAHASIA = [
        'password',
        'password_confirmation',
        'current_password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /**
     * @param  array<string, mixed>  $rincian
     */
    public function catat(
        AksiAktivitas $aksi,
        ?Model $subjek = null,
        array $rincian = [],
        ?User $pelaku = null,
    ): LogAktivitas {
        $pelaku ??= Auth::user();
        $dariKonsol = app()->runningInConsole() && ! app()->runningUnitTests();

        $rincian = array_diff_key($rincian, array_flip(self::KUNCI_RAHASIA));

        if ($dariKonsol) {
            $rincian['sumber'] = 'konsol';
        }

        return LogAktivitas::create([
            'waktu' => now(),
            'pelaku_id' => $pelaku?->id,
            'pelaku_nama' => $pelaku?->name,
            'pelaku_peran' => $pelaku?->peran->value,
            'aksi' => $aksi,
            'subjek_tipe' => $subjek?->getMorphClass(),
            'subjek_id' => $subjek?->getKey(),
            'subjek_label' => $subjek instanceof SubjekAktivitas ? $subjek->labelAktivitas() : null,
            'rincian' => $rincian === [] ? null : $rincian,
            'alamat_ip' => $dariKonsol ? null : request()->ip(),
            'agen_pengguna' => $dariKonsol ? null : Str::limit((string) request()->userAgent(), 500, ''),
        ]);
    }
}
