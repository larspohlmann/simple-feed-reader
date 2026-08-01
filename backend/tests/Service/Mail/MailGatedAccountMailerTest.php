<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\User;
use App\Service\Mail\AccountMailerInterface;
use App\Service\Mail\MailCapability;
use App\Service\Mail\MailGatedAccountMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class MailGatedAccountMailerTest extends TestCase
{
    public function testDelegatesWhenMailEnabled(): void
    {
        $inner = $this->createMock(AccountMailerInterface::class);
        $inner->expects(self::once())->method('sendApproved');

        $gated = new MailGatedAccountMailer($inner, new MailCapability(''), new NullLogger());
        $gated->sendApproved(new User('a@b.test', new \DateTimeImmutable()));
    }

    public function testSkipsAndDoesNotDelegateWhenMailDisabled(): void
    {
        $inner = $this->createMock(AccountMailerInterface::class);
        $inner->expects(self::never())->method('sendApproved');
        $inner->expects(self::never())->method('sendPasswordReset');
        $inner->expects(self::never())->method('sendPendingApprovalNotice');
        $inner->expects(self::never())->method('sendVerification');

        $gated = new MailGatedAccountMailer($inner, new MailCapability('1'), new NullLogger());
        $gated->sendApproved(new User('a@b.test', new \DateTimeImmutable()));
    }
}
