<?php

declare(strict_types=1);

namespace Marko\Webhook;

use Marko\Webhook\Exceptions\InvalidWebhookSecretException;

class WebhookSecret
{
    /**
     * Minimum length, in bytes, of a shared secret used to sign or verify webhooks.
     */
    public const int MIN_LENGTH = 16;

    /**
     * Reject secrets that would make the HMAC signature forgeable.
     *
     * @throws InvalidWebhookSecretException
     */
    public static function assertValid(
        string $secret,
    ): void {
        if ($secret === '') {
            throw InvalidWebhookSecretException::empty();
        }

        if (strlen($secret) < self::MIN_LENGTH) {
            throw InvalidWebhookSecretException::tooShort(strlen($secret));
        }
    }
}
