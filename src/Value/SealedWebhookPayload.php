<?php

declare(strict_types=1);

namespace Marko\Webhook\Value;

use Marko\Encryption\Contracts\EncryptorInterface;
use Marko\Encryption\Exceptions\DecryptionException;
use Marko\Encryption\Exceptions\EncryptionException;
use Marko\Webhook\Exceptions\InvalidWebhookPayloadException;
use Marko\Webhook\Exceptions\InvalidWebhookSecretException;
use Marko\Webhook\WebhookSecret;
use Random\RandomException;

/**
 * A WebhookPayload whose signing secret is encrypted, so it can be serialized into a queue
 * backend (database, Redis, RabbitMQ) without exposing the secret to anyone who can read
 * the queue store or its backups. Everything else is stored as is.
 */
readonly class SealedWebhookPayload
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $url,
        public string $event,
        public array $data,
        public string $id,
        public string $encryptedSecret,
    ) {}

    /**
     * @throws InvalidWebhookSecretException|EncryptionException
     */
    public static function seal(
        WebhookPayload $payload,
        EncryptorInterface $encryptor,
    ): self {
        WebhookSecret::assertValid($payload->secret);

        return new self(
            url: $payload->url,
            event: $payload->event,
            data: $payload->data,
            id: $payload->id,
            encryptedSecret: $encryptor->encrypt($payload->secret),
        );
    }

    /**
     * @throws DecryptionException|InvalidWebhookPayloadException|RandomException
     */
    public function unseal(
        EncryptorInterface $encryptor,
    ): WebhookPayload {
        return new WebhookPayload(
            url: $this->url,
            event: $this->event,
            data: $this->data,
            secret: $encryptor->decrypt($this->encryptedSecret),
            id: $this->id,
        );
    }
}
