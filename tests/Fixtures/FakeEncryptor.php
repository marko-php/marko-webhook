<?php

declare(strict_types=1);

namespace Marko\Webhook\Tests\Fixtures;

use Marko\Encryption\Contracts\EncryptorInterface;
use Marko\Encryption\Exceptions\DecryptionException;

/**
 * Reversible stand-in for a real encryptor: the output never contains the plain text,
 * so tests can assert that a secret does not reach serialized jobs.
 */
class FakeEncryptor implements EncryptorInterface
{
    private const string PREFIX = 'fake-encrypted:';

    public function encrypt(
        string $value,
    ): string {
        return self::PREFIX . base64_encode(strrev($value));
    }

    public function decrypt(
        string $encrypted,
    ): string {
        if (!str_starts_with($encrypted, self::PREFIX)) {
            throw DecryptionException::invalidPayload();
        }

        return strrev((string) base64_decode(substr($encrypted, strlen(self::PREFIX)), true));
    }
}
