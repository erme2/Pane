<?php

namespace Tests\Unit\Helpers;

use App\Exceptions\SystemException;
use App\Exceptions\ValidationException;
use App\Helpers\PaginationHelper;
use App\Stories\StoryPlot;
use Tests\TestCase;

class PaginationHelperTest extends TestCase
{
    use PaginationHelper;

    /**
     * @covers \App\Helpers\PaginationHelper::getPaginationData
     *
     * @return void
     * @throws ValidationException
     * @throws \App\Exceptions\SystemException
     */
    public function test_get_pagination_data_with_default_values(): void
    {

        $plot = new StoryPlot();
        $plot->requestData = ['data' => []];

        $result = $this->getPaginationData($plot, self::TEST_TABLE_NAME);
        $this->assertEquals(25, $result['limit']); // PAGINATION_LIMIT
        $this->assertEquals('asc', $result['order']); // PAGINATION_ORDER
        $this->assertEquals(self::TEST_TABLE_PRIMARY_KEY, $result['sort']); // Primary key
        $this->assertEquals(1, $result['page']); // Default page
        $this->assertEquals(0, $result['offset']); // (1-1) * 25
    }

    /**
     * @covers \App\Helpers\PaginationHelper::getPaginationData
     *
     * @return void
     * @throws ValidationException
     * @throws \App\Exceptions\SystemException
     */
    public function test_get_pagination_with_custom_values(): void
    {
        $plot = new StoryPlot();
        $plot->requestData = [
            'data' => [
                'limit' => 50,
                'order' => 'desc',
                'sort' => 'name',
                'page' => 3
            ]
        ];
        $offset = $plot->requestData['data']['limit'] * ($plot->requestData['data']['page'] - 1 ); // (3-1) * 50 = 100

        $result = $this->getPaginationData($plot, self::TEST_TABLE_NAME);

        $this->assertEquals($plot->requestData['data']['limit'], $result['limit']);
        $this->assertEquals($plot->requestData['data']['order'], $result['order']);
        $this->assertEquals($plot->requestData['data']['sort'], $result['sort']);
        $this->assertEquals($plot->requestData['data']['page'], $result['page']);
        $this->assertEquals($offset, $result['offset']);
    }

    /**
     * @covers \App\Helpers\PaginationHelper::getPaginationData
     *
     * @return void
     * @throws SystemException
     */
    public function test_get_pagination_data_validates_limit_min(): void
    {
        $plot = new StoryPlot();
        $plot->requestData = [
            'data' => [
                'limit' => 0
            ]
        ];

        $this->expectException(ValidationException::class);
        $this->getPaginationData($plot, self::TEST_TABLE_NAME);
    }

    /**
     * @covers \App\Helpers\PaginationHelper::getPaginationData
     *
     * @return void
     * @throws SystemException
     */
    public function test_get_pagination_data_validates_limit_max(): void
    {
        $plot = new StoryPlot();
        $plot->requestData = [
            'data' => [
                'limit' => 101 // Greater than PAGINATION_MAX (100)
            ]
        ];

        $this->expectException(ValidationException::class);
        $this->getPaginationData($plot, self::TEST_TABLE_NAME);
    }

    /**
     * @covers \App\Helpers\PaginationHelper::getPaginationData
     *
     * @return void
     * @throws SystemException
     */
    public function test_get_pagination_data_validates_invalid_order(): void
    {
        $plot = new StoryPlot();
        $plot->requestData = [
            'data' => [
                'order' => 'invalid'
            ]
        ];

        $this->expectException(ValidationException::class);
        $this->getPaginationData($plot, self::TEST_TABLE_NAME);
    }

