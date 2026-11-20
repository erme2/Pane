<?php

namespace Tests\Feature\crud\Integration;

use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\RealDatabaseServer;
use Tests\TestCase;

/**
 * The step 2 gate (Pane#194): a fixture copy of Sweegly's data folder is
 * installed with app:install on a real MySQL or MariaDB server (CI runs both),
 * then the CRUD route is exercised over lists, list_members, list_items,
 * products and categories as an administrator and as users. Skipped unless
 * PANE_IT_DB_HOST is set; see docs/testing.md.
 */
class SweeglyCrudTest extends TestCase
{
    private RealDatabaseServer $server;

    private string $previousConnection;

    private User $admin;

    private User $owner;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        if (! RealDatabaseServer::configured()) {
            $this->markTestSkipped('Set PANE_IT_DB_HOST (and PANE_IT_DB_ENGINE, _PORT, _ROOT_PASSWORD) to run against a real database.');
        }

        $this->server = new RealDatabaseServer;
        $this->previousConnection = config('database.default');
        config(['database.connections.sweegly_real' => [
            'driver' => $this->server->engine,
            'host' => $this->server->host,
            'port' => $this->server->port,
            'database' => $this->server->database,
            'username' => 'root',
            'password' => (string) getenv('PANE_IT_DB_ROOT_PASSWORD'),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]]);
        config(['database.default' => 'sweegly_real', 'database.table_prefix' => 'pane_', 'database.app_table_prefix' => 'fixt_']);
        DB::purge('sweegly_real');

        Artisan::call('migrate', ['--force' => true]);
        $this->artisan('app:install', ['path' => base_path('tests/Fixtures/sweegly-data')])->assertSuccessful();

