<?php

declare(strict_types=1);

namespace Marko\Webhook\Sending;

use Marko\Http\Contracts\HttpClientInterface;
use Marko\Http\Exceptions\ConnectionException;
use Marko\Http\Exceptions\HttpException;
use Marko\Http\RequestOptions;
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
     * Returns a WebhookResponse for every HTTP response, 4xx/5xx included; throws only on transport failures.
     *
     * @throws ConnectionException|HttpException
     */
    public function dispatch(
        WebhookPayload $payload,
    ): WebhookResponse {
        $body = json_encode(['event' => $payload->event, 'data' => $payload->data]);
        $timestamp = $this->clock->now()->getTimestamp();
        $signature = WebhookSignature::sign($body, $payload->secret, $timestamp);

        $httpResponse = $this->httpClient->post($payload->url, [
            RequestOptions::HEADERS => [
                'Content-Type' => 'application/json',
                'X-Webhook-Signature' => $signature,
                'X-Webhook-Timestamp' => (string) $timestamp,
            ],
            RequestOptions::BODY => $body,
            RequestOptions::HTTP_ERRORS => false,
        ]);

        return new WebhookResponse(
            statusCode: $httpResponse->statusCode(),
            body: $httpResponse->body(),
            successful: $httpResponse->isSuccessful(),
        );
    }
}
