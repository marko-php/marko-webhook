<?php

declare(strict_types=1);

namespace Marko\Webhook\Tests\Receiving;

use Marko\Cache\Contracts\CacheInterface;
use Marko\Webhook\Receiving\CacheReplayGuard;

describe('CacheReplayGuard', function (): void {
    it('claims a delivery ID the first time and rejects it afterwards', function (): void {
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('increment')->willReturnOnConsecutiveCalls(1, 2);
        $guard = new CacheReplayGuard($cache);

        expect($guard->claim('delivery-1', 600))->toBeTrue()
            ->and($guard->claim('delivery-1', 600))->toBeFalse();
    });

    it('increments a hashed key with the given TTL so any sender-chosen ID is a valid cache key', function (): void {
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->once())
            ->method('increment')
            ->with('webhook.seen.' . hash('sha256', 'id/with:{reserved}@chars'), 600)
            ->willReturn(1);

        expect(new CacheReplayGuard($cache)->claim('id/with:{reserved}@chars', 600))->toBeTrue();
    });
});
