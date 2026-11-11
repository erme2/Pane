<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\User;
use App\Support\ManagedDatabase\EgressDecision;
use App\Support\ManagedDatabase\HostResolver;
use App\Support\ManagedDatabase\IpRange;
use InvalidArgumentException;

/**
 * The one network policy for every managed-database connection attempt:
 * connection tests and runtime queries must both call authorize() right
 * before connecting, then connect to a pinned address from the decision.
 *
 * Denied by default: loopback, private, shared (CGNAT), link-local,
 * documentation, benchmarking, multicast and reserved ranges, for IPv4 and
 * IPv6 (including IPv4-mapped and NAT64 forms). Installation-wide allow rules
 * (`PANE_MANAGED_DB_EGRESS_ALLOW`) can open specific private hosts or ranges.
 * Cloud metadata addresses, multicast and unspecified addresses can never be
 * allowed. Organizations can't change any of this, or the timeouts.
 */
class ManagedDatabaseEgressPolicy
{
    /** @var list<string> */
    private const array BLOCKED_RANGES = [
        // IPv4
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16',
        '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        // IPv6
        '::/128', '::1/128', '100::/64', '2001::/23', '2001:db8::/32', '2002::/16',
        'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /** Never allowed, whatever the allow rules say. @var list<string> */
    private const array NEVER_ALLOWED_RANGES = ['0.0.0.0/8', '::/128', '224.0.0.0/4', '240.0.0.0/4', 'ff00::/8'];

    /** Cloud instance metadata services (AWS, GCP, Azure, Oracle, Alibaba, DigitalOcean). @var list<string> */
    private const array METADATA_RANGES = ['169.254.169.254/32', '169.254.170.2/32', '100.100.100.200/32', 'fd00:ec2::254/128'];

    /** @var list<string> */
    private const array METADATA_HOSTS = ['metadata', 'metadata.google.internal', 'metadata.goog', 'instance-data', 'instance-data.ec2.internal'];

    private const int DEFAULT_CONNECT_TIMEOUT = 5;

    private const int MAX_CONNECT_TIMEOUT = 30;

    private const int DEFAULT_QUERY_TIMEOUT = 15;

    private const int MAX_QUERY_TIMEOUT = 120;

    public function __construct(
        private readonly HostResolver $resolver,
        private readonly AuditEventService $audit,
    ) {}

    /**
     * Checks a destination and records the decision in the audit log.
     *
     * @param  array{organization?: Organization|null, actor?: User|null, connection_id?: string|null, request_id?: string|null, purpose?: string}  $context
     */
    public function authorize(string $host, int $port, array $context = []): EgressDecision
    {
        $decision = $this->evaluate($host, $port);

        $this->audit->record(
            $decision->allowed ? 'managed_database.egress.allowed' : 'managed_database.egress.denied',
            $decision->allowed ? AuditEvent::OUTCOME_SUCCESS : AuditEvent::OUTCOME_DENIED,
            [
                'real_actor' => $context['actor'] ?? null,
                'organization' => $context['organization'] ?? null,
                'connection_id' => $context['connection_id'] ?? null,
                'request_id' => $context['request_id'] ?? null,
                'metadata' => [...$decision->auditMetadata(), 'purpose' => $context['purpose'] ?? 'connect'],
            ],
        );

        return $decision;
    }

    /** Checks a destination without recording it. */
    public function evaluate(string $host, int $port): EgressDecision
    {
        $host = $this->normalizeHost($host);

        if ($host === null || $port < 1 || $port > 65535) {
            return EgressDecision::deny($host ?? '', max(0, $port), EgressDecision::INVALID_DESTINATION);
        }

        if (in_array($host, self::METADATA_HOSTS, true)) {
            return EgressDecision::deny($host, $port, EgressDecision::METADATA_ADDRESS);
        }

        $addresses = IpRange::pack($host) !== null ? [$host] : $this->resolver->resolve($host);
        $addresses = array_values(array_filter($addresses, fn (string $address): bool => IpRange::pack($address) !== null));

        if ($addresses === []) {
            return EgressDecision::deny($host, $port, EgressDecision::UNRESOLVABLE);
        }

        $hostAllowed = in_array($host, $this->allowedHosts(), true);

        // Every answer must pass: one blocked address blocks the host, so a
        // multi-answer record can't smuggle a private address in.
        foreach ($addresses as $address) {
            foreach ($this->effectiveAddresses($address) as $candidate) {
                if ($this->inAny($candidate, self::METADATA_RANGES)) {
                    return EgressDecision::deny($host, $port, EgressDecision::METADATA_ADDRESS, $addresses, $address);
                }

                if ($this->inAny($candidate, self::NEVER_ALLOWED_RANGES)) {
                    return EgressDecision::deny($host, $port, EgressDecision::BLOCKED_ADDRESS, $addresses, $address);
                }

                if ($this->inAny($candidate, self::BLOCKED_RANGES) && ! $hostAllowed && ! $this->inAllowedRange($candidate)) {
                    return EgressDecision::deny($host, $port, EgressDecision::BLOCKED_ADDRESS, $addresses, $address);
                }
            }
        }

        return EgressDecision::allow($host, $port, $addresses);
    }

    /**
     * True when every address of an allowed decision sits in a range that is
     * blocked by default, i.e. it was opened by an installation allow rule
     * (same server or private network). Used to permit unencrypted connections
     * only there.
     */
    public function isPrivateDestination(EgressDecision $decision): bool
    {
        if (! $decision->allowed || $decision->addresses === []) {
            return false;
        }

        foreach ($decision->addresses as $address) {
            $private = false;
            foreach ($this->effectiveAddresses($address) as $candidate) {
                $private = $private || $this->inAny($candidate, self::BLOCKED_RANGES);
            }
            if (! $private) {
                return false;
            }
        }

        return true;
    }

    /**
     * Installation-wide limits, in seconds. Organizations can't override them.
     *
     * @return array{connect: int, query: int}
     */
    public function timeouts(): array
    {
        return [
            'connect' => $this->boundedSeconds('connect_timeout', self::DEFAULT_CONNECT_TIMEOUT, self::MAX_CONNECT_TIMEOUT),
            'query' => $this->boundedSeconds('query_timeout', self::DEFAULT_QUERY_TIMEOUT, self::MAX_QUERY_TIMEOUT),
        ];
    }

    /**
     * Parsed allow rules: CIDR ranges or single IPs, and exact hostnames.
     *
     * @return array{ranges: list<IpRange>, hosts: list<string>}
     */
    public function allowRules(): array
    {
        $ranges = [];
        $hosts = [];
        $raw = config('services.managed_databases.egress_allow');

        foreach (is_string($raw) ? explode(',', $raw) : [] as $rule) {
            $rule = strtolower(trim($rule));
            if ($rule === '') {
                continue;
            }

            if (IpRange::pack(explode('/', $rule, 2)[0]) !== null) {
                $ranges[] = IpRange::parse($rule);
            } elseif (($host = $this->normalizeHost($rule)) !== null && IpRange::pack($host) === null) {
                $hosts[] = $host;
            } else {
                throw new InvalidArgumentException("PANE_MANAGED_DB_EGRESS_ALLOW has an invalid rule [$rule].");
            }
        }

        return ['ranges' => $ranges, 'hosts' => $hosts];
    }

    /**
     * The address itself plus any IPv4 address embedded in it (IPv4-mapped,
     * IPv4-compatible or NAT64), so `::ffff:127.0.0.1` is treated as loopback.
     *
     * @return list<string>
     */
    private function effectiveAddresses(string $address): array
    {
        $packed = (string) IpRange::pack($address);
        $candidates = [$address];

        if (strlen($packed) === 16) {
            $prefix = substr($packed, 0, 12);
            if ($prefix === str_repeat("\0", 10)."\xff\xff" || $prefix === "\x00\x64\xff\x9b".str_repeat("\0", 8)
                || ($prefix === str_repeat("\0", 12) && substr($packed, 12) !== "\0\0\0\0" && substr($packed, 12) !== "\0\0\0\1")) {
                $candidates[] = (string) inet_ntop(substr($packed, 12));
            }
        }

        return $candidates;
    }

    /**
     * @param  list<string>  $ranges
     */
    private function inAny(string $address, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (IpRange::parse($range)->contains($address)) {
                return true;
            }
        }

        return false;
    }

    private function inAllowedRange(string $address): bool
    {
        foreach ($this->allowRules()['ranges'] as $range) {
            if ($range->contains($address)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function allowedHosts(): array
    {
        return $this->allowRules()['hosts'];
    }

    /** Lowercase hostname or IP literal, or null for anything else (URLs, credentials, paths, ports). */
    private function normalizeHost(string $host): ?string
    {
        $host = strtolower(trim($host));
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }
        $host = rtrim($host, '.');

        if ($host === '' || strlen($host) > 253) {
            return null;
        }

        if (IpRange::pack($host) !== null) {
            return $host;
        }

        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }

        return $host;
    }

    private function boundedSeconds(string $key, int $default, int $max): int
    {
        $value = config("services.managed_databases.$key");
        $seconds = is_numeric($value) ? (int) $value : $default;

        return max(1, min($max, $seconds));
    }
}
