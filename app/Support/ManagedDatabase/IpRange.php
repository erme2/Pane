<?php

namespace App\Support\ManagedDatabase;

use InvalidArgumentException;

/**
 * An IPv4 or IPv6 CIDR range, compared on packed binary addresses.
 */
final class IpRange
{
    private function __construct(
        public readonly string $cidr,
        private readonly string $network,
        private readonly int $prefix,
    ) {}

    public static function parse(string $cidr): self
    {
        $cidr = trim($cidr);
        [$address, $prefix] = str_contains($cidr, '/') ? explode('/', $cidr, 2) : [$cidr, null];
        $packed = self::pack($address);

        if ($packed === null) {
            throw new InvalidArgumentException("Invalid IP range [$cidr].");
        }

        $bits = strlen($packed) * 8;
        if ($prefix === null) {
            $length = $bits;
        } elseif (ctype_digit($prefix) && (int) $prefix <= $bits) {
            $length = (int) $prefix;
        } else {
            throw new InvalidArgumentException("Invalid IP range [$cidr].");
        }

        return new self($cidr, self::mask($packed, $length), $length);
    }

    public function contains(string $address): bool
    {
        $packed = self::pack($address);

        return $packed !== null
            && strlen($packed) === strlen($this->network)
            && self::mask($packed, $this->prefix) === $this->network;
    }

    /** Packed binary form of an IP literal, or null when it isn't one. */
    public static function pack(string $address): ?string
    {
        $address = trim($address, ' []');
        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($address);

        return $packed === false ? null : $packed;
    }

    private static function mask(string $packed, int $prefix): string
    {
        $out = '';
        foreach (str_split($packed) as $index => $byte) {
            $bits = max(0, min(8, $prefix - $index * 8));
            $out .= chr(ord($byte) & (0xFF << (8 - $bits)) & 0xFF);
        }

        return $out;
    }
}
