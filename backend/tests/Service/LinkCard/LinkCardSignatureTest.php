<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkCard;

use App\Service\LinkCard\Exception\LinkCardTokenMismatchException;
use App\Service\LinkCard\LinkCardSignature;
use PHPUnit\Framework\TestCase;

final class LinkCardSignatureTest extends TestCase
{
    private const string URL = 'https://news.example/story?id=1';

    public function testATokenIsUnpaddedBase64UrlOfAFullMac(): void
    {
        $token = (new LinkCardSignature('secret'))->tokenFor(self::URL);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
    }

    public function testTheTokenOfAUrlVerifies(): void
    {
        $signature = new LinkCardSignature('secret');

        $signature->verify(self::URL, $signature->tokenFor(self::URL));

        $this->expectNotToPerformAssertions();
    }

    public function testATokenDoesNotVerifyAnotherUrl(): void
    {
        $signature = new LinkCardSignature('secret');

        $this->expectException(LinkCardTokenMismatchException::class);
        $signature->verify('https://news.example/story?id=2', $signature->tokenFor(self::URL));
    }

    public function testAnotherSecretsTokenDoesNotVerify(): void
    {
        $this->expectException(LinkCardTokenMismatchException::class);
        (new LinkCardSignature('secret'))->verify(self::URL, (new LinkCardSignature('other'))->tokenFor(self::URL));
    }

    public function testATokenIsNotTheBareMacOfTheSecret(): void
    {
        $bare = rtrim(strtr(base64_encode(hash_hmac('sha256', self::URL, 'secret', true)), '+/', '-_'), '=');

        self::assertNotSame($bare, (new LinkCardSignature('secret'))->tokenFor(self::URL));
    }
}
