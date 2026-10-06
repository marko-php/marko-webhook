<?php

declare(strict_types=1);

namespace Marko\Webhook\Exceptions;

use JsonException;
use Marko\Core\Exceptions\MarkoException;

class InvalidWebhookPayloadException extends MarkoException
{
    public static function malformedJson(
        JsonException $previous,
    ): self {
        return new self(
            message: "Webhook request body is not valid JSON: {$previous->getMessage()}.",
            context: 'While decoding the body of a webhook request whose signature verified.',
            suggestion: 'Send the webhook body as a JSON object, e.g. {"event": "order.created", "data": {}}.',
            previous: $previous,
        );
    }

    public static function notAnArray(
        string $type,
    ): self {
        return new self(
            message: "Webhook request body must decode to a JSON object or array, got $type.",
            context: 'While decoding the body of a webhook request whose signature verified.',
            suggestion: 'Send the webhook body as a JSON object, e.g. {"event": "order.created", "data": {}}. Scalar bodies such as "text", 1 or null are rejected.',
        );
    }

    public static function unencodable(
        string $event,
        JsonException $previous,
    ): self {
        return new self(
            message: "Webhook payload for event \"$event\" cannot be encoded as JSON: {$previous->getMessage()}.",
            context: 'While encoding an outgoing webhook body before signing it. Nothing was sent.',
            suggestion: 'Make sure the payload data contains only JSON-encodable values: valid UTF-8 strings, numbers, booleans, null and arrays of them.',
            previous: $previous,
        );
    }

    public static function emptyId(): self
    {
        return new self(
            message: 'Webhook payload ID must not be empty.',
            context: 'While building a WebhookPayload. The ID is sent as the X-Webhook-Id header and covered by the signature.',
            suggestion: 'Omit the id argument to have a random UUID generated, or pass a unique non-empty string.',
        );
    }
}
