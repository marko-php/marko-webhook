<?php

declare(strict_types=1);

namespace Marko\Webhook\Tests\Fixtures;

use Marko\Http\Contracts\HttpClientInterface;
use Marko\Http\HttpResponse;
use Marko\Http\RequestOptions;
use Marko\Webhook\Contracts\HostResolverInterface;

/**
 * An HTTP client that behaves like a real one when choosing where to connect: it uses the
 * pinned 'resolve_to' address when given, and otherwise resolves the URL's host itself.
 * Records the address of every connection it would have opened.
 */
class ResolvingHttpClient implements HttpClientInterface
{
    /** @var list<string> */
    public private(set) array $connectedTo = [];

    public function __construct(
        private readonly HostResolverInterface $resolver,
    ) {}

    public function request(
        string $method,
        string $url,
        array $options = [],
    ): HttpResponse {
        RequestOptions::validate($options);

        $address = $options[RequestOptions::RESOLVE_TO]
            ?? $this->resolver->resolve((string) parse_url($url, PHP_URL_HOST))[0];

        $this->connectedTo[] = (string) $address;

        return new HttpResponse(200, 'OK');
    }

    public function get(
        string $url,
        array $options = [],
    ): HttpResponse {
        return $this->request('GET', $url, $options);
    }

    public function post(
        string $url,
        array $options = [],
    ): HttpResponse {
        return $this->request('POST', $url, $options);
    }

    public function put(
        string $url,
        array $options = [],
    ): HttpResponse {
        return $this->request('PUT', $url, $options);
    }

    public function patch(
        string $url,
        array $options = [],
    ): HttpResponse {
        return $this->request('PATCH', $url, $options);
    }

    public function delete(
        string $url,
        array $options = [],
    ): HttpResponse {
        return $this->request('DELETE', $url, $options);
    }
}