    /**
     * @covers \App\Helpers\PaginationHelper::getPaginationData
     *
     * @return void
     * @throws SystemException
     */
    public function test_get_pagination_data_validates_invalid_sort_field(): void
    {
        $plot = new StoryPlot();
        $plot->requestData = [
            'data' => [
                'sort' => 'non_indexable_field'
            ]
        ];

        $this->expectException(ValidationException::class);
        $this->getPaginationData($plot, self::TEST_TABLE_NAME);
    }

    /**
     * @covers \App\Helpers\PaginationHelper::getPaginationData
     *
     * @return void
     * @throws SystemException
     */
    public function test_get_pagination_data_validates_page_min(): void
    {
        $plot = new StoryPlot();
        $plot->requestData = [
            'data' => [
                'page' => 0
            ]
        ];

        $this->expectException(ValidationException::class);
        $this->getPaginationData($plot, self::TEST_TABLE_NAME);
    }

    /**
     * @covers \App\Helpers\PaginationHelper::getPaginationData
     *
     * @return void
     * @throws ValidationException
     * @throws \App\Exceptions\SystemException
     */
    public function test_get_pagination_data_handles_string_numbers(): void
    {
        $plot = new StoryPlot();
        $plot->requestData = [
            'data' => [
                'limit' => '10',
                'page' => '2'
            ]
        ];

        $result = $this->getPaginationData($plot, self::TEST_TABLE_NAME);

        $this->assertIsInt($result['limit']);
        $this->assertIsInt($result['page']);
        $this->assertEquals(10, $result['limit']);
        $this->assertEquals(2, $result['page']);
        $this->assertEquals(10, $result['offset']); // (2-1) * 10
    }

    /**
     * @covers \App\Helpers\PaginationHelper::getPaginationData
     *
     * @return void
     * @throws ValidationException
     * @throws \App\Exceptions\SystemException
     */
    public function test_get_pagination_data_calculates_offset_correctly(): void
    {
        $testCases = [
            ['page' => 1, 'limit' => 25, 'expected_offset' => 0],
            ['page' => 2, 'limit' => 25, 'expected_offset' => 25],
            ['page' => 3, 'limit' => 10, 'expected_offset' => 20],
            ['page' => 5, 'limit' => 50, 'expected_offset' => 200],
        ];

        foreach ($testCases as $testCase) {
            $plot = new StoryPlot();
            $plot->requestData = [
                'data' => [
                    'page' => $testCase['page'],
                    'limit' => $testCase['limit']
                ]
            ];

            $result = $this->getPaginationData($plot, self::TEST_TABLE_NAME);

            $this->assertEquals(
                $testCase['expected_offset'],
                $result['offset'],
                "Failed for page {$testCase['page']} with limit {$testCase['limit']}"
            );
        }
    }

    /**
     * @covers \App\Helpers\PaginationHelper::getPaginationData
     *
     * @return void
     * @throws ValidationException
     * @throws \App\Exceptions\SystemException
     */
    public function test_get_pagination_data_with_partial_data(): void
    {
        // Test with only limit specified
        $plot = new StoryPlot();
        $plot->requestData = [
            'data' => [
                'limit' => 15
            ]
        ];

        $result = $this->getPaginationData($plot, self::TEST_TABLE_NAME);

        $this->assertEquals(15, $result['limit']);
        $this->assertEquals('asc', $result['order']); // Default
        $this->assertEquals(self::TEST_TABLE_PRIMARY_KEY, $result['sort']); // Default to primary key
        $this->assertEquals(1, $result['page']); // Default
        $this->assertEquals(0, $result['offset']);
    }

    /**
     * @covers \App\Helpers\PaginationHelper::getPaginationData
     *
     * @return void
     * @throws SystemException
     */
    public function test_get_pagination_data_validates_data_types(): void
    {
        // Test invalid limit type
        $plot = new StoryPlot();
        $plot->requestData = [
            'data' => [
                'limit' => 'not_a_number'
            ]
        ];

        $this->expectException(ValidationException::class);
        $this->getPaginationData($plot, self::TEST_TABLE_NAME);
    }
}
