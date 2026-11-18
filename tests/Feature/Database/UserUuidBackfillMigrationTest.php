<?php

namespace Tests\Feature\Database;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserUuidBackfillMigrationTest extends TestCase
{
    public function test_backfill_gives_every_existing_user_a_unique_uuid(): void
    {
        $table = (new User)->getTable();

        for ($i = 0; $i < 250; $i++) {
            $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        }

        DB::table($table)->update(['uuid' => null]);

        $migration = require base_path('database/migrations/2026_11_18_090000_add_uuid_to_users_table.php');

        Schema::table($table, function ($blueprint) {
            $blueprint->dropUnique(['uuid']);
            $blueprint->dropColumn('uuid');
        });

        $migration->up();

        $this->assertSame(0, DB::table($table)->whereNull('uuid')->count());
        $uuids = DB::table($table)->pluck('uuid');
        $this->assertCount($uuids->unique()->count(), $uuids);
        $this->assertTrue($uuids->every(fn ($uuid) => Str::isUuid($uuid)));
    }
}
