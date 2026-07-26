<?php

namespace Guggach\LaravelDbTemporal\tests\database;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UniTemporalIdSeeder extends Seeder
{
    /**
     * Run the database seeders.
     */
    public function run(): void
    {

        $records = [
            0 => [
                'id' => 1,
                'known_from' => '2023-05-01 00:00:00',
                'known_to' => '2023-05-05 16:59:59',
                'text' => 'first record',
            ],
            1 => [
                'id' => 1,
                'known_from' => '2023-05-05 17:00:00',
                'known_to' => '2023-05-10 16:59:59',
                'text' => 'second record',
            ],
            2 => [
                'id' => 1,
                'known_from' => '2023-05-10 17:00:00',
                'known_to' => '2023-05-15 16:59:59',
                'text' => 'third record',
            ],
            3 => [
                'id' => 1,
                'known_from' => '2023-05-15 17:00:00',
                'known_to' => '9999-12-31 23:59:59',
                'text' => 'fourth record',
            ],
        ];

        foreach ($records as $record) {
            DB::table('uni_temporal_ids')->insert($record);
        }
    }
}
