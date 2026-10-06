<?php

declare(strict_types=1);

namespace Marko\Webhook\Tests\Sending;

use Marko\Testing\Fake\FakeClock;
use Marko\Webhook\Contracts\WebhookAttemptRepositoryInterface;
use Marko\Webhook\Entity\WebhookAttempt;
use Marko\Webhook\Sending\WebhookDeliveryService;
use Marko\Webhook\Value\WebhookPayload;
use Marko\Webhook\Value\WebhookResponse;

describe('WebhookDeliveryService', function (): void {
    it('records successful delivery attempts with status code and response', function (): void {
        $savedAttempts = [];

        $repository = new class ($savedAttempts) implements WebhookAttemptRepositoryInterface
        {
            public function __construct(
                private array &$savedAttempts,
            ) {}

            public function save(
                WebhookAttempt $attempt,
            ): WebhookAttempt {
                $this->savedAttempts[] = $attempt;

                return $attempt;
            }
        };

        $service = new WebhookDeliveryService($repository, new FakeClock('2026-01-01 12:00:00'));

        $payload = new WebhookPayload(
            url: 'https://example.com/webhook',
            event: 'order.created',
            data: ['order_id' => 123],
            secret: 'my-secret',
        );

        $response = new WebhookResponse(
            statusCode: 200,
            body: 'OK',
            successful: true,
        );

        $service->recordSuccess($payload, $response, 1);

        expect($savedAttempts)->toHaveCount(1);

        $attempt = $savedAttempts[0];
        expect($attempt)->toBeInstanceOf(WebhookAttempt::class)
            ->and($attempt->webhookUrl)->toBe('https://example.com/webhook')
            ->and($attempt->event)->toBe('order.created')
            ->and($attempt->statusCode)->toBe(200)
            ->and($attempt->responseBody)->toBe('OK')
            ->and($attempt->errorMessage)->toBeNull()
            ->and($attempt->attemptNumber)->toBe(1)
            ->and($attempt->attemptedAt)->toBe('2026-01-01 12:00:00');
    });

    it('records failed delivery attempts with error details', function (): void {
        $savedAttempts = [];

        $repository = new class ($savedAttempts) implements WebhookAttemptRepositoryInterface
        {
            public function __construct(
                private array &$savedAttempts,
            ) {}

            public function save(
                WebhookAttempt $attempt,
            ): WebhookAttempt {
                $this->savedAttempts[] = $attempt;

                return $attempt;
            }
        };

        $service = new WebhookDeliveryService($repository, new FakeClock('2026-01-01 12:00:00'));

        $payload = new WebhookPayload(
            url: 'https://example.com/webhook',
            event: 'order.created',
            data: ['order_id' => 123],
            secret: 'my-secret',
        );

        $service->recordFailure($payload, 'Connection timed out', 2);

        expect($savedAttempts)->toHaveCount(1);

        $attempt = $savedAttempts[0];
        expect($attempt)->toBeInstanceOf(WebhookAttempt::class)
            ->and($attempt->webhookUrl)->toBe('https://example.com/webhook')
            ->and($attempt->event)->toBe('order.created')
            ->and($attempt->statusCode)->toBeNull()
            ->and($attempt->responseBody)->toBeNull()
            ->and($attempt->errorMessage)->toBe('Connection timed out')
            ->and($attempt->attemptNumber)->toBe(2)
            ->and($attempt->attemptedAt)->toBe('2026-01-01 12:00:00');
    });

    it('records attemptedAt from the clock', function (): void {
        $savedAttempts = [];

        $repository = new class ($savedAttempts) implements WebhookAttemptRepositoryInterface
        {
            public function __construct(
                private array &$savedAttempts,
            ) {}

            public function save(
                WebhookAttempt $attempt,
            ): WebhookAttempt {
                $this->savedAttempts[] = $attempt;

                return $attempt;
            }
        };

        $clock = new FakeClock('2026-01-01 12:00:00');
        $service = new WebhookDeliveryService($repository, $clock);
        $payload = new WebhookPayload(
            url: 'https://example.com/webhook',
            event: 'order.created',
            data: [],
            secret: 'my-secret',
        );

        $service->recordFailure($payload, 'first failure', 1);
        $clock->travel('+90 seconds');
        $service->recordFailure($payload, 'second failure', 2);

        expect($savedAttempts[0]->attemptedAt)->toBe('2026-01-01 12:00:00')
            ->and($savedAttempts[1]->attemptedAt)->toBe('2026-01-01 12:01:30');
    });

    it('records the status and capped body of a rejected delivery', function (): void {
        $savedAttempts = [];

        $repository = new class ($savedAttempts) implements WebhookAttemptRepositoryInterface
        {
            public function __construct(
                private array &$savedAttempts,
            ) {}

            public function save(
                WebhookAttempt $attempt,
            ): WebhookAttempt {
                $this->savedAttempts[] = $attempt;

                return $attempt;
            }
        };

        $service = new WebhookDeliveryService($repository, new FakeClock('2026-01-01 12:00:00'));

        $payload = new WebhookPayload(
            url: 'https://example.com/webhook',
            event: 'order.created',
            data: ['order_id' => 123],
            secret: 'my-secret',
        );

        $response = new WebhookResponse(
            statusCode: 500,
            body: '<html>' . str_repeat('x', 2000) . '</html>',
            successful: false,
        );

        $service->recordRejection($payload, $response, 2);

        expect($savedAttempts)->toHaveCount(1);

        $attempt = $savedAttempts[0];
        expect($attempt->statusCode)->toBe(500)
            ->and($attempt->responseBody)->toStartWith('<html>xxx')
            ->and($attempt->responseBody)->toEndWith('... [truncated 1513 bytes]')
            ->and($attempt->errorMessage)->toBe('Webhook receiver responded with HTTP 500.')
            ->and($attempt->attemptNumber)->toBe(2)
            ->and($attempt->attemptedAt)->toBe('2026-01-01 12:00:00');
    });
});
