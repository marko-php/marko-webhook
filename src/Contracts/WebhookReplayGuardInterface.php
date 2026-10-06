<?php

declare(strict_types=1);

namespace Marko\Webhook\Contracts;

/**
 * Remembers which webhook deliveries (X-Webhook-Id) were already received, so a captured
 * request cannot be replayed while its timestamp is still fresh.
 */
interface WebhookReplayGuardInterface
{
    /**
     * Record $webhookId for $ttl seconds. Returns true the first time an ID is claimed and
     * false when it was already claimed within its TTL (a replay). Must be atomic, so two
     * concurrent requests with the same ID cannot both claim it.
     */
    public function claim(
        string $webhookId,
        int $ttl,
    ): bool;
}
