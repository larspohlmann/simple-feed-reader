<?php

declare(strict_types=1);

namespace App\Tests\Dto\Admin;

use App\Dto\Admin\MailSettingsRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class MailSettingsRequestTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testConstructingWithNoArgumentsDefaultsToDisabledStarttlsOnTheSubmissionPort(): void
    {
        $request = new MailSettingsRequest();

        self::assertFalse($request->enabled);
        self::assertSame('', $request->host);
        self::assertSame(587, $request->port);
        self::assertNull($request->username);
        self::assertSame('starttls', $request->encryption);
        self::assertNull($request->password);
    }

    public function testPortAtTheBoundariesIsValid(): void
    {
        self::assertCount(0, $this->validator->validate(new MailSettingsRequest(port: 1)));
        self::assertCount(0, $this->validator->validate(new MailSettingsRequest(port: 65535)));
    }

    public function testPortOutsideTheBoundariesIsInvalid(): void
    {
        self::assertGreaterThan(0, \count($this->validator->validate(new MailSettingsRequest(port: 0))));
        self::assertGreaterThan(0, \count($this->validator->validate(new MailSettingsRequest(port: 65536))));
    }

    public function testAnUnknownEncryptionIsInvalid(): void
    {
        self::assertGreaterThan(0, \count($this->validator->validate(new MailSettingsRequest(encryption: 'ssl'))));
    }

    /** @return iterable<string, array{\Closure(string): MailSettingsRequest, int}> */
    public static function lengthLimitedFields(): iterable
    {
        yield 'host' => [fn (string $value) => new MailSettingsRequest(host: $value), 255];
        yield 'username' => [fn (string $value) => new MailSettingsRequest(username: $value), 255];
        yield 'fromAddress' => [fn (string $value) => new MailSettingsRequest(fromAddress: $value), 255];
        yield 'fromName' => [fn (string $value) => new MailSettingsRequest(fromName: $value), 255];
        yield 'password' => [fn (string $value) => new MailSettingsRequest(password: $value), 512];
    }

    /** @param \Closure(string): MailSettingsRequest $requestWith */
    #[DataProvider('lengthLimitedFields')]
    public function testAFieldAtItsLengthLimitIsValidButOneOverIsNot(\Closure $requestWith, int $limit): void
    {
        $atLimit = $requestWith(str_repeat('x', $limit));
        $overLimit = $requestWith(str_repeat('x', $limit + 1));

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }
}
