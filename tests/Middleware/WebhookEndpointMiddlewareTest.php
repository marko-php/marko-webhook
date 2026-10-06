<?php

declare(strict_types=1);

namespace Marko\Webhook\Tests\Middleware;

use Marko\Config\ConfigRepositoryInterface;
use Marko\Config\Exceptions\ConfigNotFoundException;
use Marko\Core\Container\Container;
use Marko\Routing\Http\Request;
use Marko\Routing\Http\Response;
use Marko\Routing\RouteCollection;
use Marko\Routing\RouteDefinition;
use Marko\Routing\RouteMatcher;
use Marko\Routing\Router;
use Marko\Testing\Fake\FakeClock;
use Marko\Testing\Fake\FakeConfigRepository;
use Marko\Webhook\Attributes\WebhookEndpoint;
use Marko\Webhook\Config\WebhookConfig;
use Marko\Webhook\Contracts\WebhookReceiverInterface;
use Marko\Webhook\Contracts\WebhookReplayGuardInterface;
use Marko\Webhook\Middleware\WebhookEndpointMiddleware;
use Marko\Webhook\Receiving\WebhookReceiver;
use Marko\Webhook\Receiving\WebhookVerifier;
use Marko\Webhook\Sending\WebhookSignature;

class OrdersWebhookController
{
    /** @noinspection PhpUnused - Invoked via the router */
    #[WebhookEndpoint(secretKey: 'webhook.secrets.orders')]
    public function receive(
        Request $request,
    ): Response {
        return new Response(body: 'processed ' . $request->json('event'));
    }

    /** @noinspection PhpUnused - Invoked via the router */
    #[WebhookEndpoint(secretKey: 'webhook.secrets.missing')]
    public function unconfigured(): Response
    {
        return new Response(body: 'processed');
    }

    /** @noinspection PhpUnused - Invoked via the router */
    public function status(): Response
    {
        return new Response(body: 'ok');
    }
}

#[WebhookEndpoint(secretKey: 'webhook.secrets.orders')]
class ClassLevelWebhookController
{
    /** @noinspection PhpUnused - Invoked via the router */
    public function receive(): Response
    {
        return new Response(body: 'processed');
    }
}

/**
 * A Preference that overrides the webhook action without repeating the attribute.
 */
class PreferredOrdersWebhookController extends OrdersWebhookController
{
    public function receive(
        Request $request,
    ): Response {
        return new Response(body: 'preferred');
    }
}

function webhookEndpointNow(): int
{
    return 1767268800;
}

function webhookEndpointRouter(
    ?WebhookReplayGuardInterface $replayGuard = null,
): Router {
    $values = [
        'webhook.timeout' => 30,
        'webhook.max_retries' => 3,
        'webhook.retry_delay' => 60,
        'webhook.timestamp_tolerance' => 300,
        'webhook.max_body_bytes' => 1024,
        'webhook.replay_protection' => $replayGuard !== null,
        'webhook.secrets.orders' => 'orders-signing-secret',
    ];
    $config = new FakeConfigRepository($values);
    $clock = new FakeClock('@' . webhookEndpointNow());

    $container = new Container();
    $container->instance(ConfigRepositoryInterface::class, $config);
    $container->instance(
        WebhookReceiverInterface::class,
        new WebhookReceiver(new WebhookVerifier($clock), new WebhookConfig($config), $clock, $replayGuard),
    );

    $routes = new RouteCollection();
    $routes->add(new RouteDefinition('POST', '/webhooks/orders', OrdersWebhookController::class, 'receive'));
    $routes->add(
        new RouteDefinition('POST', '/webhooks/unconfigured', OrdersWebhookController::class, 'unconfigured'),
    );
    $routes->add(new RouteDefinition('POST', '/status', OrdersWebhookController::class, 'status'));
    $routes->add(new RouteDefinition('POST', '/webhooks/class', ClassLevelWebhookController::class, 'receive'));
    $routes->add(
        new RouteDefinition('POST', '/webhooks/preferred', PreferredOrdersWebhookController::class, 'receive'),
    );

    return new Router(
        matcher: new RouteMatcher($routes),
        container: $container,
        globalMiddleware: [WebhookEndpointMiddleware::class],
    );
}

/**
 * @param array<string, string> $headers
 */
function webhookEndpointRequest(
    string $path,
    string $body,
    array $headers,
): Request {
    return new Request(
        server: [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => $path,
            'CONTENT_TYPE' => 'application/json',
            ...$headers,
        ],
        body: $body,
    );
}

