<?php

namespace Tests\Feature\ManagedDatabase;

use App\Models\ApplicationRegistration;
use App\Models\AuditEvent;
use App\Models\CatalogObject;
use App\Models\CatalogRefresh;
use App\Models\ManagedConnection;
use App\Models\ManagedCredentialSecret;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Services\ApplicationRegistryService;
use App\Services\CatalogService;
use App\Services\OrganizationTenancyService;
use App\Support\ManagedDatabase\CatalogReader;
use App\Support\ManagedDatabase\HostResolver;
use App\Support\ManagedDatabase\PdoFactory;
use DomainException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeManagedDatabase;
use Tests\TestCase;

class CatalogApiTest extends TestCase
{
    private FakeManagedDatabase $db;

    private ApplicationRegistration $application;

    private Organization $organization;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        CatalogObject::query()->delete();
        CatalogRefresh::query()->delete();
        ManagedConnection::query()->delete();
        ManagedCredentialSecret::query()->delete();
        OrganizationInvitation::query()->delete();
        ApplicationRegistration::query()->delete();
        OrganizationMembership::query()->delete();
        Organization::query()->delete();
        User::query()->delete();

        config()->set('services.managed_databases.egress_allow', null);
        config()->set('services.managed_credentials.active_key_id', 'key-v1');
        config()->set('services.managed_credentials.keys', json_encode([
            'key-v1' => 'base64:'.base64_encode(str_repeat('k', SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)),
        ], JSON_THROW_ON_ERROR));
        config()->set('services.latte.application_id', (string) Str::uuid());
        config()->set('services.latte.organization_id', (string) Str::uuid());
        config()->set('services.latte.frontend_url', 'https://latte.test');
        config()->set('services.latte.redirect_uris', ['https://latte.test/dashboard']);

        $this->db = new FakeManagedDatabase($this);
        $this->app->instance(HostResolver::class, $this->db->resolver());
        $this->app->instance(PdoFactory::class, $this->db->pdoFactory());

        $this->application = app(ApplicationRegistryService::class)->configuredLatteApplication();
        $this->organization = $this->application->organization()->firstOrFail();
        $this->organization->forceFill(['database_limit' => 3, 'active_database_connections' => 0])->save();
        $this->admin = $this->member(OrganizationMembership::ROLE_ADMINISTRATOR);

