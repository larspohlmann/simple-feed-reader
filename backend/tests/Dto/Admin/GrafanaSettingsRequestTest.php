<?php

declare(strict_types=1);

namespace App\Tests\Dto\Admin;

use App\Dto\Admin\GrafanaSettingsRequest;
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

    public function testConstructingWithNoArgumentsKeepsEverythingUnset(): void
    {
        $request = new GrafanaSettingsRequest();

        self::assertNull($request->lokiPushUrl);
        self::assertNull($request->lokiUsername);
        self::assertNull($request->grafanaUrl);
        self::assertNull($request->token);
        self::assertFalse($request->removeToken);
    }

    public function testLokiPushUrlAtTheLengthLimitIsValidButOneOverIsNot(): void
    {
        $atLimit = new GrafanaSettingsRequest(lokiPushUrl: 'http://' . str_repeat('a', 248));
        $overLimit = new GrafanaSettingsRequest(lokiPushUrl: 'http://' . str_repeat('a', 249));

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }

    public function testLokiPushUrlWithoutATldIsValid(): void
    {
        $request = new GrafanaSettingsRequest(lokiPushUrl: 'http://loki:3100/loki/api/v1/push');

        self::assertCount(0, $this->validator->validate($request));
    }

    public function testGrafanaUrlAtTheLengthLimitIsValidButOneOverIsNot(): void
    {
        $atLimit = new GrafanaSettingsRequest(grafanaUrl: 'http://' . str_repeat('a', 248));
        $overLimit = new GrafanaSettingsRequest(grafanaUrl: 'http://' . str_repeat('a', 249));

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }

    public function testGrafanaUrlWithoutATldIsValid(): void
    {
        $request = new GrafanaSettingsRequest(grafanaUrl: 'http://localhost:3000');

        self::assertCount(0, $this->validator->validate($request));
    }

    public function testLokiUsernameAtTheLengthLimitIsValidButOneOverIsNot(): void
    {
        $atLimit = new GrafanaSettingsRequest(lokiUsername: str_repeat('u', 255));
        $overLimit = new GrafanaSettingsRequest(lokiUsername: str_repeat('u', 256));

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }

    public function testTokenAtTheLengthLimitIsValidButOneOverIsNot(): void
    {
        $atLimit = new GrafanaSettingsRequest(token: str_repeat('t', 512));
        $overLimit = new GrafanaSettingsRequest(token: str_repeat('t', 513));

        self::assertCount(0, $this->validator->validate($atLimit));
        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }
}
