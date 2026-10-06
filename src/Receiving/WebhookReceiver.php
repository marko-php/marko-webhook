<?php

declare(strict_types=1);

namespace Marko\Webhook\Receiving;

use Marko\Routing\Http\Request;
use Marko\Webhook\Config\WebhookConfig;
use Marko\Webhook\Contracts\WebhookReceiverInterface;
use Marko\Webhook\Exceptions\InvalidSignatureException;
use Marko\Webhook\Exceptions\InvalidWebhookSecretException;
use Marko\Webhook\WebhookSecret;
use Psr\Clock\ClockInterface;

class WebhookReceiver implements WebhookReceiverInterface
{
    public function __construct(
        private readonly WebhookVerifier $verifier,
        private readonly WebhookConfig $webhookConfig,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidSignatureException|InvalidWebhookSecretException
     */
    public function receive(
        Request $request,
        string $secret,
    ): array {
        WebhookSecret::assertValid($secret);

        $body = $request->body();
        $signature = $request->header('X-Webhook-Signature') ?? '';
        $timestamp = $request->header('X-Webhook-Timestamp');

        if ($timestamp === null) {
            throw InvalidSignatureException::missingTimestamp();
        }

        $ts = (int) $timestamp;
        $now = $this->clock->now()->getTimestamp();

        if (abs($now - $ts) > $this->webhookConfig->timestampTolerance) {
            throw InvalidSignatureException::staleTimestamp($ts, $this->webhookConfig->timestampTolerance, $now);
        }

        if (!$this->verifier->verify(
            $body,
            $timestamp,
            $signature,
            $secret,
            $this->webhookConfig->timestampTolerance,
        )) {
            throw InvalidSignatureException::forRequest();
        }

        return json_decode($body, true) ?? [];
    }
}
