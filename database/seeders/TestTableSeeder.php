<?php

namespace Database\Seeders;

use App\Helpers\ActionHelper;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TestTableSeeder extends Seeder
{
    use ActionHelper;

    public static function getStaticRecords($sqlString = false): array
    {
        $testArray = ['this', 'is', 'an', 'array'];
        $testJson = (object)['some' => 'JSON'];
        return [
            [
                'name' => 'Test Table',
                'description' => 'Just a table to run tests',
                'is_active' => true,
                'test_date' => '2021-01-01 00:00:00',
                'test_array' => $sqlString ? json_encode($testArray, JSON_THROW_ON_ERROR) : $testArray,
                'password' => 'password',
                'email' => 'test@email.com',
                'test_json' => $sqlString ? json_encode($testJson, JSON_THROW_ON_ERROR) : $testJson,
                'numero' => 33,
            ],
        ];
    }

    public static function randomRecord(bool $sqlString = false, array $presets = []): array
    {
        $array = ['this', 'is', 'an', 'array'];
        $object = (object) ["some" => "JSON"];
        return [
            'name' => $presets['name'] ?? fake()->name(),
            'description' => $presets['description'] ?? fake()->realText(100),
            'is_active' => $presets['is_active'] ?? (bool) random_int(0, 1),
            'test_date' => $presets['test_date'] ?? now()->subDays(random_int(1, 100))->toDateString(),
            'test_array' => $sqlString ? json_encode($array) : $array,
            'password' => $presets['password'] ?? Str::random(10),
            'email' => $presets['email'] ?? fake()->email(),
            'test_json' => $sqlString ? json_encode($object) : $object,
            'numero' => $presets['numero'] ?? random_int(1, 100),
        ];
    }


    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // some test data
        $model = $this->getModel('test_table');
        DB::table($model->getTable())->truncate();
        DB::table($model->getTable())->insert(self::getStaticRecords(true));
        for ($i = 0; $i < 1000; $i++) {
            DB::table($model->getTable())->insert(self::randomRecord(true, [
                'email' => "test$i@email.com",
            ]));
        }
        // some more test data
    }
}
