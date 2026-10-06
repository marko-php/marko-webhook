<?php

declare(strict_types=1);

namespace Marko\Webhook\Exceptions;

use Marko\Core\Exceptions\MarkoException;

class UnsafeWebhookUrlException extends MarkoException
{
    private const string CONTEXT = 'While checking an outgoing webhook URL against the webhook URL policy.';

    public static function malformed(
        string $url,
    ): self {
        return new self(
            message: "Webhook URL \"$url\" is not a valid absolute URL.",
            context: self::CONTEXT,
            suggestion: 'Use an absolute URL with a scheme and host, e.g. https://example.com/webhooks.',
        );
    }

    public static function disallowedScheme(
        string $url,
        string $scheme,
        bool $allowHttp,
    ): self {
        return new self(
            message: "Webhook URL \"$url\" uses the \"$scheme\" scheme, which is not allowed.",
            context: self::CONTEXT,
            suggestion: $allowHttp
                ? 'Use an https:// or http:// URL.'
                : 'Use an https:// URL. Plain http:// is rejected unless webhook.allow_http is set to true.',
        );
    }

    public static function ambiguousNumericHost(
        string $url,
        string $host,
    ): self {
        return new self(
            message: "Webhook URL \"$url\" has the numeric host \"$host\", which is not a dotted-quad IPv4 address.",
            context: self::CONTEXT,
            suggestion: 'Use a hostname or a canonical IP address. Shorthand, octal and hex IPv4 forms are rejected because HTTP clients may read them as internal addresses.',
        );
    }

    public static function unresolvableHost(
        string $url,
        string $host,
    ): self {
        return new self(
            message: "Webhook URL \"$url\" points to the host \"$host\", which does not resolve to an IP address.",
            context: self::CONTEXT,
            suggestion: 'Check the hostname for typos and that it has an A or AAAA DNS record.',
        );
    }

    public static function disallowedAddress(
        string $url,
        string $host,
        string $address,
        string $range,
    ): self {
        $target = $host === $address ? "\"$address\"" : "\"$host\", which resolves to $address,";

        return new self(
            message: "Webhook URL \"$url\" points to $target in $range.",
            context: self::CONTEXT . ' Webhooks may only be sent to public addresses, so a registered URL cannot reach internal services or cloud metadata endpoints.',
            suggestion: 'Use a URL whose host resolves only to public IP addresses. To allow other destinations, bind your own WebhookUrlPolicyInterface implementation.',
        );
    }
}
