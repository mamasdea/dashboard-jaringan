<?php

namespace Database\Factories;

use App\Models\EndpointMonitoring;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EndpointMonitoring>
 */
class EndpointMonitoringFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'nama' => 'Endpoint uji '.fake()->unique()->numerify('####'),
            // 198.51.100.0/24 adalah rentang dokumentasi (RFC 5737), bukan alamat nyata.
            'alamat' => '198.51.100.'.fake()->unique()->numberBetween(1, 254),
            'metode' => 'icmp',
            'interval_detik' => 60,
            'aktif' => true,
            'skenario_simulasi' => 'normal',
        ];
    }

    public function skenario(string $skenario): static
    {
        return $this->state(fn (array $attributes) => ['skenario_simulasi' => $skenario]);
    }
}
