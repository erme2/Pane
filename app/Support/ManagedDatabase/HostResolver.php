<?php

namespace App\Support\ManagedDatabase;

interface HostResolver
{
    /**
     * Every IPv4 and IPv6 address the hostname resolves to (after following
     * aliases), or an empty list when it doesn't resolve.
     *
     * @return list<string>
     */
    public function resolve(string $hostname): array;
}
