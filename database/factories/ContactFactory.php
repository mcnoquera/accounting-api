<?php

namespace Database\Factories;

use App\Enums\ContactType;
use App\Models\Contact;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Contact> */
class ContactFactory extends Factory
{
    protected $model = Contact::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'type' => ContactType::Customer,
            'payment_terms_days' => 30,
            'is_active' => true,
        ];
    }

    public function supplier(): static
    {
        return $this->state(['type' => ContactType::Supplier]);
    }
}
