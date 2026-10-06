<?php

declare(strict_types=1);

namespace Marko\Webhook\Tests;

use Marko\Routing\Http\Request;
use Marko\Testing\Fake\FakeClock;
use Marko\Testing\Fake\FakeConfigRepository;
use Marko\Webhook\Config\WebhookConfig;
use Marko\Webhook\Exceptions\InvalidWebhookSecretException;
use Marko\Webhook\Receiving\WebhookReceiver;
use Marko\Webhook\Receiving\WebhookVerifier;
use Marko\Webhook\Sending\WebhookSignature;
use Marko\Webhook\WebhookSecret;

function makeSecretTestReceiver(
    FakeClock $clock,
): WebhookReceiver {
    $config = new WebhookConfig(new FakeConfigRepository([
        'webhook.timeout' => 30,
        'webhook.max_retries' => 3,
        'webhook.retry_delay' => 60,
        'webhook.timestamp_tolerance' => 300,
    ]));

    return new WebhookReceiver(new WebhookVerifier($clock), $config, $clock);
}

describe('webhook secret strength', function (): void {
    it('requires at least 16 bytes', function (): void {
        expect(WebhookSecret::MIN_LENGTH)->toBe(16);
    });

    it('rejects an empty secret on verify instead of accepting a forged signature', function (): void {
        $clock = new FakeClock('2026-01-01 12:00:00 UTC');
        $verifier = new WebhookVerifier($clock);
        $body = '{"event":"payment.succeeded"}';
        $timestamp = (string) $clock->now()->getTimestamp();
        $forged = 'sha256=' . hash_hmac('sha256', "$timestamp.$body", '');

        expect(fn () => $verifier->verify($body, $timestamp, $forged, '', 300))
            ->toThrow(InvalidWebhookSecretException::class, 'Webhook secret is empty');
    });

    it('throws when receiving with an empty secret, even before checking the timestamp', function (): void {
        $clock = new FakeClock('2026-01-01 12:00:00 UTC');
        $body = '{"event":"payment.succeeded"}';
        $timestamp = (string) $clock->now()->getTimestamp();
        $forged = 'sha256=' . hash_hmac('sha256', "$timestamp.$body", '');
        $request = new Request(
            server: [
                'HTTP_X_WEBHOOK_SIGNATURE' => $forged,
                'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
            ],
            body: $body,
        );
        $noTimestamp = new Request(body: $body);

        expect(fn () => makeSecretTestReceiver($clock)->receive($request, ''))
            ->toThrow(InvalidWebhookSecretException::class, 'Webhook secret is empty')
            ->and(fn () => makeSecretTestReceiver($clock)->receive($noTimestamp, ''))
            ->toThrow(InvalidWebhookSecretException::class, 'Webhook secret is empty');
    });

    it('throws when signing with an empty secret', function (): void {
        expect(fn () => WebhookSignature::sign('{"event":"order.created"}', '', time()))
            ->toThrow(InvalidWebhookSecretException::class, 'Webhook secret is empty');
    });

    it('throws when verifying with a secret shorter than the minimum length', function (): void {
        $clock = new FakeClock('2026-01-01 12:00:00 UTC');
        $verifier = new WebhookVerifier($clock);
        $body = '{"event":"order.created"}';
        $timestamp = (string) $clock->now()->getTimestamp();
        $secret = str_repeat('a', WebhookSecret::MIN_LENGTH - 1);
        $signature = 'sha256=' . hash_hmac('sha256', "$timestamp.$body", $secret);

        expect(fn () => $verifier->verify($body, $timestamp, $signature, $secret, 300))
            ->toThrow(InvalidWebhookSecretException::class, 'at least 16 bytes');
    });

    it('throws when signing with a secret shorter than the minimum length', function (): void {
        $secret = str_repeat('a', WebhookSecret::MIN_LENGTH - 1);

        expect(fn () => WebhookSignature::sign('{"event":"order.created"}', $secret, time()))
            ->toThrow(InvalidWebhookSecretException::class, 'at least 16 bytes');
    });

    it('accepts a secret of exactly the minimum length on both sign and verify', function (): void {
        $clock = new FakeClock('2026-01-01 12:00:00 UTC');
        $verifier = new WebhookVerifier($clock);
        $body = '{"event":"order.created"}';
        $timestamp = $clock->now()->getTimestamp();
        $secret = str_repeat('a', WebhookSecret::MIN_LENGTH);

        $signature = WebhookSignature::sign($body, $secret, $timestamp);

        expect($verifier->verify($body, (string) $timestamp, $signature, $secret, 300))->toBeTrue();
    });

    it('accepts a secret longer than the minimum length when receiving', function (): void {
        $clock = new FakeClock('2026-01-01 12:00:00 UTC');
        $body = '{"event":"order.created"}';
        $timestamp = $clock->now()->getTimestamp();
        $secret = 'whsec_a-long-enough-shared-secret';
        $request = new Request(
            server: [
                'HTTP_X_WEBHOOK_SIGNATURE' => WebhookSignature::sign($body, $secret, $timestamp),
                'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
            ],
            body: $body,
        );

        expect(makeSecretTestReceiver($clock)->receive($request, $secret))->toBe(['event' => 'order.created']);
    });
});
