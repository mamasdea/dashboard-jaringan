<?php

namespace Database\Factories;

use App\Enums\StatusTiket;
use App\Models\Tiket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tiket>
 */
class TiketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'judul' => 'Gangguan uji '.fake()->unique()->numerify('####'),
            'waktu_mulai' => now()->subHour(),
        ];
    }

    public function untuk(User $teknisi): static
    {
        return $this->state(fn (array $attributes) => [
            'teknisi_id' => $teknisi->id,
            'status' => StatusTiket::Ditugaskan,
        ]);
    }

    public function selesai(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => StatusTiket::Selesai,
            'waktu_pemulihan' => now(),
        ]);
    }
}
