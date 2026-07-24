<?php

declare(strict_types=1);

namespace App\Tests\Dto\Subscription;

use App\Dto\Subscription\SubscribeRequest;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SubscribeRequestTest extends KernelTestCase
{
    private function validator(): ValidatorInterface
    {
        self::bootKernel();
        $validator = self::getContainer()->get(ValidatorInterface::class);
        self::assertInstanceOf(ValidatorInterface::class, $validator);

        return $validator;
    }

    public function testBareDomainGetsHttpsScheme(): void
    {
        self::assertSame('https://heise.de', (new SubscribeRequest('heise.de'))->url);
    }

    public function testBareDomainWithPathGetsHttpsScheme(): void
    {
        self::assertSame('https://example.com/feed.xml', (new SubscribeRequest('example.com/feed.xml'))->url);
    }

    public function testExistingHttpSchemeIsPreserved(): void
    {
        self::assertSame('http://example.com/feed', (new SubscribeRequest('http://example.com/feed'))->url);
    }

    public function testExistingHttpsSchemeIsPreserved(): void
    {
        self::assertSame('https://example.com/feed', (new SubscribeRequest('https://example.com/feed'))->url);
    }

    public function testSurroundingWhitespaceIsTrimmedBeforeNormalizing(): void
    {
        self::assertSame('https://heise.de', (new SubscribeRequest('  heise.de  '))->url);
    }

    public function testProtocolRelativeUrlGetsHttpsScheme(): void
    {
        self::assertSame('https://heise.de', (new SubscribeRequest('//heise.de'))->url);
    }

    public function testSchemeMatchingIsCaseInsensitive(): void
    {
        // An uppercase scheme is a real URL and must not be double-prefixed.
        self::assertSame('HTTPS://example.com', (new SubscribeRequest('HTTPS://example.com'))->url);
    }

    public function testEmptyStringIsLeftEmpty(): void
    {
        // NotBlank — not a fabricated "https://" — must be the failure the user sees.
        self::assertSame('', (new SubscribeRequest(''))->url);
    }

    public function testNormalizedBareDomainPassesValidation(): void
    {
        self::assertCount(0, $this->validator()->validate(new SubscribeRequest('heise.de')));
    }

    public function testGarbageInputStillFailsValidation(): void
    {
        // Normalization prepends a scheme; it must not turn nonsense into a valid URL.
        self::assertGreaterThan(0, $this->validator()->validate(new SubscribeRequest('not a url'))->count());
    }

    public function testEmptyInputFailsValidation(): void
    {
        self::assertGreaterThan(0, $this->validator()->validate(new SubscribeRequest(''))->count());
    }
}
