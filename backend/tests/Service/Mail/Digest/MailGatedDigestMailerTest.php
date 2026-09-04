<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Entity\User;
use App\Service\Mail\Digest\DigestMailerInterface;
use App\Service\Mail\Digest\DigestModel;
use App\Service\Mail\Digest\MailGatedDigestMailer;
use App\Service\Mail\MailCapability;
use App\Service\Mail\Settings\MailSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class MailGatedDigestMailerTest extends TestCase
{
    public function testDelegatesWhenMailEnabled(): void
    {
        $model = new DigestModel([], 0);
        $user = new User('a@b.test', new \DateTimeImmutable());

        $inner = $this->createMock(DigestMailerInterface::class);
        $inner->expects(self::once())->method('send')->with($user, $model);

        $gated = new MailGatedDigestMailer($inner, $this->mailCapability(true), new NullLogger());
        $gated->send($user, $model);
    }

    public function testSkipsAndDoesNotDelegateWhenMailDisabled(): void
    {
        $inner = $this->createMock(DigestMailerInterface::class);
        $inner->expects(self::never())->method('send');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'Mail disabled; skipped digest mail to {email}.',
            ['email' => 'a@b.test'],
        );

        $gated = new MailGatedDigestMailer($inner, $this->mailCapability(false), $logger);
        $gated->send(new User('a@b.test', new \DateTimeImmutable()), new DigestModel([], 0));
    }

    private function mailCapability(bool $enabled): MailCapability
    {
        $settings = $this->createMock(MailSettings::class);
        $settings->method('isSendingEnabled')->willReturn($enabled);

        return new MailCapability($settings);
    }
}
