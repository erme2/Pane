<?php

namespace Database\Seeders;

use App\Helpers\ActionHelper;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TestSeeder extends Seeder
{
    use ActionHelper;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // some test data
        $model = $this->getModel('test_table');
        for ($i = 0; $i < 1000; $i++) {
            DB::table($model->getTable())->insert([
                'name' => fake()->name(),
                'description' => fake()->realText(100),
                'is_active' => (bool)random_int(0, 1),
                'test_date' => now()->subDays(random_int(1, 100))->toDateString(),
                'test_array' => json_encode(['this', 'is', 'an', 'array']),
                'password' => Str::random(10),
                'email' => fake()->email(),
                'test_json' => '{"some": "JSON"}',
                'numero' => random_int(1, 100),
            ]);
        }
        // some more test data
    }
}