        $this->db->addContractTable('orders', ['customer_id' => 'bigint unsigned', 'total' => 'decimal(10,2)', 'note' => 'text']);
        $this->db->addContractTable('customers', ['email' => 'varchar(320)']);
        $this->db->addForeignKey('orders', 'orders_customer_fk', ['customer_id'], 'customers', ['id']);
    }

    private function member(string $role): User
    {
        $user = $this->makePaneUser(User::STANDARD_USER_TYPE_ID);
        app(OrganizationTenancyService::class)->addOrReactivateMembership($this->organization, $user, $role);

        return $user;
    }

    private function api(string $method, string $path, array $body = [], ?string $ifMatch = null, ?User $as = null, ?Organization $organization = null): TestResponse
    {
        $this->flushHeaders();
        $this->withCsrfToken()->actingAs($as ?? $this->admin);
        $request = $this->withV1ApplicationSession($this->application)->withHeader('Origin', 'https://latte.test');
        if ($ifMatch !== null) {
            $request = $request->withHeader('If-Match', $ifMatch);
        }
        $organizationId = ($organization ?? $this->organization)->organization_id;

        return $request->json($method, "/api/v1/organizations/$organizationId/connections$path", $body);
    }

    private function connection(string $name = 'Shop'): string
    {
        return (string) $this->api('POST', '', [
            'engine' => 'mysql', 'name' => $name, 'host' => 'db.example.com', 'port' => 3306,
            'database' => 'shop', 'username' => 'app', 'tls_mode' => 'required',
            'credentials' => ['password' => 'very-secret-password'],
        ])->assertCreated()->json('data.id');
    }

    private function refresh(string $connectionId): TestResponse
    {
        RateLimiter::clear('catalog-refresh:'.$connectionId);

        return $this->api('POST', "/$connectionId/catalog-refreshes");
    }

    private function object(string $connectionId, string $kind, string $name): CatalogObject
    {
        return CatalogObject::query()->where('connection_id', $connectionId)->where('kind', $kind)->where('name', $name)->firstOrFail();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allObjects(string $connectionId): array
    {
        $objects = [];
        $cursor = null;
        do {
            $query = '?page[limit]=4'.($cursor !== null ? '&page[cursor]='.$cursor : '');
            $page = $this->api('GET', "/$connectionId/catalog$query")->assertOk();
            $objects = [...$objects, ...$page->json('data')];
            $cursor = $page->json('meta.page.next_cursor');
        } while ($page->json('meta.page.has_more'));

        return $objects;
    }

    public function test_refresh_discovers_the_configured_database_into_the_catalog(): void
    {
        $id = $this->connection();

        $this->refresh($id)->assertAccepted()
            ->assertJsonPath('data.type', 'catalog_refresh')
            ->assertJsonPath('data.attributes.connection_id', $id)
            ->assertJsonPath('data.attributes.status', 'completed');

        // Every information_schema query is bound to the configured database only.
        $this->assertCount(5, $this->db->catalogQueries);
        foreach ($this->db->catalogQueries as $query) {
            $this->assertSame(['shop'], $query['params']);
            $this->assertStringContainsString('information_schema.', $query['sql']);
        }

        $objects = $this->allObjects($id);
        $names = array_map(fn (array $object): string => $object['attributes']['kind'].':'.$object['attributes']['name'], $objects);
        $this->assertSame('schema:shop', $names[0]);
        $this->assertContains('table:orders', $names);
        $this->assertContains('column:orders.total', $names);
        $this->assertContains('relationship:orders.orders_customer_fk', $names);
        $this->assertCount(count(array_unique($names)), $names);
        foreach ($objects as $object) {
            $this->assertSame(['connection_id', 'kind', 'name', 'status', 'description', 'created_at', 'updated_at'], array_keys($object['attributes']));
            $this->assertSame('available', $object['attributes']['status']);
        }

        $orders = $this->object($id, 'table', 'orders');
        $this->assertSame(['id'], $orders->structure['primary_key']);
        $this->assertFalse($orders->crud_enabled);
        $this->assertFalse($orders->isCrudCandidate());
        $total = $this->object($id, 'column', 'orders.total');
        $this->assertSame($orders->getKey(), $total->parent_id);
        $this->assertSame('decimal', $total->structure['type']);
        $this->assertSame('decimal(10,2)', $total->structure['database_type']);
        $this->assertTrue($this->object($id, 'column', 'orders.id')->structure['primary_key']);
        $relationship = $this->object($id, 'relationship', 'orders.orders_customer_fk');
        $this->assertSame(['table' => 'orders', 'columns' => ['customer_id'], 'referenced_table' => 'customers', 'referenced_columns' => ['id']], $relationship->structure);

        $refresh = CatalogRefresh::query()->findOrFail($this->refresh($id)->json('data.id'));
        $this->assertSame(['added' => 0, 'changed' => 0, 'missing' => 0, 'unchanged' => count($objects)], $refresh->counts);
        $audit = AuditEvent::query()->where('action', 'catalog.refreshed')->latest('occurred_at')->firstOrFail();
        $this->assertSame(AuditEvent::OUTCOME_SUCCESS, $audit->outcome);
        $this->assertSame($id, $audit->connection_id);
        $this->assertStringNotContainsString('very-secret-password', AuditEvent::query()->get()->toJson());
    }

    public function test_tables_outside_the_crud_contract_are_incompatible_with_reasons(): void
    {
        $this->db->addTable('legacy', ['code' => 'varchar(10)', 'name' => 'varchar(50)']);
        $this->db->addTable('pairs', ['a' => 'int', 'b' => 'int', 'pane_membership_id' => 'char(36)', 'created_at' => 'datetime', 'updated_at' => 'datetime', 'deleted_at' => 'datetime'], primaryKey: ['a', 'b']);
        $this->db->addTable('loose', ['id' => 'int', 'pane_membership_id' => 'varchar(64)', 'created_at' => 'date', 'updated_at' => 'datetime', 'deleted_at' => 'datetime'], nullable: ['pane_membership_id']);
        $this->db->addTable('order_totals', ['id' => 'int'], primaryKey: [], type: 'VIEW');
        $this->db->addContractTable('my table', ['shape' => 'geometry', 'odd-name' => 'int']);
        $this->db->addForeignKey('orders', 'orders_remote_fk', ['customer_id'], 'customers', ['id'], 'other_db');
        $id = $this->connection();
        $this->refresh($id)->assertAccepted();

        $issues = fn (string $kind, string $name): array => $this->object($id, $kind, $name)->issues;
        $this->assertSame(['membership_column_missing', 'created_at_missing', 'updated_at_missing', 'deleted_at_missing'], $issues('table', 'legacy'));
        $this->assertSame(['primary_key_not_single_column', 'membership_column_not_indexed', 'deleted_at_not_nullable'], $issues('table', 'pairs'));
        $this->assertSame(['membership_column_nullable', 'membership_column_not_uuid', 'membership_column_not_indexed', 'created_at_not_a_timestamp', 'deleted_at_not_nullable'], $issues('table', 'loose'));
        $this->assertSame(['not_a_base_table', 'primary_key_missing', 'membership_column_missing', 'created_at_missing', 'updated_at_missing', 'deleted_at_missing'], $issues('table', 'order_totals'));
        $this->assertSame(['unsupported_identifier'], $issues('table', 'my table'));
        $this->assertSame(['unsupported_type'], $issues('column', 'my table.shape'));
        $this->assertSame(['unsupported_identifier'], $issues('column', 'my table.odd-name'));
        $this->assertSame(['references_another_database'], $issues('relationship', 'orders.orders_remote_fk'));
        $this->assertNull($this->object($id, 'relationship', 'orders.orders_remote_fk')->structure['referenced_table']);

        $this->assertSame('incompatible', $this->object($id, 'table', 'legacy')->status);
        $this->assertSame('available', $this->object($id, 'column', 'legacy.code')->status);
        $this->assertSame('available', $this->object($id, 'table', 'orders')->status);
    }

    public function test_refresh_keeps_descriptions_and_marks_missing_objects(): void
    {
        $id = $this->connection();
        $this->refresh($id)->assertAccepted();
        $orders = $this->object($id, 'table', 'orders');
        $note = $this->object($id, 'column', 'orders.note');
        $customers = $this->object($id, 'table', 'customers');
        app(CatalogService::class)->updateDescription($orders, $this->admin, 'Customer orders', $orders->versionTag());
        app(CatalogService::class)->updateDescription($note, $this->admin, 'Free text', $note->fresh()->versionTag());
        app(CatalogService::class)->updateDescription($customers, $this->admin, 'People who buy', $customers->fresh()->versionTag());
        $unchangedRevision = $this->object($id, 'column', 'orders.total')->revision;

        // Structure changes: a column type changes, a column is renamed, and a table is dropped.
        $this->db->catalog['columns'] = array_map(function (array $column): array {
            if ($column['table_name'] === 'orders' && $column['column_name'] === 'customer_id') {
                $column['column_type'] = 'int';
                $column['data_type'] = 'int';
            }
            if ($column['table_name'] === 'orders' && $column['column_name'] === 'note') {
                $column['column_name'] = 'notes';
            }

            return $column;
        }, $this->db->catalog['columns']);
        $this->db->dropTable('customers');
        $this->db->catalog['foreign_keys'] = [];

        $refresh = CatalogRefresh::query()->findOrFail($this->refresh($id)->assertAccepted()->json('data.id'));
        $this->assertSame(1, $refresh->counts['added']);
        $this->assertGreaterThanOrEqual(2, $refresh->counts['changed']);

        $orders = $this->object($id, 'table', 'orders');
        $this->assertSame('Customer orders', $orders->description);
        $this->assertSame('available', $orders->status);
        $this->assertSame('int', $this->object($id, 'column', 'orders.customer_id')->structure['database_type']);
        $this->assertSame($unchangedRevision, $this->object($id, 'column', 'orders.total')->revision);

        // A rename is a missing old column plus a new one.
        $old = $this->object($id, 'column', 'orders.note');
        $this->assertSame('missing', $old->status);
        $this->assertNotNull($old->missing_since);
        $this->assertSame('Free text', $old->description);
        $this->assertSame('available', $this->object($id, 'column', 'orders.notes')->status);
        $this->assertNull($this->object($id, 'column', 'orders.notes')->description);

        // The dropped table and everything under it stay, as missing.
        $this->assertSame('missing', $this->object($id, 'table', 'customers')->status);
        $this->assertSame('missing', $this->object($id, 'column', 'customers.email')->status);
        $this->assertSame('missing', $this->object($id, 'relationship', 'orders.orders_customer_fk')->status);
        $this->api('GET', '/'.$id.'/catalog/'.$customers->getKey())->assertOk()
            ->assertJsonPath('data.attributes.status', 'missing')
            ->assertJsonPath('data.attributes.description', 'People who buy');

        // When it comes back, it's the same object with its description.
        $this->db->addContractTable('customers', ['email' => 'varchar(320)']);
        $this->refresh($id)->assertAccepted();
        $back = $this->object($id, 'table', 'customers');
        $this->assertSame($customers->getKey(), $back->getKey());
        $this->assertSame('available', $back->status);
        $this->assertNull($back->missing_since);
        $this->assertSame('People who buy', $back->description);
    }

    public function test_descriptions_need_a_current_if_match_and_are_audited(): void
    {
        $id = $this->connection();
        $this->refresh($id)->assertAccepted();
        $orders = $this->object($id, 'table', 'orders');
        $path = "/$id/catalog/{$orders->getKey()}";

        $etag = (string) $this->api('GET', $path)->assertOk()->assertJsonPath('data.attributes.description', null)->headers->get('ETag');
        $this->assertSame('"r1"', $etag);

        $this->api('PATCH', $path, ['description' => 'x'])->assertStatus(428)->assertJsonPath('error.code', 'precondition_required');
        $this->api('PATCH', $path, ['description' => 'x'], 'r1')->assertBadRequest()->assertJsonPath('error.code', 'invalid_request');
        $this->api('PATCH', $path, ['description' => 'x'], 'W/"r1"')->assertBadRequest();
        $this->api('PATCH', $path, ['description' => 'x'], '*')->assertBadRequest();
        $this->api('PATCH', $path, ['description' => 'x'], '"r9"')->assertStatus(412)->assertJsonPath('error.code', 'version_conflict');
        $this->api('PATCH', $path, ['description' => 'x', 'name' => 'y'], $etag)->assertUnprocessable()->assertJsonPath('error.code', 'validation_failed');
        $this->api('PATCH', $path, [], $etag)->assertUnprocessable();
        $this->api('PATCH', $path, ['description' => 5], $etag)->assertUnprocessable();
        $this->api('PATCH', $path, ['description' => str_repeat('a', 5001)], $etag)->assertUnprocessable();

        $updated = $this->api('PATCH', $path, ['description' => 'Customer orders'], $etag)->assertOk()
            ->assertJsonPath('data.attributes.description', 'Customer orders')
            ->assertHeader('ETag', '"r2"');

        // A second editor who read the old version is refused, and nothing changes.
        $this->api('PATCH', $path, ['description' => 'Overwritten'], $etag)->assertStatus(412);
        $this->assertSame('Customer orders', $orders->fresh()->description);

        $this->api('PATCH', $path, ['description' => null], (string) $updated->headers->get('ETag'))->assertOk()
            ->assertJsonPath('data.attributes.description', null);

        $events = AuditEvent::query()->where('connection_id', $id)->where('action', 'catalog.description_updated')->get();
        $this->assertCount(2, $events);
        $this->assertEqualsCanonicalizing([15, 0], $events->map(fn (AuditEvent $event): int => $event->metadata['description_length'])->all());
        foreach ($events as $event) {
            $this->assertSame($orders->getKey(), $event->metadata['catalog_object_id']);
            $this->assertSame($this->admin->getKey(), $event->real_actor_user_id);
        }
    }

    public function test_catalogs_are_scoped_to_their_connection_organization_and_administrators(): void
    {
        $first = $this->connection('First');
        $second = $this->connection('Second');
        $this->refresh($first)->assertAccepted();
        $this->refresh($second)->assertAccepted();
        $firstOrders = $this->object($first, 'table', 'orders');
        $secondOrders = $this->object($second, 'table', 'orders');
        $this->assertNotSame($firstOrders->getKey(), $secondOrders->getKey());

        // An object is only reachable through its own connection.
        $this->api('GET', "/$first/catalog/{$secondOrders->getKey()}")->assertNotFound()->assertJsonPath('error.code', 'resource_not_found');
        $this->api('PATCH', "/$first/catalog/{$secondOrders->getKey()}", ['description' => 'x'], '"r1"')->assertNotFound();
        $this->assertNull($secondOrders->fresh()->description);
        $listed = array_column($this->allObjects($first), 'id');
        $this->assertContains($firstOrders->getKey(), $listed);
        $this->assertNotContains($secondOrders->getKey(), $listed);

        // Edits to one connection's catalog leave the other alone.
        $this->api('PATCH', "/$first/catalog/{$firstOrders->getKey()}", ['description' => 'First orders'], '"r1"')->assertOk();
        $this->assertNull($secondOrders->fresh()->description);

        $this->api('GET', '/'.Str::uuid().'/catalog')->assertNotFound();
        $this->api('GET', '/not-a-uuid/catalog')->assertBadRequest()->assertJsonPath('error.code', 'invalid_identifier');
        $this->api('GET', "/$first/catalog/not-a-uuid")->assertBadRequest()->assertJsonPath('error.code', 'invalid_identifier');
        $this->api('GET', "/$first/catalog/".Str::uuid())->assertNotFound();

        // Standard members and other organizations get nothing.
        $user = $this->member(OrganizationMembership::ROLE_USER);
        $this->api('GET', "/$first/catalog", as: $user)->assertForbidden()->assertJsonPath('error.code', 'permission_denied');
        $this->api('POST', "/$first/catalog-refreshes", as: $user)->assertForbidden();
        $this->api('GET', "/$first/catalog/{$firstOrders->getKey()}", as: $user)->assertForbidden();
        $other = app(OrganizationTenancyService::class)->createOrganization('Other', 'other-'.Str::uuid());
        $this->api('GET', "/$first/catalog", organization: $other)->assertForbidden();
    }

    public function test_catalog_pages_are_stable_and_reject_bad_cursors(): void
    {
        $id = $this->connection();
        $this->refresh($id)->assertAccepted();
        $total = CatalogObject::query()->where('connection_id', $id)->count();

        $ids = array_column($this->allObjects($id), 'id');
        $this->assertCount($total, $ids);
        $this->assertCount($total, array_unique($ids));
        $kinds = array_map(fn (string $objectId): int => array_search(CatalogObject::query()->findOrFail($objectId)->kind, CatalogObject::KINDS, true), $ids);
        $sorted = $kinds;
        sort($sorted);
        $this->assertSame($sorted, $kinds);

        $this->api('GET', "/$id/catalog?page[cursor]=not!valid")->assertBadRequest()->assertJsonPath('error.code', 'invalid_cursor');
        $foreign = rtrim(strtr(base64_encode(json_encode(['v' => 1, 'id' => (string) Str::uuid()])), '+/', '-_'), '=');
        $this->api('GET', "/$id/catalog?page[cursor]=$foreign")->assertBadRequest()->assertJsonPath('error.code', 'invalid_cursor');
        $this->api('GET', "/$id/catalog?page[limit]=101")->assertBadRequest()->assertJsonPath('error.code', 'invalid_request');
    }

    public function test_failed_refreshes_are_recorded_and_return_dependency_unavailable(): void
    {
        $id = $this->connection();

        $this->db->refuse = ['93.184.216.34', '2606:2800:220:1::1'];
        $this->refresh($id)->assertStatus(503)->assertJsonPath('error.code', 'dependency_unavailable');
        $this->db->refuse = [];

        $this->db->grants[] = 'GRANT CREATE ON `shop`.* TO `app`@`%`';
        $this->refresh($id)->assertStatus(503)->assertJsonPath('error.message', fn (string $message): bool => str_contains($message, 'CREATE'));
        array_pop($this->db->grants);

        $this->db->catalog['schema'] = [];
        $this->refresh($id)->assertStatus(503)->assertJsonPath('error.message', 'The configured database was not found on the server.');
        $this->db->catalog['schema'] = [['schema_name' => 'shop', 'charset' => 'utf8mb4', 'collation_name' => 'utf8mb4_bin']];

        $connection = ManagedConnection::query()->findOrFail($id);
        $etag = (string) $this->api('GET', "/$id")->headers->get('ETag');
        $this->api('DELETE', "/$id", ifMatch: $etag)->assertNoContent();
        $this->refresh($id)->assertStatus(503)->assertJsonPath('error.message', fn (string $message): bool => str_contains($message, 'disabled'));

        $this->assertSame(0, CatalogObject::query()->where('connection_id', $connection->getKey())->count());
        $this->assertSame(4, CatalogRefresh::query()->where('connection_id', $id)->where('status', 'failed')->count());
        $this->assertSame(4, AuditEvent::query()->where('connection_id', $id)->where('action', 'catalog.refreshed')->where('outcome', AuditEvent::OUTCOME_FAILURE)->count());
        $this->assertStringNotContainsString('very-secret-password', CatalogRefresh::query()->get()->toJson());
    }

    public function test_refreshes_are_rate_limited_per_connection(): void
    {
        $id = $this->connection();
        RateLimiter::clear('catalog-refresh:'.$id);

        for ($i = 0; $i < 3; $i++) {
            $this->api('POST', "/$id/catalog-refreshes")->assertAccepted();
        }
        $this->api('POST', "/$id/catalog-refreshes")->assertStatus(429)->assertJsonPath('error.code', 'rate_limited');
    }

    public function test_only_explicitly_enabled_compatible_tables_are_crud_candidates(): void
    {
        $this->db->addTable('legacy', ['code' => 'varchar(10)']);
        $id = $this->connection();
        $this->refresh($id)->assertAccepted();
        $catalog = app(CatalogService::class);

        $orders = $this->object($id, 'table', 'orders');
        $this->assertFalse($orders->isCrudCandidate());
        $catalog->setCrudEnabled($orders, $this->admin, true);
        $this->assertTrue($orders->fresh()->isCrudCandidate());
        $this->assertSame(1, AuditEvent::query()->where('connection_id', $id)->where('action', 'catalog.crud_enabled')->count());

        foreach ([$this->object($id, 'table', 'legacy'), $this->object($id, 'column', 'orders.id')] as $notACandidate) {
            try {
                $catalog->setCrudEnabled($notACandidate, $this->admin, true);
                $this->fail('A non-candidate was enabled for CRUD.');
            } catch (DomainException) {
                $this->assertFalse($notACandidate->fresh()->crud_enabled);
            }
        }

        // A table that stops meeting the contract loses CRUD, and getting it back is explicit.
        $this->db->catalog['indexes'] = array_values(array_filter($this->db->catalog['indexes'], fn (array $row): bool => ! ($row['table_name'] === 'orders' && $row['column_name'] === 'pane_membership_id')));
        $this->refresh($id)->assertAccepted();
        $orders = $this->object($id, 'table', 'orders');
        $this->assertSame('incompatible', $orders->status);
        $this->assertFalse($orders->crud_enabled);

        $this->db->catalog['indexes'][] = ['table_name' => 'orders', 'index_name' => 'orders_m', 'non_unique' => 1, 'seq' => 1, 'column_name' => 'pane_membership_id'];
        $this->refresh($id)->assertAccepted();
        $this->assertSame('available', $this->object($id, 'table', 'orders')->status);
        $this->assertFalse($this->object($id, 'table', 'orders')->isCrudCandidate());

        // A missing table loses CRUD too.
        $catalog->setCrudEnabled($this->object($id, 'table', 'orders'), $this->admin, true);
        $this->db->dropTable('orders');
        $this->refresh($id)->assertAccepted();
        $this->assertFalse($this->object($id, 'table', 'orders')->crud_enabled);
    }

    public function test_reader_queries_only_information_schema_for_the_database(): void
    {
        foreach ([CatalogReader::SCHEMA_SQL, CatalogReader::TABLES_SQL, CatalogReader::COLUMNS_SQL, CatalogReader::INDEXES_SQL, CatalogReader::FOREIGN_KEYS_SQL] as $sql) {
            $this->assertMatchesRegularExpression('/\ASELECT .+ FROM information_schema\.[A-Z_]+ WHERE (TABLE_SCHEMA|SCHEMA_NAME) = \?/', $sql);
            $this->assertSame(1, substr_count($sql, '?'));
        }
    }
}
