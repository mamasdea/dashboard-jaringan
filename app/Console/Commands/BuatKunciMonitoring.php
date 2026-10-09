<?php

namespace App\Console\Commands;

use App\Support\BrankasKredensial;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('monitoring:buat-kunci')]
#[Description('Membuat pasangan kunci untuk menyegel kredensial SNMP')]
class BuatKunciMonitoring extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $kunci = BrankasKredensial::buatPasanganKunci();

        $this->line('Tambahkan ke .env. Perintah ini tidak menulis berkas apa pun.');
        $this->newLine();
        $this->line('# Host web dan host kolektor:');
        $this->line('MONITORING_KUNCI_PUBLIK='.$kunci['publik']);
        $this->newLine();
        $this->line('# HANYA host kolektor. Jangan dipasang di host web dan jangan di-commit:');
        $this->line('MONITORING_KUNCI_PRIVAT='.$kunci['privat']);
        $this->newLine();
        $this->warn('Mengganti kunci membuat kredensial yang sudah tersimpan tidak dapat dibuka; kredensial harus diisi ulang.');

        return self::SUCCESS;
    }
}
