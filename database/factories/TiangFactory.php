<?php

namespace Database\Factories;

use App\Enums\KondisiTiang;
use App\Enums\PemilikTiang;
use App\Models\Tiang;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tiang>
 */
class TiangFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kode' => 'TG-'.fake()->unique()->numerify('#####'),
            'lintang' => fake()->randomFloat(6, -7.45, -7.25),
            'bujur' => fake()->randomFloat(6, 109.80, 110.00),
            'pemilik' => PemilikTiang::Diskominfo,
            'kondisi' => KondisiTiang::Baik,
        ];
    }

    /**
     * Hanya kode: pendataan tahap pertama.
     */
    public function belumLengkap(): static
    {
        return $this->state(fn (array $attributes) => [
            'lintang' => null, 'bujur' => null, 'pemilik' => null, 'kondisi' => null,
        ]);
    }
}
