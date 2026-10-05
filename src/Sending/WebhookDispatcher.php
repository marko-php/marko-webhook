<?php

declare(strict_types=1);

namespace Marko\Webhook\Sending;

use Marko\Http\Contracts\HttpClientInterface;
use Marko\Http\Exceptions\ConnectionException;
use Marko\Http\Exceptions\HttpException;
use Marko\Webhook\Contracts\WebhookDispatcherInterface;
use Marko\Webhook\Value\WebhookPayload;
use Marko\Webhook\Value\WebhookResponse;
use Psr\Clock\ClockInterface;

readonly class WebhookDispatcher implements WebhookDispatcherInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ClockInterface $clock,
    ) {}

    /**
     * @throws ConnectionException|HttpException
     */
    public function dispatch(
        WebhookPayload $payload,
    ): WebhookResponse {
        $body = json_encode(['event' => $payload->event, 'data' => $payload->data]);
        $timestamp = $this->clock->now()->getTimestamp();
        $signature = WebhookSignature::sign($body, $payload->secret, $timestamp);

        $httpResponse = $this->httpClient->post($payload->url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Webhook-Signature' => $signature,
                'X-Webhook-Timestamp' => (string) $timestamp,
            ],
            'body' => $body,
        ]);

        return new WebhookResponse(
            statusCode: $httpResponse->statusCode(),
            body: $httpResponse->body(),
            successful: $httpResponse->isSuccessful(),
        );
    }
}
