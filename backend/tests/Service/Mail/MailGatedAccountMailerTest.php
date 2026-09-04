<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\User;
use App\Service\Mail\AccountMailerInterface;
use App\Service\Mail\MailCapability;
use App\Service\Mail\MailGatedAccountMailer;
use App\Service\Mail\Settings\MailSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class MailGatedAccountMailerTest extends TestCase
{
    public function testDelegatesWhenMailEnabled(): void
    {
        $inner = $this->createMock(AccountMailerInterface::class);
        $inner->expects(self::once())->method('sendApproved');

        $gated = new MailGatedAccountMailer($inner, $this->mailCapability(true), new NullLogger());
        $gated->sendApproved(new User('a@b.test', new \DateTimeImmutable()));
    }

    public function testSkipsAndDoesNotDelegateWhenMailDisabled(): void
    {
        $inner = $this->createMock(AccountMailerInterface::class);
        $inner->expects(self::never())->method('sendApproved');
        $inner->expects(self::never())->method('sendPasswordReset');
        $inner->expects(self::never())->method('sendPendingApprovalNotice');
        $inner->expects(self::never())->method('sendVerification');

        $gated = new MailGatedAccountMailer($inner, $this->mailCapability(false), new NullLogger());
        $gated->sendApproved(new User('a@b.test', new \DateTimeImmutable()));
    }

    public function testTheSkipLogLineNamesTheKindAndTheRecipient(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            'Mail disabled; skipped {kind} mail to {email}.',
            ['kind' => 'approved', 'email' => 'a@b.test'],
        );
        $inner = $this->createMock(AccountMailerInterface::class);

        $gated = new MailGatedAccountMailer($inner, $this->mailCapability(false), $logger);
        $gated->sendApproved(new User('a@b.test', new \DateTimeImmutable()));
    }

    private function mailCapability(bool $enabled): MailCapability
    {
        $settings = $this->createMock(MailSettings::class);
        $settings->method('isSendingEnabled')->willReturn($enabled);

        return new MailCapability($settings);
    }
}