function signedWebhookEndpointRequest(
    string $path,
    string $body = '{"event":"order.created"}',
    string $secret = 'orders-signing-secret',
    string $webhookId = 'delivery-1',
): Request {
    $timestamp = webhookEndpointNow();

    return webhookEndpointRequest($path, $body, [
        'HTTP_X_WEBHOOK_ID' => $webhookId,
        'HTTP_X_WEBHOOK_SIGNATURE' => WebhookSignature::sign($body, $secret, $timestamp, $webhookId),
        'HTTP_X_WEBHOOK_TIMESTAMP' => (string) $timestamp,
    ]);
}

describe('WebhookEndpointMiddleware', function (): void {
    it('runs a #[WebhookEndpoint] action for a request signed with the secret from its config key', function (): void {
        $response = webhookEndpointRouter()->handle(signedWebhookEndpointRequest('/webhooks/orders'));

        expect($response->statusCode())->toBe(200)
            ->and($response->body())->toBe('processed order.created');
    });

    it('answers 401 without running the action for an unsigned or forged request', function (
        Request $request,
    ): void {
        $response = webhookEndpointRouter()->handle($request);

        expect($response->statusCode())->toBe(401)
            ->and($response->body())->toContain('Invalid webhook signature')
            ->not->toContain('processed');
    })->with([
        'unsigned' => fn (): Request => webhookEndpointRequest('/webhooks/orders', '{"event":"order.created"}', []),
        'wrong secret' => fn (): Request => signedWebhookEndpointRequest(
            '/webhooks/orders',
            secret: 'an-attacker-guessed-secret',
        ),
        'missing delivery id' => fn (): Request => webhookEndpointRequest(
            '/webhooks/orders',
            '{"event":"order.created"}',
            [
                'HTTP_X_WEBHOOK_SIGNATURE' => WebhookSignature::sign(
                    '{"event":"order.created"}',
                    'orders-signing-secret',
                    webhookEndpointNow(),
                    'delivery-1',
                ),
                'HTTP_X_WEBHOOK_TIMESTAMP' => (string) webhookEndpointNow(),
            ],
        ),
    ]);

    it('answers 413 for a body over webhook.max_body_bytes', function (): void {
        $body = json_encode(['event' => str_repeat('a', 2000)]);

        $response = webhookEndpointRouter()->handle(signedWebhookEndpointRequest('/webhooks/orders', $body));

        expect($response->statusCode())->toBe(413)
            ->and($response->body())->not->toContain('processed');
    });

    it('answers 400 for a validly signed body that is not a JSON object or array', function (): void {
        $response = webhookEndpointRouter()->handle(signedWebhookEndpointRequest('/webhooks/orders', '"str"'));

        expect($response->statusCode())->toBe(400)
            ->and($response->body())->toContain('Invalid webhook payload');
    });

    it('answers 401 for a replayed delivery when replay protection is on', function (): void {
        $guard = new class () implements WebhookReplayGuardInterface
        {
            /** @var array<string, true> */
            private array $seen = [];

            public function claim(
                string $webhookId,
                int $ttl,
            ): bool {
                if (isset($this->seen[$webhookId])) {
                    return false;
                }

                $this->seen[$webhookId] = true;

                return true;
            }
        };
        $router = webhookEndpointRouter($guard);

        $first = $router->handle(signedWebhookEndpointRequest('/webhooks/orders'));
        $replay = $router->handle(signedWebhookEndpointRequest('/webhooks/orders'));

        expect($first->statusCode())->toBe(200)
            ->and($replay->statusCode())->toBe(401)
            ->and($replay->body())->not->toContain('processed');
    });

    it('lets requests to actions without #[WebhookEndpoint] through unverified', function (): void {
        $response = webhookEndpointRouter()->handle(webhookEndpointRequest('/status', '', []));

        expect($response->statusCode())->toBe(200)
            ->and($response->body())->toBe('ok');
    });

    it('verifies every action of a controller marked with a class-level #[WebhookEndpoint]', function (): void {
        $router = webhookEndpointRouter();

        $unsigned = $router->handle(webhookEndpointRequest('/webhooks/class', '{"event":"order.created"}', []));
        $signed = $router->handle(signedWebhookEndpointRequest('/webhooks/class'));

        expect($unsigned->statusCode())->toBe(401)
            ->and($signed->statusCode())->toBe(200);
    });

    it('keeps verifying an action a Preference overrides without repeating the attribute', function (): void {
        $router = webhookEndpointRouter();

        $unsigned = $router->handle(webhookEndpointRequest('/webhooks/preferred', '{"event":"order.created"}', []));
        $signed = $router->handle(signedWebhookEndpointRequest('/webhooks/preferred'));

        expect($unsigned->statusCode())->toBe(401)
            ->and($signed->body())->toBe('preferred');
    });

    it('throws instead of answering when the secret config key is not set', function (): void {
        $router = webhookEndpointRouter();

        expect(fn () => $router->handle(signedWebhookEndpointRequest('/webhooks/unconfigured')))
            ->toThrow(ConfigNotFoundException::class);
    });
});
