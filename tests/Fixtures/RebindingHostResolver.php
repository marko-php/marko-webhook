<?php

declare(strict_types=1);

namespace Marko\Webhook\Tests\Fixtures;

use Marko\Webhook\Contracts\HostResolverInterface;

/**
 * Simulates a DNS rebinding attack: every lookup returns the next answer in the sequence,
 * so the first lookup (the policy check) sees a public address and later lookups see an
 * internal one. The last answer repeats once the sequence is exhausted.
 */
class RebindingHostResolver implements HostResolverInterface
{
    /** @var list<string> */
    public private(set) array $lookups = [];

    /**
     * @param list<list<string>> $answers
     */
    public function __construct(
        private readonly array $answers,
    ) {}

    /**
     * @return list<string>
     */
    public function resolve(
        string $host,
    ): array {
        $this->lookups[] = $host;

        return $this->answers[min(count($this->lookups), count($this->answers)) - 1];
    }
}
