<?php

declare(strict_types=1);

namespace Marko\Webhook\Jobs;

use Marko\Config\ConfigRepositoryInterface;
use Marko\Config\Exceptions\ConfigNotFoundException;
use Marko\Core\Container\ContainerInterface;
use Marko\Queue\ContainerAwareJobInterface;
use Marko\Queue\Job;
use Marko\Queue\JobEnvelope;
use Marko\Queue\QueueInterface;
use Marko\Webhook\Contracts\WebhookDispatcherInterface;
use Marko\Webhook\Sending\WebhookDeliveryService;
use Marko\Webhook\Value\WebhookPayload;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use Throwable;

class DispatchWebhookJob extends Job implements ContainerAwareJobInterface
{
    private ?ContainerInterface $container = null;

    public function __construct(
        public readonly WebhookPayload $payload,
        public readonly int $attemptNumber = 1,
    ) {}

    public function setContainer(ContainerInterface $container): void
    {
        $this->container = $container;
    }

    public function setJobEnvelope(JobEnvelope $jobEnvelope): void
    {
        // Not needed for this job — HMAC envelope is only for AsyncObserverJob event data
    }

    public function releaseContainer(): void
    {
        $this->container = null;
    }

    /**
     * @throws RuntimeException|ContainerExceptionInterface|NotFoundExceptionInterface
     */
    public function handle(): void
    {
        if ($this->container === null) {
            throw new RuntimeException(
                'DispatchWebhookJob::handle() was called without a container. '
                . 'Call setContainer() before handle() to provide the DI container for service resolution.',
            );
        }

        $dispatcher = $this->container->get(WebhookDispatcherInterface::class);
        $deliveryService = $this->container->get(WebhookDeliveryService::class);

        // Only the send sits in the try: a failure while recording a delivered webhook must surface,
        // not be mistaken for a failed delivery and re-sent.
        try {
            $response = $dispatcher->dispatch($this->payload);
        } catch (Throwable $e) {
            // Transport failure: the receiver never answered.
            $deliveryService->recordFailure($this->payload, $e->getMessage(), $this->attemptNumber);
            $this->scheduleRetry($this->container);

            return;
        }

        if ($response->successful) {
            $deliveryService->recordSuccess($this->payload, $response, $this->attemptNumber);

            return;
        }

        $deliveryService->recordRejection($this->payload, $response, $this->attemptNumber);

        // 408, 429 and 5xx may succeed later; other non-2xx statuses are final.
        if ($response->isRetryable()) {
            $this->scheduleRetry($this->container);
        }
    }

    /**
     * Re-queue the next attempt with exponential backoff, unless retries are exhausted or not configured.
     *
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface
     */
    private function scheduleRetry(
        ContainerInterface $container,
    ): void {
        try {
            /** @var ConfigRepositoryInterface $config */
            $config = $container->get(ConfigRepositoryInterface::class);
            $maxRetries = $config->getInt('webhook.max_retries');
            $retryDelay = $config->getInt('webhook.retry_delay');
        } catch (ConfigNotFoundException) {
            // Without retry config the attempt is recorded but never retried.
            return;
        }

        if ($this->attemptNumber >= $maxRetries) {
            return;
        }

        /** @var QueueInterface $queue */
        $queue = $container->get(QueueInterface::class);
        $queue->later($retryDelay * (2 ** $this->attemptNumber), new self($this->payload, $this->attemptNumber + 1));
    }
}
