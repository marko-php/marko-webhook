<?php

declare(strict_types=1);

namespace Marko\Webhook\Contracts;

use Marko\Http\Exceptions\HttpException;
use Marko\Webhook\Exceptions\InvalidWebhookPayloadException;
use Marko\Webhook\Exceptions\UnsafeWebhookUrlException;
use Marko\Webhook\Value\WebhookPayload;
use Marko\Webhook\Value\WebhookResponse;

interface WebhookDispatcherInterface
{
    /**
     * Send the webhook. Every HTTP response, including 4xx/5xx, is returned as a WebhookResponse
     * (check $response->successful); transport failures throw, as does a payload that cannot be sent at all.
     *
     * @throws HttpException|UnsafeWebhookUrlException|InvalidWebhookPayloadException
     */
    public function dispatch(
        WebhookPayload $payload,
    ): WebhookResponse;
}
