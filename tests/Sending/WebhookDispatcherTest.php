<?php

declare(strict_types=1);

namespace Marko\Webhook\Tests\Sending;

use Marko\Http\Contracts\HttpClientInterface;
use Marko\Http\Exceptions\ConnectionException;
use Marko\Http\HttpResponse;
use Marko\Http\RequestOptions;
use Marko\Testing\Fake\FakeClock;
use Marko\Testing\Fake\FakeConfigRepository;
use Marko\Testing\Fake\FakeHttpClient;
use Marko\Webhook\Config\WebhookConfig;
use Marko\Webhook\Exceptions\InvalidWebhookPayloadException;
use Marko\Webhook\Exceptions\UnsafeWebhookUrlException;
use Marko\Webhook\Sending\WebhookDispatcher;
use Marko\Webhook\Sending\WebhookSignature;
use Marko\Webhook\Sending\WebhookUrlPolicy;
use Marko\Webhook\Tests\Fixtures\FakeHostResolver;
use Marko\Webhook\Tests\Fixtures\RebindingHostResolver;
use Marko\Webhook\Tests\Fixtures\ResolvingHttpClient;
use Marko\Webhook\Value\WebhookPayload;
use Marko\Webhook\Value\WebhookResponse;

describe('WebhookDispatcher', function (): void {
    it('signs the payload with the clock timestamp', function (): void {
        $payload = new WebhookPayload(
            url: 'https://example.com/webhook',
            event: 'order.created',
            data: ['order_id' => 123],
            secret: 'my-signing-secret',
        );

        $jsonBody = json_encode(['event' => $payload->event, 'data' => $payload->data]);

        $capturedUrl = null;
        $capturedOptions = null;

        $httpClient = new class ($capturedUrl, $capturedOptions) implements HttpClientInterface
        {
            public function __construct(
                /** @noinspection PhpPropertyOnlyWrittenInspection - Reference property modifies external variable */
                private ?string &$capturedUrl,
                /** @noinspection PhpPropertyOnlyWrittenInspection - Reference property modifies external variable */
                private ?array &$capturedOptions,
            ) {}

            public function request(
                string $method,
                string $url,
                array $options = [],
            ): HttpResponse {
                return new HttpResponse(200, 'OK');
            }

            public function get(
                string $url,
                array $options = [],
            ): HttpResponse {
                return new HttpResponse(200, 'OK');
            }

            public function post(
                string $url,
                array $options = [],
            ): HttpResponse {
                $this->capturedUrl = $url;
                $this->capturedOptions = $options;

                return new HttpResponse(200, 'OK');
            }

            public function put(
                string $url,
                array $options = [],
            ): HttpResponse {
                return new HttpResponse(200, 'OK');
            }

            public function patch(
                string $url,
                array $options = [],
            ): HttpResponse {
                return new HttpResponse(200, 'OK');
            }

            public function delete(
                string $url,
                array $options = [],
            ): HttpResponse {
                return new HttpResponse(200, 'OK');
            }
        };

        $clock = new FakeClock('2026-01-01 12:00:00 UTC');
        $dispatcher = new WebhookDispatcher(
            $httpClient,
            $clock,
            webhookDispatcherConfig(),
            FakeHostResolver::policy(),
        );
        $response = $dispatcher->dispatch($payload);

        $capturedTimestamp = (int) ($capturedOptions['headers']['X-Webhook-Timestamp'] ?? 0);
        $expectedSignature = WebhookSignature::sign($jsonBody, $payload->secret, $capturedTimestamp, $payload->id);

        expect($response)->toBeInstanceOf(WebhookResponse::class)
            ->and($capturedOptions['headers']['X-Webhook-Id'])->toBe($payload->id)
            ->and($response->statusCode)->toBe(200)
            ->and($response->body)->toBe('OK')
            ->and($response->successful)->toBeTrue()
            ->and($capturedUrl)->toBe($payload->url)
            ->and($capturedTimestamp)->toBe($clock->now()->getTimestamp())
            ->and($capturedOptions['headers']['X-Webhook-Signature'])->toBe($expectedSignature)
            ->and($capturedOptions['headers']['Content-Type'])->toBe('application/json')
            ->and($capturedOptions['body'])->toBe($jsonBody);
    });

    it('sends the webhook with http_errors disabled', function (): void {
        $httpClient = new FakeHttpClient()->stub('https://example.com/webhook', new HttpResponse(200, 'OK'));

        $dispatcher = new WebhookDispatcher(
            $httpClient,
            new FakeClock(),
            webhookDispatcherConfig(),
            FakeHostResolver::policy(),
        );
        $dispatcher->dispatch(webhookDispatcherPayload());

        expect($httpClient->requests[0]->options[RequestOptions::HTTP_ERRORS])->toBeFalse();
    });

    it('sends the configured webhook.timeout as the request timeout', function (): void {
        $httpClient = new FakeHttpClient()->stub('https://example.com/webhook', new HttpResponse(200, 'OK'));

        $dispatcher = new WebhookDispatcher(
            $httpClient,
            new FakeClock(),
            webhookDispatcherConfig(timeout: 7),
            FakeHostResolver::policy(),
        );
        $dispatcher->dispatch(webhookDispatcherPayload());

        expect($httpClient->requests[0]->options[RequestOptions::TIMEOUT])->toBe(7);
    });

    it('returns an unsuccessful response when the receiver answers with a 4xx or 5xx status', function (
        int $status,
    ): void {
        $httpClient = new FakeHttpClient()->stub(
            'https://example.com/webhook',
            new HttpResponse($status, 'Receiver error'),
        );

        $dispatcher = new WebhookDispatcher(
            $httpClient,
            new FakeClock(),
            webhookDispatcherConfig(),
            FakeHostResolver::policy(),
        );
        $response = $dispatcher->dispatch(webhookDispatcherPayload());

        expect($response->successful)->toBeFalse()
            ->and($response->statusCode)->toBe($status)
            ->and($response->body)->toBe('Receiver error');
    })->with([400, 404, 410, 500, 503]);

    it('throws when the receiver cannot be reached', function (): void {
        $httpClient = new FakeHttpClient()->stub(
            'https://example.com/webhook',
            new ConnectionException('Connection refused'),
        );

        $dispatcher = new WebhookDispatcher(
            $httpClient,
            new FakeClock(),
            webhookDispatcherConfig(),
            FakeHostResolver::policy(),
        );

        expect(fn () => $dispatcher->dispatch(webhookDispatcherPayload()))
            ->toThrow(ConnectionException::class, 'Connection refused');
    });

    it('does not follow redirects', function (): void {
        $httpClient = new FakeHttpClient()->stub('https://example.com/webhook', new HttpResponse(200, 'OK'));

        $dispatcher = new WebhookDispatcher(
            $httpClient,
            new FakeClock(),
            webhookDispatcherConfig(),
            FakeHostResolver::policy(),
        );
        $dispatcher->dispatch(webhookDispatcherPayload());

        expect($httpClient->requests[0]->options[RequestOptions::ALLOW_REDIRECTS])->toBeFalse();
    });

    it('pins the connection to the address the URL policy validated', function (): void {
        $httpClient = new FakeHttpClient()->stub('https://example.com/webhook', new HttpResponse(200, 'OK'));

        $dispatcher = new WebhookDispatcher(
            $httpClient,
            new FakeClock(),
            webhookDispatcherConfig(),
            FakeHostResolver::policy(['example.com' => ['93.184.215.14', '93.184.215.15']]),
        );
        $dispatcher->dispatch(webhookDispatcherPayload());

        expect($httpClient->requests[0]->options[RequestOptions::RESOLVE_TO])->toBe('93.184.215.14');
    });

    it('cannot be steered to an internal address by DNS that flips after the policy check', function (): void {
        $resolver = new RebindingHostResolver([['93.184.215.14'], ['169.254.169.254']]);
        $httpClient = new ResolvingHttpClient($resolver);
        $policy = new WebhookUrlPolicy(new FakeConfigRepository(['webhook.allow_http' => false]), $resolver);

        $dispatcher = new WebhookDispatcher($httpClient, new FakeClock(), webhookDispatcherConfig(), $policy);
        $dispatcher->dispatch(webhookDispatcherPayload('https://rebind.attacker.test/hook'));

        expect($httpClient->connectedTo)->toBe(['93.184.215.14'])
            ->and($resolver->lookups)->toBe(['rebind.attacker.test']);
    });

    it('would reach the internal address if the HTTP client resolved the host again', function (): void {
        $resolver = new RebindingHostResolver([['93.184.215.14'], ['169.254.169.254']]);
        $httpClient = new ResolvingHttpClient($resolver);
        $policy = new WebhookUrlPolicy(new FakeConfigRepository(['webhook.allow_http' => false]), $resolver);

        $policy->validate('https://rebind.attacker.test/hook');
        $httpClient->post('https://rebind.attacker.test/hook');

        expect($httpClient->connectedTo)->toBe(['169.254.169.254']);
    });

    it('refuses to send to a URL the policy rejects, before any request is made', function (
        string $url,
        string $reason,
    ): void {
        $httpClient = new FakeHttpClient()->preventStrayRequests(false);
        $policy = FakeHostResolver::policy(['rebind.attacker.test' => ['192.168.0.10']]);

        $dispatcher = new WebhookDispatcher($httpClient, new FakeClock(), webhookDispatcherConfig(), $policy);

        expect(fn () => $dispatcher->dispatch(webhookDispatcherPayload($url)))
            ->toThrow(UnsafeWebhookUrlException::class, $reason)
            ->and($httpClient->requests)->toBe([]);
    })->with([
        'metadata ip' => ['http://169.254.169.254/latest/meta-data/iam/', 'scheme'],
        'metadata ip over https' => ['https://169.254.169.254/latest/meta-data/iam/', 'link-local range'],
        'private ip by hostname' => ['https://rebind.attacker.test/hook', 'a private range (192.168.0.0/16)'],
        'plain http' => ['http://example.com/webhook', 'uses the "http" scheme'],
    ]);

    it('sends the same X-Webhook-Id for every send of the same payload', function (): void {
        $httpClient = new FakeHttpClient()->stub('https://example.com/webhook', new HttpResponse(200, 'OK'));
        $dispatcher = new WebhookDispatcher(
            $httpClient,
            new FakeClock(),
            webhookDispatcherConfig(),
            FakeHostResolver::policy(),
        );
        $payload = webhookDispatcherPayload();

        $dispatcher->dispatch($payload);
        $dispatcher->dispatch($payload);

        expect($httpClient->requests[0]->options[RequestOptions::HEADERS]['X-Webhook-Id'])->toBe($payload->id)
            ->and($httpClient->requests[1]->options[RequestOptions::HEADERS]['X-Webhook-Id'])->toBe($payload->id);
    });

    it('refuses to send data that cannot be encoded as JSON instead of signing an empty body', function (): void {
        $httpClient = new FakeHttpClient()->preventStrayRequests(false);
        $dispatcher = new WebhookDispatcher(
            $httpClient,
            new FakeClock(),
            webhookDispatcherConfig(),
            FakeHostResolver::policy(),
        );
        $payload = new WebhookPayload(
            url: 'https://example.com/webhook',
            event: 'order.created',
            data: ['name' => "invalid \xB1\x31 utf-8"],
            secret: 'my-signing-secret',
        );

        expect(fn () => $dispatcher->dispatch($payload))
            ->toThrow(
                InvalidWebhookPayloadException::class,
                'Webhook payload for event "order.created" cannot be encoded as JSON',
            )
            ->and($httpClient->requests)->toBe([]);
    });
});

function webhookDispatcherPayload(
    string $url = 'https://example.com/webhook',
): WebhookPayload {
    return new WebhookPayload(
        url: $url,
        event: 'order.created',
        data: ['order_id' => 123],
        secret: 'my-signing-secret',
    );
}

function webhookDispatcherConfig(
    int $timeout = 30,
): WebhookConfig {
    return new WebhookConfig(new FakeConfigRepository([
        'webhook.timeout' => $timeout,
        'webhook.max_retries' => 3,
        'webhook.retry_delay' => 60,
        'webhook.timestamp_tolerance' => 300,
        'webhook.max_body_bytes' => 1048576,
        'webhook.replay_protection' => false,
    ]));
}
