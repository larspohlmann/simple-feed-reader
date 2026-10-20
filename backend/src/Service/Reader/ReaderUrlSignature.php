<?php

declare(strict_types=1);

namespace App\Service\Reader;

use App\Service\Reader\Exception\ReaderUrlTokenMismatchException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Proves the server handed a URL to the client, so the reader extracts no page the server never showed. */
final readonly class ReaderUrlSignature
{
    private const string KEY_PURPOSE = 'reader-url';

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    public function tokenFor(string $url): string
    {
        $mac = hash_hmac('sha256', $url, hash_hkdf('sha256', $this->secret, 0, self::KEY_PURPOSE), true);

        return rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');
    }

    /** @throws ReaderUrlTokenMismatchException */
    public function verify(string $url, string $token): void
    {
        if (!hash_equals($this->tokenFor($url), $token)) {
            throw new ReaderUrlTokenMismatchException('The reader token does not match its URL.');
        }
    }
}
