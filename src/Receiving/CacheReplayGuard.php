<?php

declare(strict_types=1);

namespace Marko\Webhook\Receiving;

use Marko\Cache\Contracts\CacheInterface;
use Marko\Cache\Exceptions\InvalidKeyException;
use Marko\Webhook\Contracts\WebhookReplayGuardInterface;

/**
 * Replay guard backed by marko/cache. Uses the cache's atomic increment(), so only the first
 * request carrying a given X-Webhook-Id claims it. The ID is hashed into the cache key, so any
 * sender-chosen ID is a valid key.
 *
 * Use a cache store shared by every web server (e.g. Redis); a per-process or per-server store
 * only catches replays that land on the same process or server.
 */
readonly class CacheReplayGuard implements WebhookReplayGuardInterface
{
    private const string KEY_PREFIX = 'webhook.seen.';

    public function __construct(
        private CacheInterface $cache,
    ) {}

    /**
     * @throws InvalidKeyException
     */
    public function claim(
        string $webhookId,
        int $ttl,
    ): bool {
        return $this->cache->increment(self::KEY_PREFIX . hash('sha256', $webhookId), $ttl) === 1;
    }
}
