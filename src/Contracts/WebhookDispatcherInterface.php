<?php

declare(strict_types=1);

namespace Marko\Webhook\Contracts;

use Marko\Http\Exceptions\HttpException;
use Marko\Webhook\Value\WebhookPayload;
use Marko\Webhook\Value\WebhookResponse;

interface WebhookDispatcherInterface
{
    /**
     * Send the webhook. Every HTTP response, including 4xx/5xx, is returned as a WebhookResponse
     * (check $response->successful); only transport failures throw.
     *
     * @throws HttpException
     */
    public function dispatch(
        WebhookPayload $payload,
    ): WebhookResponse;
}
