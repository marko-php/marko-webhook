<?php

declare(strict_types=1);

namespace Marko\Webhook\Exceptions;

use Marko\Core\Exceptions\MarkoException;
use Marko\Webhook\WebhookSecret;

class InvalidWebhookSecretException extends MarkoException
{
    public static function empty(): self
    {
        return new self(
            message: 'Webhook secret is empty.',
            context: 'While signing or verifying a webhook HMAC-SHA256 signature. An HMAC keyed with an empty secret can be computed by anyone, so every forged request would be accepted.',
            suggestion: sprintf(
                'Configure a random shared secret of at least %d bytes (e.g. bin2hex(random_bytes(32))). If it comes from an environment variable, make sure the variable is set.',
                WebhookSecret::MIN_LENGTH,
            ),
        );
    }

    public static function tooShort(
        int $length,
    ): self {
        return new self(
            message: sprintf(
                'Webhook secret must be at least %d bytes long, got %d.',
                WebhookSecret::MIN_LENGTH,
                $length,
            ),
            context: 'While signing or verifying a webhook HMAC-SHA256 signature. A short secret can be brute-forced from a single signed request.',
            suggestion: sprintf(
                'Configure a random shared secret of at least %d bytes (e.g. bin2hex(random_bytes(32))).',
                WebhookSecret::MIN_LENGTH,
            ),
        );
    }
}
