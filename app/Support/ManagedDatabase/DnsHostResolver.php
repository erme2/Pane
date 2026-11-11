<?php

namespace App\Support\ManagedDatabase;

/** Resolves A and AAAA records through the system resolver. */
final class DnsHostResolver implements HostResolver
{
    public function resolve(string $hostname): array
    {
        $addresses = [];
        $records = @dns_get_record($hostname, DNS_A | DNS_AAAA);

        foreach (is_array($records) ? $records : [] as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        // /etc/hosts entries (e.g. localhost) aren't returned by dns_get_record.
        if ($addresses === []) {
            $ipv4 = @gethostbynamel($hostname);
            $addresses = is_array($ipv4) ? $ipv4 : [];
        }

        return array_values(array_unique($addresses));
    }
}
