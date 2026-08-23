<?php

declare(strict_types=1);

namespace App\Tests\Dto\Admin;

use App\Dto\Admin\ProxySettingsRequest;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ProxySettingsRequestTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testConstructingWithNoArgumentsDefaultsToDisabledWithFallbackOn(): void
    {
        $request = new ProxySettingsRequest();

        self::assertFalse($request->enabled);
        self::assertTrue($request->directFallback);
        self::assertSame('SOCKS5', $request->type);
        self::assertSame(1080, $request->port);
    }

    public function testPortAtTheBoundariesIsValid(): void
    {
        self::assertCount(0, $this->validator->validate($this->requestWithPort(1)));
        self::assertCount(0, $this->validator->validate($this->requestWithPort(65535)));
    }

    public function testPortOutsideTheBoundariesIsInvalid(): void
    {
        self::assertGreaterThan(0, \count($this->validator->validate($this->requestWithPort(0))));
        self::assertGreaterThan(0, \count($this->validator->validate($this->requestWithPort(65536))));
    }

    public function testHostAtTheLengthLimitIsValidButOneOverIsNot(): void
    {
        $atLimit = new ProxySettingsRequest(host: str_repeat('a', 255));
        $overLimit = new ProxySettingsRequest(host: str_repeat('a', 256));

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }

    public function testUsernameAtTheLengthLimitIsValidButOneOverIsNot(): void
    {
        $atLimit = new ProxySettingsRequest(host: 'proxy.example', username: str_repeat('u', 255));
        $overLimit = new ProxySettingsRequest(host: 'proxy.example', username: str_repeat('u', 256));

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }

    public function testPasswordAtTheLengthLimitIsValidButOneOverIsNot(): void
    {
        $atLimit = new ProxySettingsRequest(host: 'proxy.example', password: str_repeat('p', 512));
        $overLimit = new ProxySettingsRequest(host: 'proxy.example', password: str_repeat('p', 513));

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }

    private function requestWithPort(int $port): ProxySettingsRequest
    {
        return new ProxySettingsRequest(host: 'proxy.example', port: $port);
    }
}
