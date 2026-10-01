<?php

declare(strict_types=1);

namespace App\Tests\Dto\Admin;

use App\Dto\Admin\GrafanaSettingsRequest;
use App\Tests\Support\SettingsRequests;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class GrafanaSettingsRequestTest extends TestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public function testTheTokenIntentIsOptionalAndKeepsTheStoredSecret(): void
    {
        $request = new GrafanaSettingsRequest(
            lokiPushUrl: null,
            lokiUsername: null,
            grafanaUrl: 'https://grafana.example',
        );

        self::assertNull($request->token);
        self::assertFalse($request->removeToken);
    }

    public function testLokiPushUrlAtTheLengthLimitIsValidButOneOverIsNot(): void
    {
        $atLimit = SettingsRequests::grafana(lokiPushUrl: 'http://' . str_repeat('a', 248));
        $overLimit = SettingsRequests::grafana(lokiPushUrl: 'http://' . str_repeat('a', 249));

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }

    public function testLokiPushUrlWithoutATldIsValid(): void
    {
        $request = SettingsRequests::grafana(lokiPushUrl: 'http://loki:3100/loki/api/v1/push');

        self::assertCount(0, $this->validator->validate($request));
    }

    public function testGrafanaUrlAtTheLengthLimitIsValidButOneOverIsNot(): void
    {
        $atLimit = SettingsRequests::grafana(grafanaUrl: 'http://' . str_repeat('a', 248));
        $overLimit = SettingsRequests::grafana(grafanaUrl: 'http://' . str_repeat('a', 249));

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }

    public function testGrafanaUrlWithoutATldIsValid(): void
    {
        $request = SettingsRequests::grafana(grafanaUrl: 'http://localhost:3000');

        self::assertCount(0, $this->validator->validate($request));
    }

    public function testLokiUsernameAtTheLengthLimitIsValidButOneOverIsNot(): void
    {
        $atLimit = SettingsRequests::grafana(lokiUsername: str_repeat('u', 255));
        $overLimit = SettingsRequests::grafana(lokiUsername: str_repeat('u', 256));

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }

    public function testTokenAtTheLengthLimitIsValidButOneOverIsNot(): void
    {
        $atLimit = SettingsRequests::grafana(token: str_repeat('t', 512));
        $overLimit = SettingsRequests::grafana(token: str_repeat('t', 513));

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }

    public function testToUpdateCarriesTheOverrides(): void
    {
        $update = SettingsRequests::grafana(
            lokiPushUrl: 'http://loki:3100/push',
            lokiUsername: 'tenant42',
            grafanaUrl: 'http://grafana:3000',
        )->toUpdate();

        self::assertSame('http://loki:3100/push', $update->connection->lokiPushUrl);
        self::assertSame('tenant42', $update->connection->lokiUsername);
        self::assertSame('http://grafana:3000', $update->connection->grafanaUrl);
    }

    public function testToUpdateTurnsBlankOverridesIntoNone(): void
    {
        $update = SettingsRequests::grafana(lokiPushUrl: '', lokiUsername: '', grafanaUrl: '')->toUpdate();

        self::assertNull($update->connection->lokiPushUrl);
        self::assertNull($update->connection->lokiUsername);
        self::assertNull($update->connection->grafanaUrl);
    }

    public function testABlankOrMissingTokenKeepsTheStoredOne(): void
    {
        $fromBlank = SettingsRequests::grafana(token: '')->toUpdate()->token;
        self::assertNull($fromBlank->replacement());
        self::assertFalse($fromBlank->isRemoval());

        $fromMissing = SettingsRequests::grafana()->toUpdate()->token;
        self::assertNull($fromMissing->replacement());
        self::assertFalse($fromMissing->isRemoval());
    }

    public function testATokenReplacesTheStoredOne(): void
    {
        $update = SettingsRequests::grafana(token: 'glc_new')->toUpdate();

        self::assertSame('glc_new', $update->token->replacement());
        self::assertFalse($update->token->isRemoval());
    }

    public function testRemoveTokenWinsOverASentToken(): void
    {
        $update = SettingsRequests::grafana(token: 'glc_new', removeToken: true)->toUpdate();

        self::assertNull($update->token->replacement());
        self::assertTrue($update->token->isRemoval());
    }
}
