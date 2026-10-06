<?php

declare(strict_types=1);

namespace Marko\Webhook\Config;

use Marko\Config\ConfigRepositoryInterface;
use Marko\Config\Exceptions\ConfigException;
use Marko\Config\Exceptions\ConfigNotFoundException;

/** @noinspection PhpUnused */
readonly class WebhookConfig
{
    public int $timeout;

    public int $maxRetries;

    public int $retryDelay;

    public int $timestampTolerance;

    /**
     * @throws ConfigException|ConfigNotFoundException
     */
    public function __construct(
        ConfigRepositoryInterface $config,
    ) {
        $this->timeout = $this->positiveTimeout($config->getInt('webhook.timeout'));
        $this->maxRetries = $this->nonNegative(
            'webhook.max_retries',
            $config->getInt('webhook.max_retries'),
            'Set it to how many times a failed delivery is retried, or 0 to never retry (e.g. 3).',
        );
        $this->retryDelay = $this->nonNegative(
            'webhook.retry_delay',
            $config->getInt('webhook.retry_delay'),
            'Set it to the base number of seconds before the first retry; each later retry doubles it (e.g. 60). A negative delay is not a valid backoff.',
        );
        $this->timestampTolerance = $this->positiveTolerance($config->getInt('webhook.timestamp_tolerance'));
    }

    /**
     * @throws ConfigException
     */
    private function nonNegative(
        string $key,
        int $value,
        string $suggestion,
    ): int {
        if ($value < 0) {
            throw new ConfigException(
                message: sprintf('Configuration key "%s" must be zero or a positive integer', $key),
                context: sprintf('Got %s', var_export($value, true)),
                suggestion: $suggestion,
            );
        }

        return $value;
    }

    /**
     * A tolerance of 0 or less would reject every signed webhook as stale.
     *
     * @throws ConfigException
     */
    private function positiveTolerance(
        int $tolerance,
    ): int {
        if ($tolerance <= 0) {
            throw new ConfigException(
                message: 'Configuration key "webhook.timestamp_tolerance" must be a positive integer',
                context: sprintf('Got %s', var_export($tolerance, true)),
                suggestion: 'Set it to how many seconds a received webhook timestamp may differ from the current time (e.g. 300). A value of 0 or less would reject every incoming webhook as stale.',
            );
        }

        return $tolerance;
    }

    /**
     * A timeout of 0 means "wait forever" to the HTTP client, so it is rejected rather than passed through.
     *
     * @throws ConfigException
     */
    private function positiveTimeout(
        int $timeout,
    ): int {
        if ($timeout <= 0) {
            throw new ConfigException(
                message: 'Configuration key "webhook.timeout" must be a positive integer',
                context: sprintf('Got %s', var_export($timeout, true)),
                suggestion: 'Set it to the number of seconds an outgoing webhook request may take before it is abandoned and retried (e.g. 30). A value of 0 would let a receiver that never answers block the queue worker forever.',
            );
        }

        return $timeout;
    }
}
