<?php

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Business> */
class BusinessFactory extends Factory
{
    protected $model = Business::class;

    public function definition(): array
    {
        return [
            'name'         => fake()->company(),
            'phone'        => null,
            'country_code' => 'FJ',
            'timezone'     => 'Pacific/Fiji',
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => AccountStatus::Suspended]);
    }
}
