<?php

declare(strict_types=1);

namespace Marko\Webhook\Tests\Config;

use Marko\Config\Exceptions\ConfigException;
use Marko\Config\Exceptions\ConfigNotFoundException;
use Marko\Testing\Fake\FakeConfigRepository;
use Marko\Webhook\Config\WebhookConfig;

describe('WebhookConfig', function (): void {
    it('reads every webhook config value', function (): void {
        $config = new WebhookConfig(new FakeConfigRepository([
            'webhook.timeout' => 15,
            'webhook.max_retries' => 4,
            'webhook.retry_delay' => 90,
            'webhook.timestamp_tolerance' => 120,
        ]));

        expect($config->timeout)->toBe(15)
            ->and($config->maxRetries)->toBe(4)
            ->and($config->retryDelay)->toBe(90)
            ->and($config->timestampTolerance)->toBe(120);
    });

    it('throws a config exception naming webhook.timeout when the timeout is not positive', function (
        int $timeout,
    ): void {
        $build = fn (): WebhookConfig => new WebhookConfig(new FakeConfigRepository([
            'webhook.timeout' => $timeout,
            'webhook.max_retries' => 3,
            'webhook.retry_delay' => 60,
            'webhook.timestamp_tolerance' => 300,
        ]));

        expect($build)->toThrow(
            ConfigException::class,
            'Configuration key "webhook.timeout" must be a positive integer',
        );
    })->with([0, -1]);

    it('throws ConfigNotFoundException when webhook.timeout is missing', function (): void {
        $build = fn (): WebhookConfig => new WebhookConfig(new FakeConfigRepository([
            'webhook.max_retries' => 3,
            'webhook.retry_delay' => 60,
            'webhook.timestamp_tolerance' => 300,
        ]));

        expect($build)->toThrow(ConfigNotFoundException::class);
    });
});
