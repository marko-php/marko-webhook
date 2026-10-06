<?php

declare(strict_types=1);

namespace Marko\Webhook\Value;

use Marko\Webhook\Exceptions\InvalidWebhookPayloadException;
use Random\RandomException;

readonly class WebhookPayload
{
    /**
     * Unique delivery ID, sent as the X-Webhook-Id header and covered by the signature.
     * Retries of a queued delivery keep the same ID, so receivers can deduplicate them.
     */
    public string $id;

    /**
     * @param array<string, mixed> $data
     * @param string|null $id Delivery ID; a random UUID v4 when omitted
     *
     * @throws InvalidWebhookPayloadException|RandomException
     */
    public function __construct(
        public string $url,
        public string $event,
        public array $data,
        public string $secret,
        ?string $id = null,
    ) {
        if ($id === '') {
            throw InvalidWebhookPayloadException::emptyId();
        }

        $this->id = $id ?? self::uuid();
    }

    /**
     * @throws RandomException
     */
    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
