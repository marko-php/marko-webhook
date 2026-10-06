<?php

declare(strict_types=1);

namespace Marko\Webhook\Tests\Module;

use Marko\Webhook\Contracts\HostResolverInterface;
use Marko\Webhook\Contracts\WebhookDispatcherInterface;
use Marko\Webhook\Contracts\WebhookUrlPolicyInterface;
use Marko\Webhook\Sending\DnsHostResolver;
use Marko\Webhook\Sending\WebhookDispatcher;
use Marko\Webhook\Sending\WebhookUrlPolicy;

describe('Webhook package scaffolding', function (): void {
    it('creates valid package scaffolding with composer.json, module.php, and config', function (): void {
        $basePath = dirname(__DIR__, 2);

        // composer.json exists and is valid
        $composerPath = $basePath . '/composer.json';
        expect(file_exists($composerPath))->toBeTrue();
        $composer = json_decode(file_get_contents($composerPath), true);
        expect($composer)->not->toBeNull()
            ->and($composer['name'])->toBe('marko/webhook')
            ->and($composer['require'])->toHaveKey('marko/http')
            ->and($composer['require'])->toHaveKey('marko/queue')
            ->and($composer['require'])->toHaveKey('marko/config')
            ->and($composer['require'])->toHaveKey('marko/encryption')
            ->and($composer['require'])->not->toHaveKey('marko/cache')
            ->and($composer['suggest'])->toHaveKey('marko/cache');

        // module.php exists and defines bindings
        $modulePath = $basePath . '/module.php';
        expect(file_exists($modulePath))->toBeTrue();
        $module = require $modulePath;
        expect($module)->toBeArray()
            ->and($module)->toHaveKey('bindings')
            ->and($module['bindings'])->toHaveKey(WebhookDispatcherInterface::class)
            ->and($module['bindings'][WebhookDispatcherInterface::class])->toBe(WebhookDispatcher::class)
            ->and($module['bindings'][WebhookUrlPolicyInterface::class])->toBe(WebhookUrlPolicy::class)
            ->and($module['bindings'][HostResolverInterface::class])->toBe(DnsHostResolver::class);

        // config/webhook.php exists with required keys
        $configPath = $basePath . '/config/webhook.php';
        expect(file_exists($configPath))->toBeTrue();
        $config = require $configPath;
        expect($config)->toBeArray()
            ->and($config)->toHaveKey('timeout')
            ->and($config['timeout'])->toBe(30)
            ->and($config)->toHaveKey('max_retries')
            ->and($config['max_retries'])->toBe(3)
            ->and($config)->toHaveKey('retry_delay')
            ->and($config['retry_delay'])->toBe(60)
            ->and($config)->toHaveKey('allow_http')
            ->and($config['allow_http'])->toBeFalse()
            ->and($config['max_body_bytes'])->toBe(1048576)
            ->and($config['replay_protection'])->toBeFalse();
    });
});
