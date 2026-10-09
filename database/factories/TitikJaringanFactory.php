<?php

namespace Database\Factories;

use App\Enums\JenisTitikJaringan;
use App\Models\TitikJaringan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TitikJaringan>
 */
class TitikJaringanFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kode' => 'TJ-'.fake()->unique()->numerify('#####'),
            'jenis' => JenisTitikJaringan::Closure,
            'lintang' => fake()->randomFloat(6, -7.45, -7.25),
            'bujur' => fake()->randomFloat(6, 109.80, 110.00),
        ];
    }

    public function otb(int $kapasitas = 12): static
    {
        return $this->state(fn (array $attributes) => [
            'jenis' => JenisTitikJaringan::Otb,
            'kapasitas_port' => $kapasitas,
        ]);
    }
}
