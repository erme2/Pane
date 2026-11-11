<?php

namespace App\Support\ManagedDatabase;

/**
 * The outcome of checking a managed-database destination. When allowed, the
 * caller must connect to one of the pinned addresses (never re-resolve the
 * hostname), so DNS can't be rebound between the check and the connection.
 */
final class EgressDecision
{
    public const string INVALID_DESTINATION = 'invalid_destination';

    public const string UNRESOLVABLE = 'unresolvable';

    public const string BLOCKED_ADDRESS = 'blocked_address';

    public const string METADATA_ADDRESS = 'metadata_address';

    /**
     * @param  list<string>  $addresses  every resolved address (pinned when allowed)
     */
    private function __construct(
        public readonly bool $allowed,
        public readonly string $host,
        public readonly int $port,
        public readonly array $addresses,
        public readonly ?string $reason,
        public readonly ?string $blockedAddress = null,
    ) {}

    /**
     * @param  list<string>  $addresses
     */
    public static function allow(string $host, int $port, array $addresses): self
    {
        return new self(true, $host, $port, $addresses, null);
    }

    /**
     * @param  list<string>  $addresses
     */
    public static function deny(string $host, int $port, string $reason, array $addresses = [], ?string $blockedAddress = null): self
    {
        return new self(false, $host, $port, $addresses, $reason, $blockedAddress);
    }

    /** Safe to show to administrators: no credentials, only the destination and the rule. */
    public function message(): string
    {
        return match ($this->reason) {
            null => "Connections to {$this->host}:{$this->port} are allowed.",
            self::INVALID_DESTINATION => 'The database host or port is not valid.',
            self::UNRESOLVABLE => "The database host {$this->host} does not resolve.",
            self::METADATA_ADDRESS => "The database host {$this->host} points to a cloud metadata address, which is never allowed.",
            default => "The database host {$this->host} resolves to {$this->blockedAddress}, which is a blocked network. A Pane administrator can allow it with PANE_MANAGED_DB_EGRESS_ALLOW.",
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function auditMetadata(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'addresses' => $this->addresses,
            'reason' => $this->reason,
            'blocked_address' => $this->blockedAddress,
        ];
    }
}
