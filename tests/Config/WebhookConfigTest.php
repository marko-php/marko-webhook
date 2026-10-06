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
            'webhook.max_body_bytes' => 2048,
            'webhook.replay_protection' => true,
        ]));

        expect($config->timeout)->toBe(15)
            ->and($config->maxRetries)->toBe(4)
            ->and($config->retryDelay)->toBe(90)
            ->and($config->timestampTolerance)->toBe(120)
            ->and($config->maxBodyBytes)->toBe(2048)
            ->and($config->replayProtection)->toBeTrue();
    });

    it('throws a config exception naming webhook.timeout when the timeout is not positive', function (
        int $timeout,
    ): void {
        $build = fn (): WebhookConfig => new WebhookConfig(new FakeConfigRepository([
            'webhook.timeout' => $timeout,
            'webhook.max_retries' => 3,
            'webhook.retry_delay' => 60,
            'webhook.timestamp_tolerance' => 300,
            'webhook.max_body_bytes' => 1048576,
            'webhook.replay_protection' => false,
        ]));

        expect($build)->toThrow(
            ConfigException::class,
            'Configuration key "webhook.timeout" must be a positive integer',
        );
    })->with([0, -1]);

    it('throws a config exception naming the key when a retry or tolerance value is out of range', function (
        string $key,
        int $value,
        string $expected,
    ): void {
        $build = fn (): WebhookConfig => new WebhookConfig(new FakeConfigRepository([
            'webhook.timeout' => 30,
            'webhook.max_retries' => 3,
            'webhook.retry_delay' => 60,
            'webhook.timestamp_tolerance' => 300,
            'webhook.max_body_bytes' => 1048576,
            'webhook.replay_protection' => false,
            $key => $value,
        ]));

        expect($build)->toThrow(ConfigException::class, sprintf('Configuration key "%s" must be %s', $key, $expected));
    })->with([
        'negative max_retries' => ['webhook.max_retries', -1, 'zero or a positive integer'],
        'negative retry_delay' => ['webhook.retry_delay', -1, 'zero or a positive integer'],
        'zero timestamp_tolerance' => ['webhook.timestamp_tolerance', 0, 'a positive integer'],
        'negative timestamp_tolerance' => ['webhook.timestamp_tolerance', -5, 'a positive integer'],
        'zero max_body_bytes' => ['webhook.max_body_bytes', 0, 'a positive integer'],
        'negative max_body_bytes' => ['webhook.max_body_bytes', -1, 'a positive integer'],
    ]);

    it('ships a 1 MiB body cap and replay protection off by default', function (): void {
        $defaults = require dirname(__DIR__, 2) . '/config/webhook.php';

        $config = new WebhookConfig(new FakeConfigRepository([
            'webhook.timeout' => $defaults['timeout'],
            'webhook.max_retries' => $defaults['max_retries'],
            'webhook.retry_delay' => $defaults['retry_delay'],
            'webhook.timestamp_tolerance' => $defaults['timestamp_tolerance'],
            'webhook.max_body_bytes' => $defaults['max_body_bytes'],
            'webhook.replay_protection' => $defaults['replay_protection'],
        ]));

        expect($config->maxBodyBytes)->toBe(1024 * 1024)
            ->and($config->replayProtection)->toBeFalse();
    });

    it('accepts zero retries and a zero retry delay', function (): void {
        $config = new WebhookConfig(new FakeConfigRepository([
            'webhook.timeout' => 30,
            'webhook.max_retries' => 0,
            'webhook.retry_delay' => 0,
            'webhook.timestamp_tolerance' => 1,
            'webhook.max_body_bytes' => 1048576,
            'webhook.replay_protection' => false,
        ]));

        expect($config->maxRetries)->toBe(0)
            ->and($config->retryDelay)->toBe(0)
            ->and($config->timestampTolerance)->toBe(1);
    });

    it('throws ConfigNotFoundException when webhook.timeout is missing', function (): void {
        $build = fn (): WebhookConfig => new WebhookConfig(new FakeConfigRepository([
            'webhook.max_retries' => 3,
            'webhook.retry_delay' => 60,
            'webhook.timestamp_tolerance' => 300,
            'webhook.max_body_bytes' => 1048576,
            'webhook.replay_protection' => false,
        ]));

        expect($build)->toThrow(ConfigNotFoundException::class);
    });
});
