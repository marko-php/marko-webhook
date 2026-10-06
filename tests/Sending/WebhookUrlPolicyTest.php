<?php

declare(strict_types=1);

namespace Marko\Webhook\Tests\Sending;

use Marko\Config\Exceptions\ConfigNotFoundException;
use Marko\Testing\Fake\FakeConfigRepository;
use Marko\Webhook\Exceptions\UnsafeWebhookUrlException;
use Marko\Webhook\Sending\WebhookUrlPolicy;
use Marko\Webhook\Tests\Fixtures\FakeHostResolver;

describe('WebhookUrlPolicy', function (): void {
    it('allows an https URL whose host resolves to a public address', function (): void {
        $resolver = new FakeHostResolver(
            ['hooks.example.com' => ['93.184.215.14', '2606:2800:21f:cb07:6820:80da:af6b:8b2c']],
        );
        $policy = new WebhookUrlPolicy(new FakeConfigRepository(['webhook.allow_http' => false]), $resolver);

        $policy->validate('https://hooks.example.com/webhook');

        expect($resolver->lookups)->toBe(['hooks.example.com']);
    });

    it('rejects the cloud metadata address', function (): void {
        expect(fn () => FakeHostResolver::policy()->validate('https://169.254.169.254/latest/meta-data/iam/'))
            ->toThrow(UnsafeWebhookUrlException::class, 'link-local range (169.254.0.0/16)');
    });

    it('rejects a hostname that resolves to the cloud metadata address', function (): void {
        $policy = FakeHostResolver::policy(['metadata.attacker.test' => ['169.254.169.254']]);

        expect(fn () => $policy->validate('https://metadata.attacker.test/'))
            ->toThrow(
                UnsafeWebhookUrlException::class,
                '"metadata.attacker.test", which resolves to 169.254.169.254, in the link-local range',
            );
    });

    it('rejects a hostname that resolves to a private address', function (): void {
        $policy = FakeHostResolver::policy(['intranet.attacker.test' => ['10.0.0.5']]);

        expect(fn () => $policy->validate('https://intranet.attacker.test/'))
            ->toThrow(UnsafeWebhookUrlException::class, 'a private range (10.0.0.0/8)');
    });

    it('rejects a hostname when any of its addresses is internal', function (): void {
        $policy = FakeHostResolver::policy(['mixed.attacker.test' => ['93.184.215.14', '127.0.0.1']]);

        expect(fn () => $policy->validate('https://mixed.attacker.test/'))
            ->toThrow(UnsafeWebhookUrlException::class, 'loopback range');
    });

    it('rejects internal and reserved addresses', function (
        string $address,
        string $range,
    ): void {
        $policy = FakeHostResolver::policy(['internal.test' => [$address]]);

        expect(fn () => $policy->validate('https://internal.test/hook'))
            ->toThrow(UnsafeWebhookUrlException::class, $range);
    })->with([
        'this network' => ['0.0.0.0', '0.0.0.0/8'],
        'rfc1918 10/8' => ['10.1.2.3', '10.0.0.0/8'],
        'rfc1918 172.16/12' => ['172.31.255.254', '172.16.0.0/12'],
        'rfc1918 192.168/16' => ['192.168.1.1', '192.168.0.0/16'],
        'cgnat' => ['100.64.0.1', '100.64.0.0/10'],
        'loopback' => ['127.0.0.1', '127.0.0.0/8'],
        'loopback high' => ['127.255.255.254', '127.0.0.0/8'],
        'link-local' => ['169.254.1.1', '169.254.0.0/16'],
        'multicast' => ['224.0.0.1', '224.0.0.0/4'],
        'broadcast' => ['255.255.255.255', '240.0.0.0/4'],
        'ipv6 loopback' => ['::1', 'IPv6 loopback'],
        'ipv6 unspecified' => ['::', '::/96'],
        'ipv6 ula' => ['fd00:ec2::254', 'unique local range (fc00::/7)'],
        'ipv6 link-local' => ['fe80::1', 'link-local range (fe80::/10)'],
        'ipv6 multicast' => ['ff02::1', 'multicast range (ff00::/8)'],
        'ipv4-mapped metadata' => ['::ffff:169.254.169.254', 'IPv4-mapped form of the link-local range'],
        'ipv4-mapped loopback' => ['::ffff:127.0.0.1', 'IPv4-mapped form of the loopback range'],
        'ipv4-mapped private hex' => ['::ffff:a00:1', 'IPv4-mapped form of a private range (10.0.0.0/8)'],
        'nat64 private' => ['64:ff9b::192.168.0.1', 'NAT64 form of a private range'],
        '6to4 loopback' => ['2002:7f00:1::', '6to4 form of the loopback range'],
    ]);

    it('does not block public addresses that sit next to blocked ranges', function (
        string $address,
    ): void {
        $policy = FakeHostResolver::policy(['edge.test' => [$address]]);

        expect(fn () => $policy->validate('https://edge.test/'))->not->toThrow(UnsafeWebhookUrlException::class);
    })->with([
        '100.63.255.255',
        '100.128.0.0',
        '172.15.255.255',
        '172.32.0.0',
        '169.253.255.255',
        '11.0.0.0',
        '::ffff:93.184.215.14',
        '2606:4700::1111',
    ]);

    it('rejects IP literal hosts in blocked ranges, including bracketed IPv6', function (
        string $url,
    ): void {
        expect(fn () => FakeHostResolver::policy()->validate($url))
            ->toThrow(UnsafeWebhookUrlException::class, 'points to');
    })->with([
        'https://127.0.0.1/',
        'https://[::1]/',
        'https://[::ffff:169.254.169.254]/',
        'https://[fd00::1]:8443/',
        'https://user:pass@10.0.0.1/',
    ]);

    it('rejects numeric hosts that clients read as IPv4 shorthand', function (
        string $url,
    ): void {
        expect(fn () => FakeHostResolver::policy()->validate($url))
            ->toThrow(UnsafeWebhookUrlException::class, 'is not a dotted-quad IPv4 address');
    })->with([
        'https://2130706433/',
        'https://0x7f.1/',
        'https://127.1/',
        'https://0177.0.0.1/',
    ]);

    it('rejects http URLs by default', function (): void {
        expect(fn () => FakeHostResolver::policy()->validate('http://example.com/webhook'))
            ->toThrow(UnsafeWebhookUrlException::class, 'uses the "http" scheme, which is not allowed');
    });

    it('allows http URLs when webhook.allow_http is true', function (): void {
        $resolver = new FakeHostResolver(['example.com' => ['93.184.215.14']]);
        $policy = new WebhookUrlPolicy(new FakeConfigRepository(['webhook.allow_http' => true]), $resolver);

        $policy->validate('http://example.com/webhook');

        expect($resolver->lookups)->toBe(['example.com']);
    });

    it('still rejects internal addresses over http when webhook.allow_http is true', function (): void {
        $policy = FakeHostResolver::policy(allowHttp: true);

        expect(fn () => $policy->validate('http://169.254.169.254/'))
            ->toThrow(UnsafeWebhookUrlException::class, 'link-local range');
    });

    it('rejects schemes other than http and https', function (
        string $url,
    ): void {
        expect(fn () => FakeHostResolver::policy(allowHttp: true)->validate($url))
            ->toThrow(UnsafeWebhookUrlException::class, 'scheme, which is not allowed');
    })->with([
        'file://localhost/etc/passwd',
        'gopher://example.com/',
        'ftp://example.com/',
    ]);

    it('rejects malformed URLs', function (
        string $url,
    ): void {
        expect(fn () => FakeHostResolver::policy()->validate($url))
            ->toThrow(UnsafeWebhookUrlException::class, 'is not a valid absolute URL');
    })->with([
        'not a url',
        '/relative/path',
        'https://',
        'https:///path',
        'https://example.com\@127.0.0.1/',
        'https://exa mple.com/',
    ]);

    it('rejects a host that does not resolve', function (): void {
        expect(fn () => FakeHostResolver::policy()->validate('https://nowhere.invalid/'))
            ->toThrow(UnsafeWebhookUrlException::class, '"nowhere.invalid", which does not resolve');
    });

    it('normalizes host case and a trailing dot before resolving', function (): void {
        $resolver = new FakeHostResolver(['example.com' => ['93.184.215.14']]);
        $policy = new WebhookUrlPolicy(new FakeConfigRepository(['webhook.allow_http' => false]), $resolver);

        $policy->validate('HTTPS://Example.COM./webhook');

        expect($resolver->lookups)->toBe(['example.com']);
    });

    it('throws ConfigNotFoundException when webhook.allow_http is missing', function (): void {
        expect(fn () => new WebhookUrlPolicy(new FakeConfigRepository([]), new FakeHostResolver()))
            ->toThrow(ConfigNotFoundException::class);
    });
});
