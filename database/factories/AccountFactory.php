<?php

namespace Database\Factories;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Account> */
class AccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => (string) fake()->unique()->numberBetween(1000, 9999),
            'name' => ucfirst(fake()->words(2, true)),
            'type' => fake()->randomElement(AccountType::cases()),
            'is_active' => true,
        ];
    }

    public function type(AccountType $type): static
    {
        return $this->state(fn () => ['type' => $type]);
    }
}
