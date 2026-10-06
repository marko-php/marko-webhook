<?php

declare(strict_types=1);

namespace Marko\Webhook\Exceptions;

use Marko\Core\Exceptions\MarkoException;

class InvalidSignatureException extends MarkoException
{
    public static function forRequest(): self
    {
        return new self(
            message: 'Invalid webhook signature.',
            context: 'While verifying the HMAC-SHA256 signature of the incoming webhook request.',
            suggestion: 'Ensure the signing secret matches and the request body has not been tampered with.',
        );
    }

    public static function missingTimestamp(): self
    {
        return new self(
            message: 'Webhook request is missing the X-Webhook-Timestamp header.',
            context: 'While checking timestamp freshness of the incoming webhook request.',
            suggestion: 'Ensure the sender includes the X-Webhook-Timestamp header with a Unix epoch timestamp.',
        );
    }

    public static function missingWebhookId(): self
    {
        return new self(
            message: 'Webhook request is missing the X-Webhook-Id header.',
            context: 'While verifying the incoming webhook request. The delivery ID is part of the signed message and identifies retries of the same delivery.',
            suggestion: 'Ensure the sender includes the X-Webhook-Id header and signs "{id}.{timestamp}.{body}". Marko senders do this since the X-Webhook-Id header was introduced.',
        );
    }

    public static function replayed(
        string $webhookId,
    ): self {
        return new self(
            message: "Webhook delivery \"$webhookId\" was already received.",
            context: 'While checking the X-Webhook-Id of the incoming webhook request against recently received deliveries (webhook.replay_protection).',
            suggestion: 'A delivery is accepted once. If the sender retried a delivery you already processed, answer it with a 2xx status; if not, the request was replayed.',
        );
    }

    public static function staleTimestamp(
        int $timestamp,
        int $tolerance,
        int $now,
    ): self {
        $age = abs($now - $timestamp);

        return new self(
            message: "Webhook timestamp is outside the freshness window (age: {$age}s, tolerance: {$tolerance}s).",
            context: 'While checking timestamp freshness of the incoming webhook request.',
            suggestion: 'Ensure the webhook was sent recently and that clocks are synchronized. Replay attacks are rejected.',
        );
    }
}
