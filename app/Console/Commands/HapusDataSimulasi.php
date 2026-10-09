<?php

namespace App\Console\Commands;

use App\Models\EndpointMonitoring;
use App\Models\KoneksiEndpoint;
use App\Models\Opd;
use App\Models\Perangkat;
use App\Models\PortTerminasi;
use App\Models\ProfilSnmp;
use App\Models\Sambungan;
use App\Models\SegmenKabel;
use App\Models\Tiang;
use App\Models\Tiket;
use App\Models\TitikJaringan;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('simulasi:hapus {--force : Hapus tanpa konfirmasi}')]
#[Description('Menghapus seluruh OPD, perangkat, inventaris FO, dan tiket berlabel SIMULASI')]
class HapusDataSimulasi extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $jumlahPerangkat = Perangkat::query()->simulasi()->count();
        $jumlahOpd = Opd::query()->simulasi()->count();
        $jumlahMonitoring = EndpointMonitoring::query()->where('simulasi', true)->count() + ProfilSnmp::query()->simulasi()->count();
        $jumlahFo = Tiang::query()->simulasi()->count() + TitikJaringan::query()->simulasi()->count() + SegmenKabel::query()->simulasi()->count();

        $jumlahTiket = Tiket::query()->where('simulasi', true)->count();

        if ($jumlahPerangkat + $jumlahOpd + $jumlahFo + $jumlahMonitoring + $jumlahTiket === 0) {
            $this->info('Tidak ada data SIMULASI.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm("Hapus {$jumlahPerangkat} perangkat, {$jumlahOpd} OPD, dan {$jumlahFo} objek FO berlabel SIMULASI?")) {
            return self::FAILURE;
        }

        $tersisa = DB::transaction(function (): int {
            // Tiket contoh beserta catatan dan fotonya.
            Tiket::query()->where('simulasi', true)->get()->each->delete();

            // Hasil, status, insiden, dan riwayat ikut terhapus bersama endpoint-nya.
            EndpointMonitoring::query()->where('simulasi', true)->delete();
            ProfilSnmp::query()->simulasi()->whereDoesntHave('endpoint')->delete();

            $this->hapusFo();

            Perangkat::query()->simulasi()->get()->each->delete();

            // OPD simulasi yang sudah diisi perangkat nyata tidak ikut dihapus.
            [$kosong, $terpakai] = Opd::query()->simulasi()->withCount('perangkat')->get()
                ->partition(fn (Opd $opd) => $opd->perangkat_count === 0);

            $kosong->each->delete();

            return $terpakai->count();
        });

        $this->info('Data SIMULASI dihapus.');

        if ($tersisa > 0) {
            $this->warn("{$tersisa} OPD SIMULASI tidak dihapus karena masih memiliki perangkat nyata.");
        }

        return self::SUCCESS;
    }

    /**
     * Hubungan dahulu, lalu objeknya. Data contoh dihapus langsung, tanpa
     * menambah riwayat hubungan.
     */
    private function hapusFo(): void
    {
        $titik = TitikJaringan::query()->simulasi()->select('id');
        $segmen = SegmenKabel::query()->simulasi()->select('id');
        $port = PortTerminasi::query()->whereIn('titik_jaringan_id', $titik)->select('id');

        KoneksiEndpoint::query()
            ->whereIn('port_terminasi_id', $port)
            ->orWhereIn('titik_jaringan_id', $titik)
            ->orWhereHas('portPerangkat.perangkat', fn ($query) => $query->where('simulasi', true))
            ->delete();
        Sambungan::query()->whereIn('titik_jaringan_id', $titik)->delete();
        PortTerminasi::query()->whereIn('titik_jaringan_id', $titik)->update(['core_id' => null]);

        // Objek simulasi yang sudah terhubung ke data nyata dibiarkan.
        SegmenKabel::query()->whereIn('id', $segmen)
            ->whereNotIn('id', DB::table('pemakaian_ujung_core')->join('core_kabel', 'core_kabel.id', '=', 'core_id')->select('segmen_kabel_id'))
            ->get()->each->delete();
        TitikJaringan::query()->simulasi()
            ->whereNotIn('id', SegmenKabel::query()->whereNotNull('titik_awal_id')->select('titik_awal_id'))
            ->whereNotIn('id', SegmenKabel::query()->whereNotNull('titik_akhir_id')->select('titik_akhir_id'))
            ->get()->each->delete();
        Tiang::query()->simulasi()
            ->whereDoesntHave('segmen')
            ->whereDoesntHave('titikJaringan')
            ->get()->each->delete();
    }
}
