<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Entity\User;
use App\Http\MeJson;
use App\Http\MeProfileJson;
use App\Service\Mail\MailCapability;
use App\Tests\DbTestCase;
use App\Tests\Support\EnablesMailInTests;

final class MeProfileJsonTest extends DbTestCase
{
    use EnablesMailInTests;

    public function testAnInstanceThatSendsNoMailReportsMailOffAndItsTimezone(): void
    {
        $user = $this->user();

        self::assertSame(
            MeJson::profile($user, false, 'Europe/Berlin'),
            $this->profileJson('Europe/Berlin')->of($user),
        );
    }

    public function testAMailSendingInstanceReportsMailOn(): void
    {
        $this->seedEnabledMailInstance();
        $user = $this->user();

        self::assertSame(MeJson::profile($user, true, 'UTC'), $this->profileJson('UTC')->of($user));
    }

    private function profileJson(string $instanceTimezone): MeProfileJson
    {
        $mail = self::getContainer()->get(MailCapability::class);
        self::assertInstanceOf(MailCapability::class, $mail);

        return new MeProfileJson($mail, $instanceTimezone);
    }

    private function user(): User
    {
        return new User('profile@example.test', new \DateTimeImmutable('2026-08-01T00:00:00Z'));
    }
}
