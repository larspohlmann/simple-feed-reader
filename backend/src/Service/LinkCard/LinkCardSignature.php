<?php

declare(strict_types=1);

namespace App\Service\LinkCard;

use App\Service\LinkCard\Exception\LinkCardTokenMismatchException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Proves a URL reached the client as a link card, so the reader extracts no page the server never showed. */
final readonly class LinkCardSignature
{
    private const string KEY_PURPOSE = 'link-card-reader';

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

    /** @throws LinkCardTokenMismatchException */
    public function verify(string $url, string $token): void
    {
        if (!hash_equals($this->tokenFor($url), $token)) {
            throw new LinkCardTokenMismatchException('The link card token does not match its URL.');
        }
    }
}
