<?php

namespace App\Console\Commands;

use App\Monitoring\Kolektor;
use App\Monitoring\Kontrak\AdapterPemeriksa;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('monitoring:kolektor
    {--sekali : Jalankan satu putaran lalu berhenti}
    {--jeda=5 : Jeda antar-putaran dalam detik}')]
#[Description('Menjalankan kolektor monitoring (proses terpisah dari aplikasi web)')]
class JalankanKolektor extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(Kolektor $kolektor, AdapterPemeriksa $adapter): int
    {
        $jeda = max(1, (int) $this->option('jeda'));

        $this->info(sprintf(
            'Kolektor berjalan dengan adapter "%s"%s.',
            $adapter->nama(),
            $adapter->simulasi() ? ' (SIMULASI: tidak ada paket yang dikirim ke jaringan)' : '',
        ));

        do {
            $hasil = $kolektor->jalankanSekali();

            if ($hasil['diperiksa'] > 0 || $hasil['disapu'] > 0 || $this->option('sekali')) {
                $this->line(sprintf('[%s UTC] %d endpoint diperiksa, %d status disapu.', now()->utc()->format('H:i:s'), $hasil['diperiksa'], $hasil['disapu']));
            }

            if (! $this->option('sekali')) {
                sleep($jeda);
            }
        } while (! $this->option('sekali'));

        return self::SUCCESS;
    }
}
