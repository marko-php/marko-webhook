<?php

declare(strict_types=1);

namespace Marko\Webhook\Tests\Receiving;

use Marko\Config\Exceptions\ConfigException;
use Marko\Routing\Http\Request;
use Marko\Testing\Fake\FakeClock;
use Marko\Testing\Fake\FakeConfigRepository;
use Marko\Webhook\Config\WebhookConfig;
use Marko\Webhook\Contracts\WebhookReplayGuardInterface;
use Marko\Webhook\Exceptions\InvalidSignatureException;
use Marko\Webhook\Exceptions\InvalidWebhookPayloadException;
use Marko\Webhook\Exceptions\WebhookPayloadTooLargeException;
use Marko\Webhook\Receiving\WebhookReceiver;
use Marko\Webhook\Receiving\WebhookVerifier;
use Marko\Webhook\Sending\WebhookSignature;

function makeWebhookReceiver(
    int $maxBodyBytes = 1048576,
    bool $replayProtection = false,
    ?WebhookReplayGuardInterface $replayGuard = null,
): WebhookReceiver {
    $config = new WebhookConfig(new FakeConfigRepository([
        'webhook.timeout' => 30,
        'webhook.max_retries' => 3,
        'webhook.retry_delay' => 60,
        'webhook.timestamp_tolerance' => 300,
        'webhook.max_body_bytes' => $maxBodyBytes,
        'webhook.replay_protection' => $replayProtection,
    ]));

    $clock = new FakeClock('@' . webhookReceiverNow());

    return new WebhookReceiver(new WebhookVerifier($clock), $config, $clock, $replayGuard);
}

function webhookReceiverNow(): int
{
    return 1767268800;
}

function signedWebhookRequest(
    string $body,
    string $webhookId = 'delivery-1',
    string $secret = 'my-signing-secret',
): Request {
    $timestamp = webhookReceiverNow();

    return new Request(
        server: [
            'HTTP_X_WEBHOOK_ID' => $webhookId,
            'HTTP_X_WEBHOOK_SIGNATURE' => WebhookSignature::sign($body, $secret, $timestamp, $webhookId),
            'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
        ],
        body: $body,
    );
}

/**
 * In-memory replay guard recording every claim.
 */
function recordingReplayGuard(): WebhookReplayGuardInterface
{
    return new class () implements WebhookReplayGuardInterface
    {
        /** @var array<string, int> */
        public array $claims = [];

        public function claim(
            string $webhookId,
            int $ttl,
        ): bool {
            if (array_key_exists($webhookId, $this->claims)) {
                return false;
            }

            $this->claims[$webhookId] = $ttl;

            return true;
        }
    };
}

