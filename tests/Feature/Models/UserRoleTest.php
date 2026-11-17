<?php

namespace Tests\Feature\Models;

use App\Models\User;
use App\Support\PaneTable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    public function test_role_is_admin_or_user(): void
    {
        $this->assertSame('admin', $this->makePaneUser(User::PANE_ADMINISTRATOR_USER_TYPE_ID)->role());
        $this->assertSame('user', $this->makePaneUser(User::STANDARD_USER_TYPE_ID)->role());
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
