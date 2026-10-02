<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\User;
use App\Http\ActiveAiJson;
use App\Http\MeJson;
use App\Http\MeProfileJson;
use App\Http\RecommendationCapabilitiesJson;
use App\Service\Mail\MailCapability;
use App\Service\Recommendation\Engine\RecommendationEngineResolver;
use App\Tests\DbTestCase;
use App\Tests\Support\EnablesMailInTests;

final class MeProfileJsonTest extends DbTestCase
{
    use EnablesMailInTests;

    private const array NO_ACTIVE_CONNECTION = ['ready' => false, 'model' => null, 'capabilities' => null];

    public function testAnInstanceThatSendsNoMailReportsMailOffAndItsTimezone(): void
    {
        $user = $this->user();

        self::assertSame(
            [...MeJson::profile($user, false, 'Europe/Berlin'), 'ai' => self::NO_ACTIVE_CONNECTION],
            $this->profileJson('Europe/Berlin')->of($user),
        );
    }

    public function testAMailSendingInstanceReportsMailOn(): void
    {
        $this->seedEnabledMailInstance();
        $user = $this->user();

        self::assertSame(
            [...MeJson::profile($user, true, 'UTC'), 'ai' => self::NO_ACTIVE_CONNECTION],
            $this->profileJson('UTC')->of($user),
        );
    }

    private function profileJson(string $instanceTimezone): MeProfileJson
    {
        $mail = self::getContainer()->get(MailCapability::class);
        self::assertInstanceOf(MailCapability::class, $mail);

        $engines = self::getContainer()->get(RecommendationEngineResolver::class);
        self::assertInstanceOf(RecommendationEngineResolver::class, $engines);

        return new MeProfileJson(
            $mail,
            new ActiveAiJson(new RecommendationCapabilitiesJson($engines)),
            $instanceTimezone,
        );
    }

    private function user(): User
    {
        return new User('profile@example.test', new \DateTimeImmutable('2026-08-01T00:00:00Z'));
    }
}
