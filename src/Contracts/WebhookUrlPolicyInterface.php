<?php

declare(strict_types=1);

namespace Marko\Webhook\Contracts;

use Marko\Webhook\Exceptions\UnsafeWebhookUrlException;

/**
 * Decides whether an outgoing webhook may be sent to a URL. WebhookDispatcher calls
 * validate() right before every request and pins the connection to the address it
 * returns; call it yourself when a user registers a webhook URL so an unsafe
 * destination is rejected before it is ever stored.
 */
interface WebhookUrlPolicyInterface
{
    /**
     * Return the IP address the request must connect to. The dispatcher pins the
     * connection to it, so the HTTP client never resolves the host a second time
     * (which a DNS rebinding attack could answer with an internal address).
     *
     * @return string An IPv4 or IPv6 address (IPv6 without brackets) that the policy approved
     *
     * @throws UnsafeWebhookUrlException When the URL must not receive webhooks
     */
    public function validate(
        string $url,
    ): string;
}
