<?php

namespace Database\Factories;

use App\Enums\JenisPerangkat;
use App\Enums\PenempatanPerangkat;
use App\Models\Opd;
use App\Models\Perangkat;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Perangkat>
 */
class PerangkatFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'opd_id' => Opd::factory(),
            'penempatan' => PenempatanPerangkat::Opd,
            'lokasi' => 'Ruang server',
            'kode_aset' => 'AST-'.fake()->unique()->numerify('#####'),
            'nama' => 'Switch '.fake()->unique()->word(),
            'jenis' => JenisPerangkat::Switch,
            'merek' => 'Merek Uji',
            'model' => 'Model-'.fake()->numerify('###'),
            'nomor_seri' => fake()->unique()->bothify('SN########'),
            // 203.0.113.0/24 adalah rentang dokumentasi (RFC 5737), bukan alamat nyata. Pengujian yang
            // menetapkan IP sendiri memakai 198.51.100.0/24, sehingga tidak pernah bentrok dengan nilai acak ini.
            'ip_manajemen' => '203.0.113.'.fake()->unique()->numberBetween(1, 254),
            'port_uplink' => 'GigabitEthernet0/1',
            'monitoring_aktif' => false,
            'switch_utama' => false,
            'catatan' => null,
            'simulasi' => false,
        ];
    }

    public function switchUtama(): static
    {
        return $this->state(fn (array $attributes) => [
            'jenis' => JenisPerangkat::Switch,
            'switch_utama' => true,
        ]);
    }

    public function dipantau(): static
    {
        return $this->state(fn (array $attributes) => ['monitoring_aktif' => true]);
    }

    public function simulasi(): static
    {
        return $this->state(fn (array $attributes) => ['simulasi' => true]);
    }
}
