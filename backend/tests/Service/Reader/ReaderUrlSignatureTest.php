<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\Exception\ReaderUrlTokenMismatchException;
use App\Service\Reader\ReaderUrlSignature;
use PHPUnit\Framework\TestCase;

final class ReaderUrlSignatureTest extends TestCase
{
    private const string URL = 'https://news.example/story?id=1';

    public function testATokenIsUnpaddedBase64UrlOfAFullMac(): void
    {
        $token = (new ReaderUrlSignature('secret'))->tokenFor(self::URL);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $token);
    }

    public function testTheTokenOfAUrlVerifies(): void
    {
        $signature = new ReaderUrlSignature('secret');

        $signature->verify(self::URL, $signature->tokenFor(self::URL));

        $this->expectNotToPerformAssertions();
    }

    public function testATokenDoesNotVerifyAnotherUrl(): void
    {
        $signature = new ReaderUrlSignature('secret');

        $this->expectException(ReaderUrlTokenMismatchException::class);
        $signature->verify('https://news.example/story?id=2', $signature->tokenFor(self::URL));
    }

    public function testAnotherSecretsTokenDoesNotVerify(): void
    {
        $this->expectException(ReaderUrlTokenMismatchException::class);
        (new ReaderUrlSignature('secret'))->verify(self::URL, (new ReaderUrlSignature('other'))->tokenFor(self::URL));
    }

    public function testATokenIsNotTheBareMacOfTheSecret(): void
    {
        $bare = rtrim(strtr(base64_encode(hash_hmac('sha256', self::URL, 'secret', true)), '+/', '-_'), '=');

        self::assertNotSame($bare, (new ReaderUrlSignature('secret'))->tokenFor(self::URL));
    }
}
