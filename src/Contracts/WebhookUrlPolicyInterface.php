<?php

declare(strict_types=1);

namespace Marko\Webhook\Contracts;

use Marko\Webhook\Exceptions\UnsafeWebhookUrlException;

/**
 * Decides whether an outgoing webhook may be sent to a URL. WebhookDispatcher calls
 * validate() right before every request; call it yourself when a user registers a
 * webhook URL so an unsafe destination is rejected before it is ever stored.
 */
interface WebhookUrlPolicyInterface
{
    /**
     * @throws UnsafeWebhookUrlException When the URL must not receive webhooks
     */
    public function validate(
        string $url,
    ): void;
}
