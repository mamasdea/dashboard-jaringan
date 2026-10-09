<?php

namespace Database\Factories;

use App\Enums\KlasifikasiKabel;
use App\Models\SegmenKabel;
use App\Models\TitikJaringan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SegmenKabel>
 */
class SegmenKabelFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kode' => 'SK-'.fake()->unique()->numerify('#####'),
            'klasifikasi' => KlasifikasiKabel::Distribusi,
            'jumlah_core' => 12,
            'titik_awal_id' => TitikJaringan::factory(),
            'titik_akhir_id' => TitikJaringan::factory(),
            'panjang_ukur_m' => 500,
            'jalur_peta' => [[109.9000, -7.3600], [109.9050, -7.3600]],
        ];
    }

    public function antara(TitikJaringan $awal, TitikJaringan $akhir): static
    {
        return $this->state(fn (array $attributes) => [
            'titik_awal_id' => $awal->id,
            'titik_akhir_id' => $akhir->id,
        ]);
    }

    /**
     * Hanya kode: pendataan tahap pertama.
     */
    public function belumLengkap(): static
    {
        return $this->state(fn (array $attributes) => [
            'klasifikasi' => null, 'jumlah_core' => null, 'titik_awal_id' => null, 'titik_akhir_id' => null,
            'panjang_ukur_m' => null, 'jalur_peta' => null,
        ]);
    }
}
