<?php

declare(strict_types=1);

namespace Marko\Webhook\Sending;

use Marko\Webhook\Exceptions\InvalidWebhookSecretException;
use Marko\Webhook\WebhookSecret;

class WebhookSignature
{
    /**
     * Sign "{webhookId}.{timestamp}.{payload}", so neither the delivery ID nor the timestamp
     * can be changed without invalidating the signature.
     *
     * @throws InvalidWebhookSecretException
     */
    public static function sign(
        string $payload,
        string $secret,
        int $timestamp,
        string $webhookId,
    ): string {
        WebhookSecret::assertValid($secret);

        return 'sha256=' . hash_hmac('sha256', "$webhookId.$timestamp.$payload", $secret);
    }
}
