<?php

declare(strict_types=1);

namespace App\Tests\Dto\Admin;

use App\Dto\Admin\ProxySettingsRequest;
use App\Entity\ProxyConnection;
use App\Enum\ProxyType;
use App\Service\Crypto\SecretChange;
use App\Tests\Support\SettingsRequests;
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

    public function testThePasswordIntentIsOptionalAndKeepsTheStoredSecret(): void
    {
        $request = new ProxySettingsRequest(
            enabled: true,
            directFallback: false,
            type: 'HTTP',
            host: 'proxy.example',
            port: 3128,
            username: null,
            remoteDns: false,
        );

        self::assertNull($request->password);
        self::assertFalse($request->removePassword);
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
        $atLimit = SettingsRequests::proxy(host: str_repeat('a', 255));
        $overLimit = SettingsRequests::proxy(host: str_repeat('a', 256));

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }

    public function testUsernameAtTheLengthLimitIsValidButOneOverIsNot(): void
    {
        $atLimit = SettingsRequests::proxy(host: 'proxy.example', username: str_repeat('u', 255));
        $overLimit = SettingsRequests::proxy(host: 'proxy.example', username: str_repeat('u', 256));

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }

    public function testPasswordAtTheLengthLimitIsValidButOneOverIsNot(): void
    {
        $atLimit = SettingsRequests::proxy(host: 'proxy.example', password: str_repeat('p', 512));
        $overLimit = SettingsRequests::proxy(host: 'proxy.example', password: str_repeat('p', 513));

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }

    public function testToUpdateCarriesTheConnection(): void
    {
        $update = SettingsRequests::proxy(
            enabled: true,
            directFallback: false,
            type: 'HTTP',
            host: 'proxy.example',
            port: 3128,
            username: 'user',
            remoteDns: true,
        )->toUpdate();

        self::assertEquals(
            new ProxyConnection(true, false, ProxyType::Http, 'proxy.example', 3128, 'user', true),
            $update->connection,
        );
    }

    public function testToUpdateTurnsABlankUsernameIntoNone(): void
    {
        $update = SettingsRequests::proxy(host: 'proxy.example', username: '')->toUpdate();

        self::assertNull($update->connection->username);
    }

    public function testNoPasswordKeepsTheStoredOne(): void
    {
        $update = SettingsRequests::proxy(host: 'proxy.example')->toUpdate();

        self::assertEquals(SecretChange::keep(), $update->password);
    }

    public function testAPasswordReplacesTheStoredOne(): void
    {
        $update = SettingsRequests::proxy(host: 'proxy.example', password: 'sw0rdfish')->toUpdate();

        self::assertEquals(SecretChange::replaceWith('sw0rdfish'), $update->password);
    }

    public function testAnEmptyPasswordStillReplacesTheStoredOne(): void
    {
        $update = SettingsRequests::proxy(host: 'proxy.example', password: '')->toUpdate();

        self::assertEquals(SecretChange::replaceWith(''), $update->password);
    }

    public function testRemovePasswordWinsOverASentPassword(): void
    {
        $update = SettingsRequests::proxy(host: 'proxy.example', password: 'sw0rdfish', removePassword: true)
            ->toUpdate();

        self::assertEquals(SecretChange::remove(), $update->password);
    }

    private function requestWithPort(int $port): ProxySettingsRequest
    {
        return SettingsRequests::proxy(host: 'proxy.example', port: $port);
    }
}
