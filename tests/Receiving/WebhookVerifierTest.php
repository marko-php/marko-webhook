<?php

declare(strict_types=1);

namespace Marko\Webhook\Tests\Receiving;

use Marko\Testing\Fake\FakeClock;
use Marko\Webhook\Receiving\WebhookVerifier;

describe('WebhookVerifier', function (): void {
    it('verifies incoming webhook signatures using HMAC-SHA256', function (): void {
        $clock = new FakeClock('2026-01-01 12:00:00 UTC');
        $verifier = new WebhookVerifier($clock);
        $body = '{"event":"order.created","data":{"order_id":123}}';
        $secret = 'my-signing-secret';
        $timestamp = (string) $clock->now()->getTimestamp();
        $hash = hash_hmac('sha256', "$timestamp.$body", $secret);
        $signature = 'sha256=' . $hash;

        expect($verifier->verify($body, $timestamp, $signature, $secret, 300))->toBeTrue();
    });

    it('accepts a timestamp exactly at the tolerance boundary', function (): void {
        $clock = new FakeClock('2026-01-01 12:00:00 UTC');
        $verifier = new WebhookVerifier($clock);
        $body = '{"event":"order.created"}';
        $timestamp = (string) ($clock->now()->getTimestamp() - 300);
        $signature = 'sha256=' . hash_hmac('sha256', "$timestamp.$body", 'my-signing-secret');

        expect($verifier->verify($body, $timestamp, $signature, 'my-signing-secret', 300))->toBeTrue();
    });

    it('rejects a timestamp one second past the tolerance', function (): void {
        $clock = new FakeClock('2026-01-01 12:00:00 UTC');
        $verifier = new WebhookVerifier($clock);
        $body = '{"event":"order.created"}';
        $timestamp = (string) $clock->now()->getTimestamp();
        $signature = 'sha256=' . hash_hmac('sha256', "$timestamp.$body", 'my-signing-secret');

        $clock->travel('+301 seconds');

        expect($verifier->verify($body, $timestamp, $signature, 'my-signing-secret', 300))->toBeFalse();
    });
});
