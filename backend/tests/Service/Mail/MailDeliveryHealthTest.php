<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\MailKind;
use App\Repository\MailSendFailureRepository;
use App\Service\Mail\MailDeliveryHealth;
use App\Tests\DbTestCase;

final class MailDeliveryHealthTest extends DbTestCase
{
    private MailDeliveryHealth $health;
    private MailSendFailureRepository $failures;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var MailDeliveryHealth $health */
        $health = self::getContainer()->get(MailDeliveryHealth::class);
        $this->health = $health;
        /** @var MailSendFailureRepository $failures */
        $failures = self::getContainer()->get(MailSendFailureRepository::class);
        $this->failures = $failures;
    }

    public function testRecordFailurePersistsARecentFailure(): void
    {
        $this->health->recordFailure(MailKind::Digest, 'reader@example.test', 'SMTP is down');

        $failures = $this->health->recentFailures();

        self::assertCount(1, $failures);
        self::assertSame(MailKind::Digest, $failures[0]->getKind());
        self::assertSame('reader@example.test', $failures[0]->getRecipient());
        self::assertSame('SMTP is down', $failures[0]->getErrorDetail());
    }

    public function testRecordSuccessClearsEveryStoredFailure(): void
    {
        $this->health->recordFailure(MailKind::Digest, 'reader@example.test', 'SMTP is down');
        $this->health->recordFailure(MailKind::Account, 'new@example.test', 'relay refused');

        $this->health->recordSuccess();

        self::assertSame([], $this->health->recentFailures());
        self::assertSame(0, $this->failures->countAll());
    }

    public function testRecordFailureKeepsTheLogWithinRetention(): void
    {
        for ($failure = 0; $failure <= MailSendFailureRepository::RETENTION; ++$failure) {
            $this->health->recordFailure(MailKind::Digest, "reader{$failure}@example.test", 'SMTP is down');
        }

        self::assertSame(MailSendFailureRepository::RETENTION, $this->failures->countAll());
    }
}