        $this->admin = $this->makePaneUser(1);
        $this->owner = $this->makePaneUser(2);
        $this->other = $this->makePaneUser(2);
    }

    protected function tearDown(): void
    {
        if (isset($this->server)) {
            config(['database.default' => $this->previousConnection]);
            DB::purge('sweegly_real');
            $this->server->cleanup();
        }

        parent::tearDown();
    }

    public function test_a_user_creates_reads_lists_updates_and_deletes_their_list(): void
    {
        $this->actingAs($this->owner);

        $created = $this->withCsrfToken()->postJson('/crud/lists', ['name' => 'Groceries', 'created_by_membership_id' => $this->owner->uuid]);
        $created->assertStatus(Response::HTTP_CREATED);
        $listId = (int) DB::table('fixt_lists')->value('id');
        $this->assertDatabaseHas('fixt_list_members', ['list_id' => $listId, 'membership_id' => $this->owner->uuid]);

        $this->getJson("/crud/lists/$listId")->assertOk()->assertJsonPath('data.0.name', 'Groceries');
        $this->getJson('/crud/lists')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pagination.total', 1);

        $this->withCsrfToken()->putJson("/crud/lists/$listId", ['name' => 'Weekly', 'created_by_membership_id' => $this->owner->uuid])->assertOk();
        $this->assertSame('Weekly', DB::table('fixt_lists')->where('id', $listId)->value('name'));

        $this->withCsrfToken()->deleteJson("/crud/lists/$listId")->assertStatus(Response::HTTP_NO_CONTENT);
        $this->assertSame(0, DB::table('fixt_lists')->count());
        $this->assertSame(0, DB::table('fixt_list_members')->count());
    }

    public function test_a_member_manages_list_items(): void
    {
        $listId = $this->listFor($this->owner, 'Groceries');
        $this->actingAs($this->owner);

        $this->withCsrfToken()->postJson('/crud/list_items', ['list_id' => $listId, 'text' => 'Milk', 'position' => 1])
            ->assertStatus(Response::HTTP_CREATED);
        $itemId = (int) DB::table('fixt_list_items')->value('id');

        $this->getJson("/crud/list_items/$itemId")->assertOk()->assertJsonPath('data.0.text', 'Milk');
        $this->withCsrfToken()->putJson("/crud/list_items/$itemId", ['list_id' => $listId, 'text' => 'Oat milk', 'position' => 1])->assertOk();
        $this->assertSame('Oat milk', DB::table('fixt_list_items')->where('id', $itemId)->value('text'));
        $this->withCsrfToken()->deleteJson("/crud/list_items/$itemId")->assertStatus(Response::HTTP_NO_CONTENT);
        $this->assertSame(0, DB::table('fixt_list_items')->count());
    }

    public function test_list_items_paginate_and_sort(): void
    {
        $listId = $this->listFor($this->owner, 'Groceries');
        foreach (['Apples', 'Bread', 'Cheese', 'Dates', 'Eggs'] as $position => $text) {
            DB::table('fixt_list_items')->insert(['list_id' => $listId, 'text' => $text, 'position' => $position]);
        }
        $this->actingAs($this->owner);

        $first = $this->getJson('/crud/list_items?limit=2&page=1&sort=text&order=desc');
        $first->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.text', 'Eggs')->assertJsonPath('data.1.text', 'Dates')
            ->assertJsonPath('pagination.total', 5)->assertJsonPath('pagination.pages', 3);
        $this->getJson('/crud/list_items?limit=2&page=3&sort=text&order=desc')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.text', 'Apples');
        $this->getJson('/crud/list_items?limit=0')->assertStatus(Response::HTTP_BAD_REQUEST);
        $this->getJson('/crud/list_items?sort=nope')->assertStatus(Response::HTTP_BAD_REQUEST);
    }

    public function test_a_non_member_never_sees_or_changes_a_list_or_its_items(): void
    {
        $listId = $this->listFor($this->owner, 'Groceries');
        $itemId = DB::table('fixt_list_items')->insertGetId(['list_id' => $listId, 'text' => 'Milk', 'position' => 0]);
        $this->actingAs($this->other);

        $this->getJson('/crud/lists')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/crud/list_items')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/crud/list_members')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/crud/lists/$listId")->assertForbidden();
        $this->getJson("/crud/list_items/$itemId")->assertForbidden();
        $this->withCsrfToken()->putJson("/crud/lists/$listId", ['name' => 'Mine now', 'created_by_membership_id' => $this->other->uuid])->assertForbidden();
        $this->withCsrfToken()->putJson("/crud/list_items/$itemId", ['list_id' => $listId, 'text' => 'Mine', 'position' => 0])->assertForbidden();
        $this->withCsrfToken()->deleteJson("/crud/lists/$listId")->assertForbidden();
        $this->withCsrfToken()->deleteJson("/crud/list_items/$itemId")->assertForbidden();
        $this->withCsrfToken()->postJson('/crud/list_items', ['list_id' => $listId, 'text' => 'Eggs', 'position' => 1])->assertForbidden();
        $this->withCsrfToken()->postJson('/crud/list_members', ['list_id' => $listId, 'membership_id' => $this->other->uuid])->assertForbidden();

        $this->assertSame('Groceries', DB::table('fixt_lists')->where('id', $listId)->value('name'));
        $this->assertSame(1, DB::table('fixt_list_items')->count());
        $this->assertSame(1, DB::table('fixt_list_members')->count());
    }

    public function test_a_member_shares_a_list_and_the_new_member_sees_it(): void
    {
        $listId = $this->listFor($this->owner, 'Groceries');
        $this->actingAs($this->owner);

        $this->withCsrfToken()->postJson('/crud/list_members', ['list_id' => $listId, 'membership_id' => $this->other->uuid])
            ->assertStatus(Response::HTTP_CREATED);

        $this->actingAs($this->other);
        $this->getJson("/crud/lists/$listId")->assertOk()->assertJsonPath('data.0.name', 'Groceries');
        $this->getJson('/crud/list_members')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_maps_validations_reject_unknown_columns_and_wrong_types(): void
    {
        $this->actingAs($this->owner);

        $this->withCsrfToken()->postJson('/crud/lists', ['name' => str_repeat('x', 101), 'created_by_membership_id' => $this->owner->uuid])
            ->assertStatus(Response::HTTP_BAD_REQUEST);
        $this->withCsrfToken()->postJson('/crud/lists', ['name' => ['not', 'a', 'string'], 'created_by_membership_id' => $this->owner->uuid])
            ->assertStatus(Response::HTTP_BAD_REQUEST);
        $this->assertSame(0, DB::table('fixt_lists')->count());

        $listId = $this->listFor($this->owner, 'Groceries');
        $this->withCsrfToken()->postJson('/crud/list_items', ['list_id' => $listId, 'text' => 'Milk', 'position' => 'first'])
            ->assertStatus(Response::HTTP_BAD_REQUEST);
        $this->assertSame(0, DB::table('fixt_list_items')->count());
    }

    public function test_unknown_columns_are_never_written(): void
    {
        $this->actingAs($this->owner);

        $this->withCsrfToken()->postJson('/crud/lists', ['name' => 'Groceries', 'created_by_membership_id' => $this->owner->uuid, 'colour' => 'red', 'deleted_at' => '2026-01-01'])
            ->assertStatus(Response::HTTP_CREATED);

        $row = DB::table('fixt_lists')->first();
        $this->assertNull($row->deleted_at);
        $this->assertObjectNotHasProperty('colour', $row);
    }

    public function test_an_administrator_manages_categories_and_products(): void
    {
        $this->actingAs($this->admin);

        $this->withCsrfToken()->postJson('/crud/categories', ['name' => 'Dairy', 'position' => 1])->assertStatus(Response::HTTP_CREATED);
        $categoryId = (int) DB::table('fixt_categories')->value('id');
        $this->withCsrfToken()->postJson('/crud/products', ['name' => 'Milk', 'brand' => 'Farm', 'category_id' => $categoryId])
            ->assertStatus(Response::HTTP_CREATED);
        $productId = (int) DB::table('fixt_products')->value('id');

        $this->getJson("/crud/categories/$categoryId")->assertOk()->assertJsonPath('data.0.name', 'Dairy');
        $this->getJson("/crud/products/$productId")->assertOk()->assertJsonPath('data.0.brand', 'Farm');
        $this->getJson('/crud/products?limit=10&sort=name')->assertOk()->assertJsonPath('pagination.total', 1);

        $this->withCsrfToken()->putJson("/crud/products/$productId", ['name' => 'Whole milk', 'brand' => 'Farm', 'category_id' => $categoryId])->assertOk();
        $this->assertSame('Whole milk', DB::table('fixt_products')->where('id', $productId)->value('name'));
        $this->withCsrfToken()->putJson("/crud/categories/$categoryId", ['name' => 'Dairy and eggs', 'position' => 1])->assertOk();

        $this->withCsrfToken()->postJson('/crud/products', ['name' => str_repeat('x', 201)])->assertStatus(Response::HTTP_BAD_REQUEST);

        $this->withCsrfToken()->deleteJson("/crud/products/$productId")->assertStatus(Response::HTTP_NO_CONTENT);
        $this->withCsrfToken()->deleteJson("/crud/categories/$categoryId")->assertStatus(Response::HTTP_NO_CONTENT);
        $this->assertSame(0, DB::table('fixt_products')->count() + DB::table('fixt_categories')->count());
    }

    public function test_an_administrator_keeps_access_to_every_list(): void
    {
        $listId = $this->listFor($this->owner, 'Groceries');
        $this->actingAs($this->admin);

        $this->getJson("/crud/lists/$listId")->assertOk();
        $this->getJson('/crud/lists')->assertOk()->assertJsonCount(1, 'data');
        $this->withCsrfToken()->putJson("/crud/lists/$listId", ['name' => 'Admin edit', 'created_by_membership_id' => $this->owner->uuid])->assertOk();
        $this->withCsrfToken()->deleteJson("/crud/lists/$listId")->assertStatus(Response::HTTP_NO_CONTENT);
    }

    private function listFor(User $user, string $name): int
    {
        $listId = DB::table('fixt_lists')->insertGetId(['name' => $name, 'created_by_membership_id' => $user->uuid]);
        DB::table('fixt_list_members')->insert(['list_id' => $listId, 'membership_id' => $user->uuid]);

        return $listId;
    }
}
