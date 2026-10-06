<?php

declare(strict_types=1);

namespace Marko\Webhook\Tests\Value;

use Marko\Webhook\Exceptions\InvalidWebhookPayloadException;
use Marko\Webhook\Value\WebhookPayload;

describe('WebhookPayload', function (): void {
    it('creates WebhookPayload value object with url, event, data, and secret', function (): void {
        $payload = new WebhookPayload(
            url: 'https://example.com/webhook',
            event: 'order.created',
            data: ['order_id' => 123, 'total' => 99.99],
            secret: 'my-secret',
        );

        expect($payload->url)->toBe('https://example.com/webhook')
            ->and($payload->event)->toBe('order.created')
            ->and($payload->data)->toBe(['order_id' => 123, 'total' => 99.99])
            ->and($payload->secret)->toBe('my-secret');
    });

    it('generates a random UUID v4 delivery ID when none is given', function (): void {
        $first = new WebhookPayload('https://example.com/webhook', 'order.created', [], 'my-signing-secret');
        $second = new WebhookPayload('https://example.com/webhook', 'order.created', [], 'my-signing-secret');

        expect($first->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
            ->and($second->id)->not->toBe($first->id);
    });

    it('keeps a delivery ID that is given', function (): void {
        $payload = new WebhookPayload(
            'https://example.com/webhook',
            'order.created',
            [],
            'my-signing-secret',
            'delivery-1',
        );

        expect($payload->id)->toBe('delivery-1');
    });

    it('rejects an empty delivery ID', function (): void {
        expect(
            fn () => new WebhookPayload('https://example.com/webhook', 'order.created', [], 'my-signing-secret', ''),
        )
            ->toThrow(InvalidWebhookPayloadException::class, 'Webhook payload ID must not be empty.');
    });
});
