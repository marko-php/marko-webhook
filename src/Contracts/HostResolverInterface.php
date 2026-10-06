<?php

declare(strict_types=1);

namespace Marko\Webhook\Contracts;

/**
 * Resolves a hostname to the IP addresses an HTTP client would connect to.
 * Bind a fake in tests so URL policy checks never perform live DNS lookups.
 */
interface HostResolverInterface
{
    /**
     * Return every IPv4 and IPv6 address the host resolves to, or an empty list
     * when it does not resolve. An IP literal resolves to itself.
     *
     * @return list<string>
     */
    public function resolve(
        string $host,
    ): array;
}
