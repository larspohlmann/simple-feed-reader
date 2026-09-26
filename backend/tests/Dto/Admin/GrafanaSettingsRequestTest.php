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
            pyroscopePushUrl: null,
            profilingEnabled: true,
        );

        self::assertNull($request->token);
        self::assertFalse($request->removeToken);
    }

    public function testPyroscopePushUrlOverTheLengthLimitIsRejected(): void
    {
        $overLimit = SettingsRequests::grafana(pyroscopePushUrl: 'http://' . str_repeat('a', 249));

        self::assertGreaterThan(0, \count($this->validator->validate($overLimit)));
    }

    public function testPyroscopePushUrlThatIsNotAUrlIsRejected(): void
    {
        $request = SettingsRequests::grafana(pyroscopePushUrl: 'not a url');

        self::assertGreaterThan(0, \count($this->validator->validate($request)));
    }

    public function testPyroscopePushUrlWithoutATldIsValid(): void
    {
        $request = SettingsRequests::grafana(pyroscopePushUrl: 'http://pyroscope:4040');

        self::assertCount(0, $this->validator->validate($request));
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
}
