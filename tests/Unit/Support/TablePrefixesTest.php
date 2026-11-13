<?php

namespace Tests\Unit\Support;

use App\Providers\AppServiceProvider;
use App\Support\Maps\TablePrefixes;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TablePrefixesTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function overlapping(): array
    {
        return [
            'same prefix' => ['pane_', 'pane_'],
            'app starts with system' => ['pane_', 'pane_app_'],
            'system starts with app' => ['pane_', 'pan'],
            'empty system prefix' => ['', 'sweegly_'],
        ];
    }

    #[DataProvider('overlapping')]
    public function test_overlapping_prefixes_are_refused(string $system, string $app): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_TABLE_PREFIX');

        TablePrefixes::assertNoOverlap($system, $app);
    }

    public function test_separate_or_empty_app_prefixes_are_accepted(): void
    {
        TablePrefixes::assertNoOverlap('pane_', 'sweegly_');
        TablePrefixes::assertNoOverlap('pane_', '');
        TablePrefixes::assertNoOverlap('', '');

        $this->addToAssertionCount(3);
    }

    public function test_pane_refuses_to_boot_with_overlapping_prefixes(): void
    {
        config(['database.table_prefix' => 'pane_', 'database.app_table_prefix' => 'pane_sweegly_']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('overlaps DB_TABLE_PREFIX');

        (new AppServiceProvider($this->app))->register();
    }
}
