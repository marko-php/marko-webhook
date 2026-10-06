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
        $this->maxRetries = $config->getInt('webhook.max_retries');
        $this->retryDelay = $config->getInt('webhook.retry_delay');
        $this->timestampTolerance = $config->getInt('webhook.timestamp_tolerance');
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
