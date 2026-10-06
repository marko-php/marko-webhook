<?php

declare(strict_types=1);

use Marko\Webhook\Contracts\HostResolverInterface;
use Marko\Webhook\Contracts\WebhookDispatcherInterface;
use Marko\Webhook\Contracts\WebhookUrlPolicyInterface;
use Marko\Webhook\Sending\DnsHostResolver;
use Marko\Webhook\Sending\WebhookDispatcher;
use Marko\Webhook\Sending\WebhookUrlPolicy;

return [
    'bindings' => [
        WebhookDispatcherInterface::class => WebhookDispatcher::class,
        WebhookUrlPolicyInterface::class => WebhookUrlPolicy::class,
        HostResolverInterface::class => DnsHostResolver::class,
    ],
];
