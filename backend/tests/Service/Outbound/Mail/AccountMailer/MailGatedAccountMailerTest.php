<?php

declare(strict_types=1);

namespace App\Tests\Service\Outbound\Mail\AccountMailer;

use App\Entity\User;
use App\Service\Outbound\Mail\AccountMailer\AccountMailerInterface;
use App\Service\Outbound\Mail\AccountMailer\MailGatedAccountMailer;
use App\Service\Outbound\Mail\MailCapability;
use App\Service\Outbound\Mail\MailSendingSettings\MailSendingSettingsInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class MailGatedAccountMailerTest extends TestCase
{
    public function testDelegatesWhenMailEnabled(): void
    {
        $inner = $this->createMock(AccountMailerInterface::class);
        $inner->expects($this->once())->method('sendApproved');

        $gated = new MailGatedAccountMailer($inner, $this->mailCapability(true), new NullLogger());
        $gated->sendApproved(new User('a@b.test', new \DateTimeImmutable()));
    }

    public function testSkipsAndDoesNotDelegateWhenMailDisabled(): void
    {
        $inner = $this->createMock(AccountMailerInterface::class);
        $inner->expects($this->never())->method('sendApproved');
        $inner->expects($this->never())->method('sendPasswordReset');
        $inner->expects($this->never())->method('sendPendingApprovalNotice');
        $inner->expects($this->never())->method('sendVerification');

        $gated = new MailGatedAccountMailer($inner, $this->mailCapability(false), new NullLogger());
        $gated->sendApproved(new User('a@b.test', new \DateTimeImmutable()));
    }

    public function testTheSkipLogLineNamesTheKindAndTheRecipient(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('info')->with(
            'Mail disabled; skipped {kind} mail to {email}.',
            ['kind' => 'approved', 'email' => 'a@b.test'],
        );
        $inner = $this->createStub(AccountMailerInterface::class);

        $gated = new MailGatedAccountMailer($inner, $this->mailCapability(false), $logger);
        $gated->sendApproved(new User('a@b.test', new \DateTimeImmutable()));
    }

    private function mailCapability(bool $enabled): MailCapability
    {
        $settings = $this->createStub(MailSendingSettingsInterface::class);
        $settings->method('isSendingEnabled')->willReturn($enabled);

        return new MailCapability($settings);
    }
}
