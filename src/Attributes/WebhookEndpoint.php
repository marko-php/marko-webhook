<?php

declare(strict_types=1);

namespace Marko\Webhook\Attributes;

use Attribute;
use Marko\Webhook\Exceptions\InvalidWebhookSecretException;

/**
 * Marks a routed controller action (or every action of a controller) as a webhook endpoint.
 *
 * WebhookEndpointMiddleware, which marko/webhook registers as global middleware, verifies every
 * request routed to it with WebhookReceiver before the action runs, using the signing secret
 * stored under the config key $secretKey. Unverified requests never reach the action. The
 * attribute does not register a route: pair it with a routing attribute such as #[Post].
 * A method-level attribute wins over a class-level one.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
readonly class WebhookEndpoint
{
    /**
     * @param string $secretKey Config key holding the shared signing secret, e.g. 'webhook.secrets.stripe'
     *
     * @throws InvalidWebhookSecretException
     */
    public function __construct(
        public string $secretKey,
    ) {
        if ($secretKey === '') {
            throw InvalidWebhookSecretException::emptyConfigKey();
        }
    }
}
