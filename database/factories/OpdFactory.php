<?php

namespace Database\Factories;

use App\Models\Opd;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Opd>
 */
class OpdFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kode' => 'OPD-'.fake()->unique()->numerify('####'),
            'nama' => 'Dinas Uji '.fake()->unique()->numerify('####'),
            'alamat' => fake()->address(),
            'lintang' => fake()->randomFloat(6, -7.45, -7.25),
            'bujur' => fake()->randomFloat(6, 109.80, 110.00),
            'kontak_teknis_nama' => fake()->name(),
            'kontak_teknis_telepon' => '0286 '.fake()->numerify('######'),
            'kontak_teknis_email' => fake()->unique()->safeEmail(),
            'aktif' => true,
            'simulasi' => false,
        ];
    }

    public function nonaktif(): static
    {
        return $this->state(fn (array $attributes) => ['aktif' => false]);
    }

    public function simulasi(): static
    {
        return $this->state(fn (array $attributes) => ['simulasi' => true]);
    }
}
