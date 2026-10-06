<?php

declare(strict_types=1);

namespace Marko\Webhook\Sending;

use Marko\Database\Config\DatabaseTimezoneConfig;
use Marko\Http\HttpResponse;
use Marko\Webhook\Contracts\WebhookAttemptRepositoryInterface;
use Marko\Webhook\Entity\WebhookAttempt;
use Marko\Webhook\Value\WebhookPayload;
use Marko\Webhook\Value\WebhookResponse;
use Psr\Clock\ClockInterface;

/**
 * Records webhook delivery attempts. attemptedAt is written in the database
 * timezone (`database.timezone`, UTC by default). Every recorded response body is
 * trimmed and capped at 500 bytes (HttpResponse::bodyExcerpt()), so a large page from
 * the receiver cannot overflow webhook_attempts.response_body.
 */
readonly class WebhookDeliveryService
{
    public function __construct(
        private WebhookAttemptRepositoryInterface $repository,
        private ClockInterface $clock,
        private DatabaseTimezoneConfig $databaseTimezoneConfig,
    ) {}

    public function recordSuccess(
        WebhookPayload $payload,
        WebhookResponse $response,
        int $attempt,
    ): void {
        $webhookAttempt = new WebhookAttempt(
            webhookUrl: $payload->url,
            event: $payload->event,
            attemptNumber: $attempt,
        );

        $webhookAttempt->statusCode = $response->statusCode;
        $webhookAttempt->responseBody = $this->excerpt($response);
        $webhookAttempt->attemptedAt = $this->databaseTimezoneConfig->format($this->clock->now());

        $this->repository->save($webhookAttempt);
    }

    /**
     * Record a delivery the receiver answered with a non-2xx status.
     */
    public function recordRejection(
        WebhookPayload $payload,
        WebhookResponse $response,
        int $attempt,
    ): void {
        $webhookAttempt = new WebhookAttempt(
            webhookUrl: $payload->url,
            event: $payload->event,
            attemptNumber: $attempt,
        );

        $webhookAttempt->statusCode = $response->statusCode;
        $webhookAttempt->responseBody = $this->excerpt($response);
        $webhookAttempt->errorMessage = "Webhook receiver responded with HTTP $response->statusCode.";
        $webhookAttempt->attemptedAt = $this->databaseTimezoneConfig->format($this->clock->now());

        $this->repository->save($webhookAttempt);
    }

    public function recordFailure(
        WebhookPayload $payload,
        string $error,
        int $attempt,
    ): void {
        $webhookAttempt = new WebhookAttempt(
            webhookUrl: $payload->url,
            event: $payload->event,
            attemptNumber: $attempt,
        );

        $webhookAttempt->errorMessage = $error;
        $webhookAttempt->attemptedAt = $this->databaseTimezoneConfig->format($this->clock->now());

        $this->repository->save($webhookAttempt);
    }

    private function excerpt(
        WebhookResponse $response,
    ): string {
        return new HttpResponse($response->statusCode, $response->body)->bodyExcerpt();
    }
}
