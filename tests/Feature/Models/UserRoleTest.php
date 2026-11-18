<?php

namespace Tests\Feature\Models;

use App\Http\Controllers\Auth\WorkOsAuthController;
use App\Models\User;
use App\Support\PaneTable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    public function test_role_is_admin_or_user(): void
    {
        $this->assertSame('admin', $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID)->role());
        $this->assertSame('user', $this->makePaneUser(User::STANDARD_USER_TYPE_ID)->role());
    }

    public function test_a_new_user_gets_a_uuid_that_never_changes(): void
    {
        $user = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $uuid = $user->uuid;

        $this->assertTrue(Str::isUuid($uuid));
        $this->assertSame($uuid, $user->fresh()->uuid);

        $user->update(['name' => 'renamed-'.$uuid, 'uuid' => (string) Str::uuid()]);
        $user->uuid = (string) Str::uuid();
        $user->save();

        $this->assertSame($uuid, $user->fresh()->uuid);
        $this->assertNotSame($uuid, $this->makePaneUser(User::STANDARD_USER_TYPE_ID)->uuid);
    }

    public function test_the_session_user_id_is_the_stored_uuid(): void
    {
        $user = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        $method = new \ReflectionMethod(WorkOsAuthController::class, 'versionedUserId');
        $controller = app(WorkOsAuthController::class);

        $this->assertSame($user->uuid, $method->invoke($controller, $user));
    }

    public function test_the_organization_tables_and_routes_are_gone(): void
    {
        foreach (['organizations', 'organization_memberships', 'organization_invitations'] as $table) {
            $this->assertFalse(Schema::hasTable(PaneTable::name($table)), "$table should not exist");
        }

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $this->assertStringNotContainsString('organizations', $route->uri());
        }
    }
}
