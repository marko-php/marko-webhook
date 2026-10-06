<?php

declare(strict_types=1);

namespace Marko\Webhook\Exceptions;

class WebhookPayloadTooLargeException extends InvalidWebhookPayloadException
{
    public static function forBody(
        int $size,
        int $maxBodyBytes,
    ): self {
        return new self(
            message: "Webhook request body is $size bytes, over the $maxBodyBytes-byte limit.",
            context: 'While checking the size of an incoming webhook request body, before verifying its signature.',
            suggestion: 'Send smaller webhook payloads, or raise webhook.max_body_bytes if the receiver must accept larger ones.',
        );
    }
}
