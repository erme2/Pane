<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    const TEST_TABLE_NAME = 'test_table';
    const TEST_TABLE_PRIMARY_KEY = 'table_id';

    const array VALID_TEST_TABLE_RECORD = [
        'name' => 'just a test name',
        'description' => 'a basic description',
        'is_active' => true,
        'test_date' => '07-03-2017',
        'test_array' => ['this', 'is', 'an', 'array'],
        'password' => 'Pa$$word123#',
        'email' => 'test@email.com',
        'test_json' => '{"some": "JSON"}',
        'numero' => 33,
    ];

    const array INVALID_TEST_TABLE_RECORD =  [
        'name' => '',
        'description' => [1 => 2],
        'is_active' => ['aa'],
        'test_date' => 'not a date',
        'test_array' => 'not an array',
        'password' => '123',
        'email' => 'invalid@email.con',
        'test_json' => 'not json',
        'numero' => 3,
    ];

    const array UPDATED_VALID_TEST_TABLE_RECORD = [
        'table_id' => 2, // this is the primary key, it should be present in the record to update
        'name' => 'this name was updated',
        'description' => 'this description was updated',
        'is_active' => false,
        'test_date' => '22-04-2021',
        'test_array' => ['this', 'is', 'another', 'array'],
        'password' => 'Hacked?123#',
        'email' => 'another@email.com',
        'test_json' => '{"some": "JSON", "more": "data"}',
        'numero' => 55
    ];
}
