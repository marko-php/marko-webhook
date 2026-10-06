<?php

declare(strict_types=1);

namespace Marko\Webhook\Receiving;

use Marko\Webhook\Exceptions\InvalidWebhookSecretException;
use Marko\Webhook\WebhookSecret;
use Psr\Clock\ClockInterface;

class WebhookVerifier
{
    public function __construct(
        private readonly ClockInterface $clock,
    ) {}

    /**
     * Check the signature over "{webhookId}.{timestamp}.{body}" and that the timestamp is within $tolerance seconds.
     *
     * @throws InvalidWebhookSecretException
     */
    public function verify(
        string $body,
        string $timestamp,
        string $signature,
        string $secret,
        int $tolerance,
        string $webhookId,
    ): bool {
        WebhookSecret::assertValid($secret);

        if (abs($this->clock->now()->getTimestamp() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', "$webhookId.$timestamp.$body", $secret);

        return hash_equals($expected, $signature);
    }
}
