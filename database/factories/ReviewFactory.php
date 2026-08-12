<?php

namespace Database\Factories;

use App\Models\Review;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Review>
 */
class ReviewFactory extends Factory
{
    protected $model = Review::class;

    public function definition(): array
    {
        return [
            'author_name' => $this->faker->name(),
            'rating' => $this->faker->numberBetween(4, 5),
            'body' => $this->faker->sentence(12),
            'is_approved' => true,
            'sort_order' => 0,
        ];
    }
}
