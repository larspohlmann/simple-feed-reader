<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Exception\ReaderUrlTokenMismatchException;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Proves the server handed a URL to the client, so the reader extracts no page the server never showed. */
final readonly class ReaderUrlSignature
{
    private const string KEY_PURPOSE = 'reader-url';

    private string $key;

    public function __construct(#[Autowire('%kernel.secret%')] string $secret)
    {
        $this->key = hash_hkdf('sha256', $secret, 0, self::KEY_PURPOSE);
    }

    public function tokenFor(string $url): string
    {
        return Base64UrlSafe::encodeUnpadded(hash_hmac('sha256', $url, $this->key, true));
    }

    /** @throws ReaderUrlTokenMismatchException */
    public function verify(string $url, string $token): void
    {
        if (!hash_equals($this->tokenFor($url), $token)) {
            throw new ReaderUrlTokenMismatchException('The reader token does not match its URL.');
        }
    }
}
