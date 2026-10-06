<?php

declare(strict_types=1);

namespace Marko\Webhook\Tests\Jobs;

use Closure;
use Marko\Config\ConfigRepositoryInterface;
use Marko\Core\Container\ContainerInterface;
use Marko\Database\Config\DatabaseTimezoneConfig;
use Marko\Http\Exceptions\ConnectionException;
use Marko\Http\HttpResponse;
use Marko\Http\RequestOptions;
use Marko\Queue\QueueInterface;
use Marko\Testing\Fake\FakeClock;
use Marko\Testing\Fake\FakeConfigRepository;
use Marko\Testing\Fake\FakeHttpClient;
use Marko\Testing\Fake\FakeQueue;
use Marko\Webhook\Config\WebhookConfig;
use Marko\Webhook\Contracts\WebhookAttemptRepositoryInterface;
use Marko\Webhook\Contracts\WebhookDispatcherInterface;
use Marko\Webhook\Entity\WebhookAttempt;
use Marko\Webhook\Jobs\DispatchWebhookJob;
use Marko\Webhook\Sending\WebhookDeliveryService;
use Marko\Webhook\Sending\WebhookDispatcher;
use Marko\Webhook\Tests\Fixtures\FakeHostResolver;
use Marko\Webhook\Value\WebhookPayload;
use RuntimeException;

/**
 * Records every saved attempt; optionally fails on save to simulate a database outage.
 */
class RecordingWebhookAttemptRepository implements WebhookAttemptRepositoryInterface
{
    /** @var array<WebhookAttempt> */
    public array $saved = [];

    public function __construct(
        private readonly bool $failOnSave = false,
    ) {}

    public function save(
        WebhookAttempt $attempt,
    ): WebhookAttempt {
        if ($this->failOnSave) {
            throw new RuntimeException('Database is unavailable');
        }

        $this->saved[] = $attempt;

        return $attempt;
    }
}

/**
 * Wires a real WebhookDispatcher on a FakeHttpClient into a DispatchWebhookJob, so the
 * http_errors => false path runs end to end.
 *
 * @param array<string, mixed> $config
 * @return array{job: DispatchWebhookJob, queue: FakeQueue, http: FakeHttpClient}
 */
function webhookJob(
    HttpResponse|ConnectionException $receiverResponse,
    RecordingWebhookAttemptRepository $repository,
    int $attemptNumber = 1,
    array $config = ['webhook.max_retries' => 3, 'webhook.retry_delay' => 60],
    string $url = 'https://example.com/webhook',
): array {
    $httpClient = new FakeHttpClient()->stub('https://example.com/webhook', $receiverResponse);
    $queue = new FakeQueue();

    $services = [
        WebhookDispatcherInterface::class => new WebhookDispatcher(
            $httpClient,
            new FakeClock(),
            new WebhookConfig(new FakeConfigRepository([
                'webhook.timeout' => 30,
                'webhook.max_retries' => 3,
                'webhook.retry_delay' => 60,
                'webhook.timestamp_tolerance' => 300,
            ])),
            FakeHostResolver::policy(),
        ),
        WebhookDeliveryService::class => new WebhookDeliveryService(
            $repository,
            new FakeClock(),
            DatabaseTimezoneConfig::fromName('UTC'),
        ),
        ConfigRepositoryInterface::class => new FakeConfigRepository($config),
        QueueInterface::class => $queue,
    ];

    $container = new readonly class ($services) implements ContainerInterface
    {
        /**
         * @param array<string, object> $services
         */
        public function __construct(
            private array $services,
        ) {}

        public function get(
            string $id,
        ): object {
            return $this->services[$id] ?? throw new RuntimeException("No binding for: $id");
        }

        public function has(
            string $id,
        ): bool {
            return isset($this->services[$id]);
        }

        public function singleton(string $id): void {}

        public function instance(
            string $id,
            object $instance,
        ): void {}

        public function call(
            Closure $callable,
        ): mixed {
            return null;
        }

        public function resolvedInstances(
            ?string $interface = null,
        ): array {
            return [];
        }
    };

    $job = new DispatchWebhookJob(
        new WebhookPayload(
            url: $url,
            event: 'order.created',
            data: ['order_id' => 123],
            secret: 'my-secret',
        ),
        $attemptNumber,
    );
    $job->setContainer($container);

    return ['job' => $job, 'queue' => $queue, 'http' => $httpClient];
}

