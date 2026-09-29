<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\MailSendFailure;
use App\Enum\MailKind;
use App\Repository\MailSendFailureRepository;
use App\Repository\RowIds;
use App\Tests\DbTestCase;
use App\Tests\Support\QueryRecorder;
use Doctrine\ORM\QueryBuilder;

final class RowIdsTest extends DbTestCase
{
    public function testSelectedByReadsExactlyTheMatchingRows(): void
    {
        [$a, $b] = $this->store('a@example.test', 'b@example.test');

        $ids = $this->rowIds()->selectedBy($this->idsOf('a@example.test', 'b@example.test'));

        self::assertEqualsCanonicalizing([$a->requireId(), $b->requireId()], $ids);
    }

    public function testDeleteRemovesExactlyTheNamedRows(): void
    {
        $this->store('a@example.test', 'b@example.test', 'c@example.test');
        $doomed = $this->rowIds()->selectedBy($this->idsOf('a@example.test', 'c@example.test'));

        $this->rowIds()->delete(MailSendFailure::class, $doomed);

        $left = array_map(
            static fn (MailSendFailure $failure): string => $failure->getRecipient(),
            $this->failures()->recent(10),
        );
        self::assertSame(['b@example.test'], $left);
    }

    public function testDeletingNoIdsRunsNoStatement(): void
    {
        $recorder = self::getContainer()->get(QueryRecorder::SERVICE_ID);
        self::assertInstanceOf(QueryRecorder::class, $recorder);
        $recorder->reset();

        $this->rowIds()->delete(MailSendFailure::class, []);

        self::assertSame([], $recorder->queriesMatching('delete from mail_send_failure'));
    }

    /** @return list<MailSendFailure> */
    private function store(string ...$recipients): array
    {
        $failures = [];
        foreach ($recipients as $recipient) {
            $failure = new MailSendFailure(
                MailKind::Digest,
                $recipient,
                'SMTP transport failed',
                new \DateTimeImmutable('2026-09-06T10:00:00Z'),
            );
            $this->failures()->add($failure);
            $failures[] = $failure;
        }
        $this->entityManager->flush();

        return $failures;
    }

    private function idsOf(string ...$recipients): QueryBuilder
    {
        return $this->entityManager->createQueryBuilder()
            ->from(MailSendFailure::class, 'f')
            ->andWhere('f.recipient IN (:recipients)')
            ->setParameter('recipients', $recipients);
    }

    private function rowIds(): RowIds
    {
        $rowIds = self::getContainer()->get(RowIds::class);
        self::assertInstanceOf(RowIds::class, $rowIds);

        return $rowIds;
    }

    private function failures(): MailSendFailureRepository
    {
        $failures = self::getContainer()->get(MailSendFailureRepository::class);
        self::assertInstanceOf(MailSendFailureRepository::class, $failures);

        return $failures;
    }
}
