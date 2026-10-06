<?php

declare(strict_types=1);

namespace Marko\Webhook\Tests\Sending;

use Marko\Webhook\Sending\DnsHostResolver;

describe('DnsHostResolver', function (): void {
    it('resolves an IP literal to itself without a DNS lookup', function (
        string $address,
    ): void {
        expect(new DnsHostResolver()->resolve($address))->toBe([$address]);
    })->with([
        '169.254.169.254',
        '93.184.215.14',
        '::1',
        '::ffff:127.0.0.1',
    ]);
});
