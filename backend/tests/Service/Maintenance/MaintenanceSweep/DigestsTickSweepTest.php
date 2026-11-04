<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance\MaintenanceSweep;

use App\Service\Mail\Digest\Model\DigestSweepReportModel;
use App\Service\Mail\Digest\DigestComposer;
use App\Service\Mail\Digest\DigestMailer\DigestMailerInterface;
use App\Service\Mail\Digest\DigestRecipients\DigestRecipientsInterface;
use App\Service\Mail\Digest\DigestSchedule;
use App\Service\Mail\Digest\SendDueDigests;
use App\Service\Mail\MailCapability;
use App\Service\Mail\MailSendingSettings\MailSendingSettingsInterface;
use App\Service\Maintenance\MaintenanceSweep\DigestsTickSweep;
use App\Tests\DbTestCase;
use App\Tests\Support\InMemoryMailFailureRecorder;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class DigestsTickSweepTest extends DbTestCase
{
    public function testLooksForDueDigestsEvenAfterTheDeadline(): void
    {
        $recipients = $this->createMock(DigestRecipientsInterface::class);
        $recipients->expects($this->once())->method('findWithDigestEnabled')->willReturn([]);

        $report = $this->sweep($recipients)->run(new \DateTimeImmutable('2000-01-01 00:00:00'));

        self::assertEquals(DigestSweepReportModel::none(), $report);
    }

    public function testAnIdleTickReportsNoDigests(): void
    {
        $recipients = $this->createMock(DigestRecipientsInterface::class);
        $recipients->expects($this->never())->method('findWithDigestEnabled');

        self::assertEquals(DigestSweepReportModel::none(), $this->sweep($recipients)->idleReport());
    }

    private function sweep(DigestRecipientsInterface $recipients): DigestsTickSweep
    {
        $schedule = self::getContainer()->get(DigestSchedule::class);
        self::assertInstanceOf(DigestSchedule::class, $schedule);
        $composer = self::getContainer()->get(DigestComposer::class);
        self::assertInstanceOf(DigestComposer::class, $composer);
        $mailer = self::getContainer()->get(DigestMailerInterface::class);
        self::assertInstanceOf(DigestMailerInterface::class, $mailer);
        $mailSettings = $this->createStub(MailSendingSettingsInterface::class);
        $mailSettings->method('isSendingEnabled')->willReturn(true);

        return new DigestsTickSweep(new SendDueDigests(
            $recipients,
            $schedule,
            $composer,
            $mailer,
            new MailCapability($mailSettings),
            new MockClock('2026-11-04 12:00:00', 'UTC'),
            $this->entityManager,
            new NullLogger(),
            new InMemoryMailFailureRecorder(),
        ));
    }
}
