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
            'empty system prefix, app matches a Pane table' => ['', 'org'],
            'empty system prefix, app is a whole Pane table name' => ['', 'messages'],
            'empty system prefix, app matches a maps table' => ['', 'maps_'],
            'empty system prefix, app matches a framework table' => ['', 'migr'],
            'empty system prefix, app matches the legacy map_ tables' => ['', 'map'],
            'empty system prefix, app starts with the legacy map_' => ['', 'map_sweegly_'],
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
        TablePrefixes::assertNoOverlap('', 'sweegly_');

        $this->addToAssertionCount(4);
    }

    public function test_pane_refuses_to_boot_with_overlapping_prefixes(): void
    {
        config(['database.table_prefix' => 'pane_', 'database.app_table_prefix' => 'pane_sweegly_']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('overlaps DB_TABLE_PREFIX');

        (new AppServiceProvider($this->app))->register();
    }

    public function test_pane_boots_with_an_empty_system_prefix_and_an_app_prefix(): void
    {
        config(['database.table_prefix' => '', 'database.app_table_prefix' => 'sweegly_']);

        (new AppServiceProvider($this->app))->register();

        $this->addToAssertionCount(1);
    }
}
