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

    public function testRecordFailurePersistsAViewableRow(): void
    {
        $this->health->recordFailure(MailKind::Digest, 'reader@example.test', 'SMTP is down');

        $view = $this->health->view();

        self::assertCount(1, $view['failures']);
        self::assertSame('digest', $view['failures'][0]['kind']);
        self::assertSame('reader@example.test', $view['failures'][0]['recipient']);
        self::assertSame('SMTP is down', $view['failures'][0]['error']);
        self::assertNotEmpty($view['failures'][0]['at']);
    }

    public function testRecordSuccessClearsEveryStoredFailure(): void
    {
        $this->health->recordFailure(MailKind::Digest, 'reader@example.test', 'SMTP is down');
        $this->health->recordFailure(MailKind::Account, 'new@example.test', 'relay refused');

        $this->health->recordSuccess();

        self::assertSame([], $this->health->view()['failures']);
        self::assertSame(0, $this->failures->countAll());
    }
}
