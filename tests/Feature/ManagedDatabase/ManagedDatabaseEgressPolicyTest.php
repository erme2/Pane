<?php

namespace Tests\Feature\ManagedDatabase;

use App\Models\AuditEvent;
use App\Services\ManagedDatabaseEgressPolicy;
use App\Support\ManagedDatabase\EgressDecision;
use App\Support\ManagedDatabase\HostResolver;
use InvalidArgumentException;
use Tests\TestCase;

class ManagedDatabaseEgressPolicyTest extends TestCase
{
    /** @var array<string, list<list<string>>> answers per hostname, one list per lookup */
    private array $dns = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.managed_databases.egress_allow', null);
        $dns = &$this->dns;
        $this->app->instance(HostResolver::class, new class($dns) implements HostResolver
        {
            /** @param  array<string, list<list<string>>>  $dns */
            public function __construct(private array &$dns) {}

            public function resolve(string $hostname): array
            {
                $answers = $this->dns[$hostname] ?? [[]];

                // Repeat the last answer once the list runs out.
                return count($answers) > 1 ? array_shift($this->dns[$hostname]) : $answers[0];
            }
        });
    }

    private function policy(): ManagedDatabaseEgressPolicy
    {
        return app(ManagedDatabaseEgressPolicy::class);
    }

    public function test_public_addresses_are_allowed_and_pinned(): void
    {
        $this->dns['db.example.com'] = [['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']];

        $decision = $this->policy()->evaluate('DB.Example.com.', 3306);

        $this->assertTrue($decision->allowed);
        $this->assertSame('db.example.com', $decision->host);
        $this->assertSame(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'], $decision->addresses);
        $this->assertTrue($this->policy()->evaluate('8.8.8.8', 3306)->allowed);
        $this->assertTrue($this->policy()->evaluate('[2001:4860:4860::8888]', 3306)->allowed);
    }

    public function test_private_and_special_ranges_are_denied_by_default(): void
    {
        foreach ([
            '127.0.0.1', '127.8.8.8', '10.1.2.3', '172.16.0.1', '172.31.255.255', '192.168.1.10',
            '100.64.0.1', '169.254.10.10', '0.0.0.0', '192.0.2.5', '198.18.0.1', '224.0.0.1', '239.1.1.1',
            '240.0.0.1', '255.255.255.255',
            '::', '::1', 'fe80::1', 'fc00::1', 'fd12:3456::1', 'ff02::1', '2001:db8::1',
            '::ffff:127.0.0.1', '::ffff:10.0.0.1', '64:ff9b::a00:1', '::ffff:169.254.169.254',
        ] as $address) {
            $decision = $this->policy()->evaluate($address, 3306);
            $this->assertFalse($decision->allowed, $address);
        }
    }

    public function test_cloud_metadata_is_denied_even_when_allowed(): void
    {
        config()->set('services.managed_databases.egress_allow', '169.254.0.0/16,metadata.google.internal,fd00::/8');
        $this->dns['sneaky.example.com'] = [['169.254.169.254']];

        foreach (['169.254.169.254', 'fd00:ec2::254', '100.100.100.200', 'metadata.google.internal', 'sneaky.example.com'] as $host) {
            $decision = $this->policy()->evaluate($host, 3306);
            $this->assertFalse($decision->allowed, $host);
            $this->assertSame(EgressDecision::METADATA_ADDRESS, $decision->reason, $host);
        }
        // The rest of the allowed range still works.
        $this->assertTrue($this->policy()->evaluate('169.254.1.1', 3306)->allowed);
    }

    public function test_every_dns_answer_must_pass(): void
    {
        $this->dns['mixed.example.com'] = [['93.184.216.34', '10.0.0.5']];
        $this->dns['alias.example.com'] = [['::ffff:192.168.0.9']];

        $mixed = $this->policy()->evaluate('mixed.example.com', 3306);
        $this->assertFalse($mixed->allowed);
        $this->assertSame('10.0.0.5', $mixed->blockedAddress);
        $this->assertFalse($this->policy()->evaluate('alias.example.com', 3306)->allowed);
    }

    public function test_rebinding_is_caught_on_the_next_attempt_and_the_first_answer_stays_pinned(): void
    {
        $this->dns['rebind.example.com'] = [['93.184.216.34'], ['127.0.0.1']];

        $first = $this->policy()->evaluate('rebind.example.com', 3306);
        $second = $this->policy()->evaluate('rebind.example.com', 3306);

        $this->assertTrue($first->allowed);
        $this->assertSame(['93.184.216.34'], $first->addresses);
        $this->assertFalse($second->allowed);
        $this->assertSame(EgressDecision::BLOCKED_ADDRESS, $second->reason);
    }

    public function test_unresolvable_and_invalid_destinations_are_denied(): void
    {
        $this->assertSame(EgressDecision::UNRESOLVABLE, $this->policy()->evaluate('nowhere.example.com', 3306)->reason);

        foreach (['', 'mysql://db.example.com', 'user:pass@db.example.com', 'db.example.com:3306', 'db.example.com/x', 'bad host'] as $host) {
            $this->assertSame(EgressDecision::INVALID_DESTINATION, $this->policy()->evaluate($host, 3306)->reason, $host);
        }
        foreach ([0, -1, 65536] as $port) {
            $this->assertSame(EgressDecision::INVALID_DESTINATION, $this->policy()->evaluate('8.8.8.8', $port)->reason);
        }
    }

    public function test_allow_rules_open_approved_private_hosts_and_ranges(): void
    {
        config()->set('services.managed_databases.egress_allow', 'localhost, 10.20.0.0/16 ,fd00:1234::/32,192.168.5.7');
        $this->dns['localhost'] = [['127.0.0.1', '::1']];
        $this->dns['other.internal'] = [['10.30.0.1']];

        $this->assertTrue($this->policy()->evaluate('localhost', 3306)->allowed);
        $this->assertTrue($this->policy()->evaluate('10.20.4.5', 3306)->allowed);
        $this->assertTrue($this->policy()->evaluate('fd00:1234::9', 3306)->allowed);
        $this->assertTrue($this->policy()->evaluate('192.168.5.7', 3306)->allowed);
        $this->assertFalse($this->policy()->evaluate('192.168.5.8', 3306)->allowed);
        $this->assertFalse($this->policy()->evaluate('other.internal', 3306)->allowed);
        // A hostname rule doesn't open the IP for other names, and multicast stays closed.
        $this->assertFalse($this->policy()->evaluate('127.0.0.1', 3306)->allowed);
        config()->set('services.managed_databases.egress_allow', '224.0.0.0/4');
        $this->assertFalse($this->policy()->evaluate('224.0.0.1', 3306)->allowed);
    }

    public function test_invalid_allow_rules_fail_closed(): void
    {
        config()->set('services.managed_databases.egress_allow', '10.0.0.0/99');

        $this->expectException(InvalidArgumentException::class);
        $this->policy()->evaluate('8.8.8.8', 3306);
    }

    public function test_decisions_are_audited_without_credentials(): void
    {
        $this->dns['db.example.com'] = [['93.184.216.34']];

        $requestId = 'egress-test-'.uniqid();
        $this->policy()->authorize('db.example.com', 3306, ['purpose' => 'connection_test', 'request_id' => $requestId]);
        $this->policy()->authorize('10.0.0.1', 3306, ['request_id' => $requestId]);

        $events = AuditEvent::query()->where('request_id', $requestId)->get();
        $this->assertSame(
            ['managed_database.egress.allowed', 'managed_database.egress.denied'],
            $events->pluck('action')->sort()->values()->all()
        );
        $denied = $events->firstWhere('action', 'managed_database.egress.denied');
        $this->assertSame(AuditEvent::OUTCOME_DENIED, $denied->outcome);
        $this->assertSame('10.0.0.1', $denied->metadata['blocked_address']);
        $this->assertSame(EgressDecision::BLOCKED_ADDRESS, $denied->metadata['reason']);
        $allowed = $events->firstWhere('action', 'managed_database.egress.allowed');
        $this->assertSame('connection_test', $allowed->metadata['purpose']);
    }

    public function test_messages_are_safe_to_show(): void
    {
        $decision = $this->policy()->evaluate('10.0.0.1', 3306);

        $this->assertStringContainsString('10.0.0.1', $decision->message());
        $this->assertStringContainsString('PANE_MANAGED_DB_EGRESS_ALLOW', $decision->message());
    }

    public function test_timeouts_are_bounded_installation_settings(): void
    {
        $this->assertSame(['connect' => 5, 'query' => 15], $this->policy()->timeouts());

        config()->set('services.managed_databases.connect_timeout', '600');
        config()->set('services.managed_databases.query_timeout', '0');
        $this->assertSame(['connect' => 30, 'query' => 1], $this->policy()->timeouts());
    }
}
