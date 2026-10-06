<?php

declare(strict_types=1);

namespace Marko\Webhook\Tests\Fixtures;

use Marko\Testing\Fake\FakeConfigRepository;
use Marko\Webhook\Contracts\HostResolverInterface;
use Marko\Webhook\Sending\WebhookUrlPolicy;

/**
 * Resolves hosts from a fixed map so URL policy tests never perform live DNS lookups.
 * IP literals resolve to themselves; unknown hosts resolve to nothing.
 */
class FakeHostResolver implements HostResolverInterface
{
    /** @var list<string> */
    public private(set) array $lookups = [];

    /**
     * @param array<string, list<string>> $hosts
     */
    public function __construct(
        private readonly array $hosts = [],
    ) {}

    /**
     * @return list<string>
     */
    public function resolve(
        string $host,
    ): array {
        $this->lookups[] = $host;

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        return $this->hosts[$host] ?? [];
    }

    /**
     * A WebhookUrlPolicy whose resolver maps example.com to a public address.
     *
     * @param array<string, list<string>> $hosts
     */
    public static function policy(
        array $hosts = ['example.com' => ['93.184.215.14']],
        bool $allowHttp = false,
    ): WebhookUrlPolicy {
        return new WebhookUrlPolicy(
            new FakeConfigRepository(['webhook.allow_http' => $allowHttp]),
            new self($hosts),
        );
    }
}
