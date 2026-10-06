<?php

declare(strict_types=1);

namespace Marko\Webhook\Tests\Sending;

use Marko\Webhook\Sending\WebhookSignature;

describe('WebhookSignature', function (): void {
    it('signs payloads with HMAC-SHA256 over the delivery ID, timestamp and body', function (): void {
        $payload = '{"event":"order.created","data":{"order_id":123}}';
        $secret = 'my-signing-secret';
        $timestamp = time();
        $webhookId = 'delivery-1';

        $signature = WebhookSignature::sign($payload, $secret, $timestamp, $webhookId);

        $expected = 'sha256=' . hash_hmac('sha256', "$webhookId.$timestamp.$payload", $secret);

        expect($signature)->toBe($expected)
            ->and($signature)->toStartWith('sha256=');
    });

    it('produces a different signature for a different delivery ID', function (): void {
        $payload = '{"event":"order.created"}';
        $timestamp = time();

        expect(WebhookSignature::sign($payload, 'my-signing-secret', $timestamp, 'delivery-1'))
            ->not->toBe(WebhookSignature::sign($payload, 'my-signing-secret', $timestamp, 'delivery-2'));
    });
});
