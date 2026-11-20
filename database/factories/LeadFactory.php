<?php

namespace Database\Factories;

use App\Enums\LeadSource;
use App\Enums\LeadStatus;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'source' => LeadSource::WhatsappCustomer,
            'external_id' => fake()->unique()->uuid(),
            'name' => fake()->name(),
            'phone' => fake()->numerify('98########'),
            'city' => fake()->city(),
            'status' => LeadStatus::New,
        ];
    }
}
