<?php

declare(strict_types=1);

namespace Marko\Webhook\Sending;

use JsonException;
use Marko\Http\Contracts\HttpClientInterface;
use Marko\Http\Exceptions\ConnectionException;
use Marko\Http\Exceptions\HttpException;
use Marko\Http\RequestOptions;
use Marko\Webhook\Config\WebhookConfig;
use Marko\Webhook\Contracts\WebhookDispatcherInterface;
use Marko\Webhook\Contracts\WebhookUrlPolicyInterface;
use Marko\Webhook\Exceptions\InvalidWebhookPayloadException;
use Marko\Webhook\Exceptions\InvalidWebhookSecretException;
use Marko\Webhook\Exceptions\UnsafeWebhookUrlException;
use Marko\Webhook\Value\WebhookPayload;
use Marko\Webhook\Value\WebhookResponse;
use Psr\Clock\ClockInterface;

readonly class WebhookDispatcher implements WebhookDispatcherInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private ClockInterface $clock,
        private WebhookConfig $config,
        private WebhookUrlPolicyInterface $urlPolicy,
    ) {}

    /**
     * Returns a WebhookResponse for every HTTP response, 4xx/5xx included; throws only on transport failures.
     * The request is abandoned after webhook.timeout seconds, which surfaces as a ConnectionException.
     *
     * The URL is checked against the webhook URL policy immediately before the request, and redirects are
     * never followed, so a receiver cannot bounce the request to an address the policy would reject.
     * marko/http has no option to pin the connection to the checked IP, so a host whose DNS answer changes
     * between the check and the connection (DNS rebinding) is narrowed to that window, not ruled out.
     *
     * The body is signed together with the payload's delivery ID (X-Webhook-Id) and the timestamp.
     * A payload whose data cannot be encoded as JSON throws InvalidWebhookPayloadException before anything is sent.
     *
     * @throws UnsafeWebhookUrlException|InvalidWebhookPayloadException|InvalidWebhookSecretException|ConnectionException|HttpException
     */
    public function dispatch(
        WebhookPayload $payload,
    ): WebhookResponse {
        $this->urlPolicy->validate($payload->url);

        try {
            $body = json_encode(['event' => $payload->event, 'data' => $payload->data], JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw InvalidWebhookPayloadException::unencodable($payload->event, $e);
        }

        $timestamp = $this->clock->now()->getTimestamp();
        $signature = WebhookSignature::sign($body, $payload->secret, $timestamp, $payload->id);

        $httpResponse = $this->httpClient->post($payload->url, [
            RequestOptions::HEADERS => [
                'Content-Type' => 'application/json',
                'X-Webhook-Id' => $payload->id,
                'X-Webhook-Signature' => $signature,
                'X-Webhook-Timestamp' => (string) $timestamp,
            ],
            RequestOptions::BODY => $body,
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::ALLOW_REDIRECTS => false,
            RequestOptions::TIMEOUT => $this->config->timeout,
        ]);

        return new WebhookResponse(
            statusCode: $httpResponse->statusCode(),
            body: $httpResponse->body(),
            successful: $httpResponse->isSuccessful(),
        );
    }
}
