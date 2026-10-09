<?php

namespace App\Console\Commands;

use App\Enums\Peran;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('pengguna:peran
    {email : Alamat email pengguna}
    {peran : administrator, operator, teknisi, atau viewer}')]
#[Description('Mengubah peran pengguna dari server, mis. bila tidak ada Administrator yang dapat masuk')]
class UbahPeranPengguna extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $pengguna = User::query()->where('email', $this->argument('email'))->first();
        $peran = Peran::tryFrom((string) $this->argument('peran'));

        if ($pengguna === null) {
            $this->error('Pengguna dengan email tersebut tidak ditemukan.');

            return self::FAILURE;
        }

        if ($peran === null) {
            $this->error('Peran tidak dikenal. Pilihan: '.implode(', ', array_column(Peran::cases(), 'value')).'.');

            return self::FAILURE;
        }

        $pengguna->peran = $peran;
        $pengguna->save();

        $this->info("Peran {$pengguna->email} sekarang {$peran->label()}.");

        return self::SUCCESS;
    }
}
