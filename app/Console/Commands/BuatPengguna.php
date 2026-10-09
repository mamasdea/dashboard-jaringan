<?php

namespace App\Console\Commands;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\Peran;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

#[Signature('pengguna:buat
    {--nama= : Nama lengkap pengguna}
    {--email= : Alamat email untuk masuk}
    {--peran= : administrator, operator, teknisi, atau viewer}')]
#[Description('Membuat akun pengguna dashboard (pendaftaran mandiri dinonaktifkan)')]
class BuatPengguna extends Command
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $nama = $this->option('nama') ?? text('Nama lengkap', required: true);
        $email = $this->option('email') ?? text('Alamat email', required: true);
        $peran = $this->option('peran') ?? select(
            label: 'Peran',
            options: collect(Peran::cases())->mapWithKeys(fn (Peran $p) => [$p->value => $p->label()])->all(),
            default: Peran::Administrator->value,
        );
        $kataSandi = password('Kata sandi', required: true);
        $konfirmasi = password('Ulangi kata sandi', required: true);

        $validator = Validator::make([
            'name' => $nama,
            'email' => $email,
            'peran' => $peran,
            'password' => $kataSandi,
            'password_confirmation' => $konfirmasi,
        ], [
            ...$this->profileRules(),
            'peran' => ['required', Rule::enum(Peran::class)],
            'password' => $this->passwordRules(),
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $pesan) {
                $this->error($pesan);
            }

            return self::FAILURE;
        }

        $pengguna = new User([
            'name' => $nama,
            'email' => $email,
            'password' => $kataSandi,
        ]);
        $pengguna->peran = Peran::from((string) $peran);
        $pengguna->save();

        $this->info("Pengguna {$pengguna->email} berhasil dibuat sebagai {$pengguna->peran->label()}.");

        return self::SUCCESS;
    }
}
