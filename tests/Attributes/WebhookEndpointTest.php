<?php

declare(strict_types=1);

namespace Marko\Webhook\Tests\Attributes;

use Attribute;
use Marko\Webhook\Attributes\WebhookEndpoint;
use Marko\Webhook\Exceptions\InvalidWebhookSecretException;
use ReflectionClass;

describe('WebhookEndpoint', function (): void {
    it('names the config key holding the signing secret and targets methods and classes', function (): void {
        $reflection = new ReflectionClass(WebhookEndpoint::class);
        $attributes = $reflection->getAttributes(Attribute::class);
        $attribute = $attributes[0]->newInstance();

        $endpoint = new WebhookEndpoint(secretKey: 'webhook.secrets.orders');

        expect($endpoint->secretKey)->toBe('webhook.secrets.orders')
            ->and($attribute->flags)->toBe(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD);
    });

    it('rejects an empty config key', function (): void {
        expect(fn () => new WebhookEndpoint(secretKey: ''))
            ->toThrow(InvalidWebhookSecretException::class, '#[WebhookEndpoint] needs the config key');
    });
});
