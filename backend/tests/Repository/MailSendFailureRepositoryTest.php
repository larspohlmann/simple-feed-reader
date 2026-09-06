<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\MailKind;
use App\Entity\MailSendFailure;
use App\Repository\MailSendFailureRepository;
use App\Tests\DbTestCase;

final class MailSendFailureRepositoryTest extends DbTestCase
{
    private MailSendFailureRepository $failures;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var MailSendFailureRepository $failures */
        $failures = self::getContainer()->get(MailSendFailureRepository::class);
        $this->failures = $failures;
    }

    public function testAddPersistsAndRecentReturnsNewestFirst(): void
    {
        $this->failures->add($this->failure('a@example.test', '2026-09-06T10:00:00Z'));
        $this->failures->add($this->failure('b@example.test', '2026-09-06T11:00:00Z'));

        $recent = $this->failures->recent(10);

        self::assertCount(2, $recent);
        self::assertSame('b@example.test', $recent[0]->getRecipient());
        self::assertSame('a@example.test', $recent[1]->getRecipient());
        self::assertSame(2, $this->failures->countAll());
    }

    public function testDeleteAllClearsTheTable(): void
    {
        $this->failures->add($this->failure('a@example.test', '2026-09-06T10:00:00Z'));

        $this->failures->deleteAll();

        self::assertSame(0, $this->failures->countAll());
        self::assertSame([], $this->failures->recent(10));
    }

    public function testAddPrunesToRetentionNewestFirst(): void
    {
        for ($minute = 0; $minute < MailSendFailureRepository::RETENTION + 5; ++$minute) {
            $stamp = sprintf('2026-09-06T10:%02d:00Z', $minute);
            $this->failures->add($this->failure("user{$minute}@example.test", $stamp));
        }

        self::assertSame(MailSendFailureRepository::RETENTION, $this->failures->countAll());
        // The five oldest were pruned; the newest survives.
        self::assertSame(
            'user' . (MailSendFailureRepository::RETENTION + 4) . '@example.test',
            $this->failures->recent(1)[0]->getRecipient(),
        );
    }

    private function failure(string $recipient, string $createdAt): MailSendFailure
    {
        return new MailSendFailure(
            MailKind::Digest,
            $recipient,
            'SMTP transport failed',
            new \DateTimeImmutable($createdAt),
        );
    }
}
