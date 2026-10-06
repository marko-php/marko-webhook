<?php

declare(strict_types=1);

namespace Marko\Webhook\Sending;

use Marko\Webhook\Exceptions\InvalidWebhookSecretException;
use Marko\Webhook\WebhookSecret;

class WebhookSignature
{
    /**
     * @throws InvalidWebhookSecretException
     */
    public static function sign(
        string $payload,
        string $secret,
        int $timestamp,
    ): string {
        WebhookSecret::assertValid($secret);

        return 'sha256=' . hash_hmac('sha256', "$timestamp.$payload", $secret);
    }
}
