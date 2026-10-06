<?php

declare(strict_types=1);

namespace Marko\Webhook\Tests\Value;

use Marko\Encryption\Exceptions\DecryptionException;
use Marko\Webhook\Exceptions\InvalidWebhookSecretException;
use Marko\Webhook\Tests\Fixtures\FakeEncryptor;
use Marko\Webhook\Value\SealedWebhookPayload;
use Marko\Webhook\Value\WebhookPayload;

describe('SealedWebhookPayload', function (): void {
    it('encrypts the signing secret and keeps everything else readable', function (): void {
        $payload = new WebhookPayload(
            url: 'https://example.com/webhook',
            event: 'order.created',
            data: ['order_id' => 42],
            secret: 'whsec-subscriber-secret',
        );

        $sealed = SealedWebhookPayload::seal($payload, new FakeEncryptor());

        expect($sealed->url)->toBe('https://example.com/webhook')
            ->and($sealed->event)->toBe('order.created')
            ->and($sealed->data)->toBe(['order_id' => 42])
            ->and($sealed->id)->toBe($payload->id)
            ->and($sealed->encryptedSecret)->not->toContain('whsec-subscriber-secret')
            ->and(serialize($sealed))->not->toContain('whsec-subscriber-secret');
    });

    it('unseals back to the original payload, delivery ID included', function (): void {
        $payload = new WebhookPayload(
            url: 'https://example.com/webhook',
            event: 'order.created',
            data: ['order_id' => 42],
            secret: 'whsec-subscriber-secret',
        );
        $encryptor = new FakeEncryptor();

        $unsealed = SealedWebhookPayload::seal($payload, $encryptor)->unseal($encryptor);

        expect($unsealed)->toEqual($payload);
    });

    it('refuses to seal a payload whose secret is too short to sign with', function (): void {
        $payload = new WebhookPayload('https://example.com/webhook', 'order.created', [], 'short');

        expect(fn () => SealedWebhookPayload::seal($payload, new FakeEncryptor()))
            ->toThrow(InvalidWebhookSecretException::class);
    });

    it('refuses to decrypt a secret moved into a payload for another URL or delivery', function (
        string $url,
        string $id,
    ): void {
        $payload = new WebhookPayload('https://example.com/webhook', 'order.created', [], 'whsec-subscriber-secret');
        $encryptor = new FakeEncryptor();
        $sealed = SealedWebhookPayload::seal($payload, $encryptor);

        $moved = new SealedWebhookPayload(
            url: $url === '' ? $sealed->url : $url,
            event: $sealed->event,
            data: $sealed->data,
            id: $id === '' ? $sealed->id : $id,
            encryptedSecret: $sealed->encryptedSecret,
        );

        expect(fn () => $moved->unseal($encryptor))->toThrow(DecryptionException::class);
    })->with([
        'other url' => ['https://attacker.example/collect', ''],
        'other delivery' => ['', 'delivery-2'],
    ]);

    it('fails loudly when the secret cannot be decrypted', function (): void {
        $sealed = new SealedWebhookPayload(
            url: 'https://example.com/webhook',
            event: 'order.created',
            data: [],
            id: 'delivery-1',
            encryptedSecret: 'not-encrypted',
        );

        expect(fn () => $sealed->unseal(new FakeEncryptor()))->toThrow(DecryptionException::class);
    });
});
