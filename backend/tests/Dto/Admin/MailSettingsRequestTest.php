<?php

declare(strict_types=1);

namespace App\Tests\Dto\Admin;

use App\Dto\Admin\MailSettingsRequest;
use App\Tests\Support\SettingsRequests;
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

    public function testThePasswordIntentIsOptionalAndKeepsTheStoredSecret(): void
    {
        $request = new MailSettingsRequest(
            enabled: true,
            host: 'smtp.example',
            port: 2525,
            username: 'user',
            encryption: 'tls',
            fromAddress: 'noreply@example.com',
            fromName: 'Example',
            useProxy: false,
        );

        self::assertNull($request->password);
        self::assertFalse($request->removePassword);
    }

    public function testPortAtTheBoundariesIsValid(): void
    {
        self::assertCount(0, $this->validator->validate(SettingsRequests::mail(port: 1)));
        self::assertCount(0, $this->validator->validate(SettingsRequests::mail(port: 65535)));
    }

    public function testPortOutsideTheBoundariesIsInvalid(): void
    {
        self::assertGreaterThan(0, \count($this->validator->validate(SettingsRequests::mail(port: 0))));
        self::assertGreaterThan(0, \count($this->validator->validate(SettingsRequests::mail(port: 65536))));
    }

    public function testAMalformedFromAddressIsInvalidButABlankOneIsNot(): void
    {
        $malformed = SettingsRequests::mail(fromAddress: 'not-an-address');
        $blank = SettingsRequests::mail(fromAddress: '');

        self::assertGreaterThan(0, \count($this->validator->validate($malformed)));
        self::assertCount(0, $this->validator->validate($blank));
    }

    public function testAnUnknownEncryptionIsInvalid(): void
    {
        self::assertGreaterThan(0, \count($this->validator->validate(SettingsRequests::mail(encryption: 'ssl'))));
    }

    /** @return iterable<string, array{\Closure(string): MailSettingsRequest, int}> */
    public static function lengthLimitedFields(): iterable
    {
        yield 'host' => [fn (string $value) => SettingsRequests::mail(host: $value), 255];
        yield 'username' => [fn (string $value) => SettingsRequests::mail(username: $value), 255];
        yield 'fromAddress' => [
            fn (string $value) => SettingsRequests::mail(fromAddress: substr($value, 0, -12) . '@example.com'),
            255,
        ];
        yield 'fromName' => [fn (string $value) => SettingsRequests::mail(fromName: $value), 255];
        yield 'password' => [fn (string $value) => SettingsRequests::mail(password: $value), 512];
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