describe('WebhookReceiver', function (): void {
    it('throws InvalidSignatureException for failed signature verification', function (): void {
        $receiver = makeWebhookReceiver();
        $timestamp = (string) webhookReceiverNow();
        $request = new Request(
            server: [
                'HTTP_X_WEBHOOK_ID' => 'delivery-1',
                'HTTP_X_WEBHOOK_SIGNATURE' => 'sha256=invalidsignature',
                'HTTP_X_WEBHOOK_TIMESTAMP' => $timestamp,
            ],
        );

        expect(fn () => $receiver->receive($request, 'my-signing-secret'))
            ->toThrow(InvalidSignatureException::class);
    });

    it('parses JSON payloads from incoming webhook request bodies', function (): void {
        $data = ['event' => 'order.created', 'data' => ['order_id' => 123]];

        $result = makeWebhookReceiver()->receive(signedWebhookRequest(json_encode($data)), 'my-signing-secret');

        expect($result)->toBe($data);
    });

    it('rejects a request without an X-Webhook-Id header', function (): void {
        $body = '{"event":"order.created"}';
        $timestamp = webhookReceiverNow();
        $request = new Request(
            server: [
                'HTTP_X_WEBHOOK_SIGNATURE' => WebhookSignature::sign($body, 'my-signing-secret', $timestamp, ''),
                'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
            ],
            body: $body,
        );

        expect(fn () => makeWebhookReceiver()->receive($request, 'my-signing-secret'))
            ->toThrow(InvalidSignatureException::class, 'missing the X-Webhook-Id header');
    });

    it('rejects a signed request whose X-Webhook-Id was changed in transit', function (): void {
        $body = '{"event":"order.created"}';
        $timestamp = webhookReceiverNow();
        $request = new Request(
            server: [
                'HTTP_X_WEBHOOK_ID' => 'delivery-2',
                'HTTP_X_WEBHOOK_SIGNATURE' => WebhookSignature::sign(
                    $body,
                    'my-signing-secret',
                    $timestamp,
                    'delivery-1',
                ),
                'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
            ],
            body: $body,
        );

        expect(fn () => makeWebhookReceiver()->receive($request, 'my-signing-secret'))
            ->toThrow(InvalidSignatureException::class, 'Invalid webhook signature.');
    });

    it('rejects a validly signed scalar JSON body with a clear exception instead of a TypeError', function (
        string $body,
        string $type,
    ): void {
        expect(fn () => makeWebhookReceiver()->receive(signedWebhookRequest($body), 'my-signing-secret'))
            ->toThrow(
                InvalidWebhookPayloadException::class,
                "Webhook request body must decode to a JSON object or array, got $type.",
            );
    })->with([
        'string' => ['"str"', 'string'],
        'integer' => ['1', 'int'],
        'boolean' => ['true', 'bool'],
        'null' => ['null', 'null'],
    ]);

    it('rejects a validly signed body that is not valid JSON', function (
        string $body,
    ): void {
        expect(fn () => makeWebhookReceiver()->receive(signedWebhookRequest($body), 'my-signing-secret'))
            ->toThrow(InvalidWebhookPayloadException::class, 'Webhook request body is not valid JSON');
    })->with([
        'malformed' => ['{"event":'],
        'empty' => [''],
    ]);

    it('rejects a body over webhook.max_body_bytes before verifying its signature', function (): void {
        $body = str_repeat('a', 101);
        $request = new Request(
            server: [
                'HTTP_X_WEBHOOK_ID' => 'delivery-1',
                'HTTP_X_WEBHOOK_SIGNATURE' => 'sha256=not-checked',
                'HTTP_X_WEBHOOK_TIMESTAMP' => (string) webhookReceiverNow(),
            ],
            body: $body,
        );

        expect(fn () => makeWebhookReceiver(maxBodyBytes: 100)->receive($request, 'my-signing-secret'))
            ->toThrow(
                WebhookPayloadTooLargeException::class,
                'Webhook request body is 101 bytes, over the 100-byte limit.',
            );
    });

    it('accepts a body exactly at webhook.max_body_bytes', function (): void {
        $body = '{"event":"order.created"}';

        $result = makeWebhookReceiver(maxBodyBytes: strlen($body))
            ->receive(signedWebhookRequest($body), 'my-signing-secret');

        expect($result)->toBe(['event' => 'order.created']);
    });

    it('rejects a replayed delivery when replay protection is on', function (): void {
        $guard = recordingReplayGuard();
        $receiver = makeWebhookReceiver(replayProtection: true, replayGuard: $guard);
        $request = signedWebhookRequest('{"event":"order.created"}');

        $first = $receiver->receive($request, 'my-signing-secret');

        expect($first)->toBe(['event' => 'order.created'])
            ->and(fn () => $receiver->receive($request, 'my-signing-secret'))
            ->toThrow(InvalidSignatureException::class, 'Webhook delivery "delivery-1" was already received.');
    });

    it('remembers a delivery ID for twice the timestamp tolerance', function (): void {
        $guard = recordingReplayGuard();

        makeWebhookReceiver(replayProtection: true, replayGuard: $guard)
            ->receive(signedWebhookRequest('{"event":"order.created"}'), 'my-signing-secret');

        expect($guard->claims)->toBe(['delivery-1' => 600]);
    });

    it('accepts different delivery IDs when replay protection is on', function (): void {
        $receiver = makeWebhookReceiver(replayProtection: true, replayGuard: recordingReplayGuard());

        $first = $receiver->receive(signedWebhookRequest('{"n":1}', 'delivery-1'), 'my-signing-secret');
        $second = $receiver->receive(signedWebhookRequest('{"n":2}', 'delivery-2'), 'my-signing-secret');

        expect($first)->toBe(['n' => 1])
            ->and($second)->toBe(['n' => 2]);
    });

    it('does not claim the delivery ID of a request that fails verification', function (): void {
        $guard = recordingReplayGuard();
        $receiver = makeWebhookReceiver(replayProtection: true, replayGuard: $guard);
        $forged = signedWebhookRequest('{"event":"order.created"}', secret: 'an-attacker-guessed-secret');

        expect(fn () => $receiver->receive($forged, 'my-signing-secret'))
            ->toThrow(InvalidSignatureException::class)
            ->and($guard->claims)->toBe([]);
    });

    it('accepts the same delivery again when replay protection is off', function (): void {
        $receiver = makeWebhookReceiver();
        $request = signedWebhookRequest('{"event":"order.created"}');

        $receiver->receive($request, 'my-signing-secret');

        expect($receiver->receive($request, 'my-signing-secret'))->toBe(['event' => 'order.created']);
    });

    it('throws a config exception when replay protection is on but no replay guard was given', function (): void {
        $receiver = makeWebhookReceiver(replayProtection: true);

        expect(fn () => $receiver->receive(signedWebhookRequest('{"event":"order.created"}'), 'my-signing-secret'))
            ->toThrow(
                ConfigException::class,
                'Configuration key "webhook.replay_protection" is true, but WebhookReceiver has no replay guard',
            );
    });
});