describe('DispatchWebhookJob error responses', function (): void {
    it('records a successful delivery for a 2xx response without retrying', function (): void {
        $repository = new RecordingWebhookAttemptRepository();
        ['job' => $job, 'queue' => $queue] = webhookJob(new HttpResponse(200, 'OK'), $repository);

        $job->handle();

        expect($repository->saved)->toHaveCount(1)
            ->and($repository->saved[0]->statusCode)->toBe(200)
            ->and($repository->saved[0]->errorMessage)->toBeNull()
            ->and($queue->pushed)->toBeEmpty();
    });

    it('records a 500 response as a failure and schedules a retry', function (): void {
        $repository = new RecordingWebhookAttemptRepository();
        ['job' => $job, 'queue' => $queue] = webhookJob(new HttpResponse(500, 'Internal Server Error'), $repository);

        $job->handle();

        expect($repository->saved)->toHaveCount(1)
            ->and($repository->saved[0]->statusCode)->toBe(500)
            ->and($repository->saved[0]->responseBody)->toBe('Internal Server Error')
            ->and($repository->saved[0]->errorMessage)->toBe('Webhook receiver responded with HTTP 500.')
            ->and($queue->pushed)->toHaveCount(1)
            ->and($queue->pushed[0]['delay'])->toBe(120)
            ->and($queue->pushed[0]['job']->attemptNumber)->toBe(2);
    });

    it('retries 408 and 429 responses', function (int $status): void {
        $repository = new RecordingWebhookAttemptRepository();
        ['job' => $job, 'queue' => $queue] = webhookJob(new HttpResponse($status, ''), $repository);

        $job->handle();

        expect($repository->saved[0]->statusCode)->toBe($status)
            ->and($queue->pushed)->toHaveCount(1);
    })->with([408, 429]);

    it('records a 4xx response as a final failure without retrying', function (int $status): void {
        $repository = new RecordingWebhookAttemptRepository();
        ['job' => $job, 'queue' => $queue] = webhookJob(new HttpResponse($status, 'Gone'), $repository);

        $job->handle();

        expect($repository->saved)->toHaveCount(1)
            ->and($repository->saved[0]->statusCode)->toBe($status)
            ->and($repository->saved[0]->responseBody)->toBe('Gone')
            ->and($repository->saved[0]->errorMessage)->toBe("Webhook receiver responded with HTTP $status.")
            ->and($queue->pushed)->toBeEmpty();
    })->with([400, 401, 404, 410, 422]);

    it('never records a non-2xx response as a success', function (int $status): void {
        $repository = new RecordingWebhookAttemptRepository();
        ['job' => $job] = webhookJob(new HttpResponse($status, ''), $repository);

        $job->handle();

        expect($repository->saved[0]->errorMessage)->toBe("Webhook receiver responded with HTTP $status.");
    })->with([301, 304, 400, 500]);

    it('retries transport failures with exponential backoff', function (): void {
        $repository = new RecordingWebhookAttemptRepository();
        ['job' => $job, 'queue' => $queue] = webhookJob(
            new ConnectionException('Connection refused'),
            $repository,
            attemptNumber: 2,
        );

        $job->handle();

        expect($repository->saved)->toHaveCount(1)
            ->and($repository->saved[0]->statusCode)->toBeNull()
            ->and($repository->saved[0]->errorMessage)->toBe('Connection refused')
            ->and($queue->pushed)->toHaveCount(1)
            ->and($queue->pushed[0]['delay'])->toBe(240);
    });

    it('records a timed-out request as a failure and retries it', function (): void {
        $timeout = 'cURL error 28: Operation timed out after 30001 milliseconds with 0 bytes received';
        $repository = new RecordingWebhookAttemptRepository();
        ['job' => $job, 'queue' => $queue, 'http' => $http] = webhookJob(
            new ConnectionException($timeout),
            $repository,
        );

        $job->handle();

        expect($http->requests[0]->options[RequestOptions::TIMEOUT])->toBe(30)
            ->and($repository->saved)->toHaveCount(1)
            ->and($repository->saved[0]->statusCode)->toBeNull()
            ->and($repository->saved[0]->errorMessage)->toBe($timeout)
            ->and($queue->pushed)->toHaveCount(1)
            ->and($queue->pushed[0]['delay'])->toBe(120);
    });

    it('stops retrying a retryable status once max retries is reached', function (): void {
        $repository = new RecordingWebhookAttemptRepository();
        ['job' => $job, 'queue' => $queue] = webhookJob(
            new HttpResponse(503, 'Service Unavailable'),
            $repository,
            attemptNumber: 3,
        );

        $job->handle();

        expect($repository->saved)->toHaveCount(1)
            ->and($repository->saved[0]->statusCode)->toBe(503)
            ->and($queue->pushed)->toBeEmpty();
    });

    it('records a rejection without retrying when retry config is missing', function (): void {
        $repository = new RecordingWebhookAttemptRepository();
        ['job' => $job, 'queue' => $queue] = webhookJob(
            new HttpResponse(500, 'Internal Server Error'),
            $repository,
            config: [],
        );

        $job->handle();

        expect($repository->saved)->toHaveCount(1)
            ->and($repository->saved[0]->statusCode)->toBe(500)
            ->and($queue->pushed)->toBeEmpty();
    });

    it('does not resend the webhook when recording a successful delivery fails', function (): void {
        $repository = new RecordingWebhookAttemptRepository(failOnSave: true);
        ['job' => $job, 'queue' => $queue, 'http' => $http] = webhookJob(new HttpResponse(200, 'OK'), $repository);

        expect(fn () => $job->handle())->toThrow(RuntimeException::class, 'Database is unavailable')
            ->and($queue->pushed)->toBeEmpty()
            ->and($http->requests)->toHaveCount(1);
    });

    it('records a URL the policy rejects as a final failure without sending or retrying', function (): void {
        $repository = new RecordingWebhookAttemptRepository();
        ['job' => $job, 'queue' => $queue, 'http' => $http] = webhookJob(
            new HttpResponse(200, 'OK'),
            $repository,
            url: 'https://169.254.169.254/latest/meta-data/iam/',
        );

        $job->handle();

        expect($repository->saved)->toHaveCount(1)
            ->and($repository->saved[0]->statusCode)->toBeNull()
            ->and($repository->saved[0]->errorMessage)->toContain('link-local range (169.254.0.0/16)')
            ->and($queue->pushed)->toBeEmpty()
            ->and($http->requests)->toBeEmpty();
    });
});
