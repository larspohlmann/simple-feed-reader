<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Recommendation\Stream;

use App\Entity\InterestTermSet;
use App\Repository\InterestTermSetRepository;
use App\Service\Curation\Recommendation\Stream\InterestTermSweep;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsProfiledAccounts;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubChatClient;

final class InterestTermSweepTest extends DbTestCase
{
    use SeedsProfiledAccounts;
    use SeedsUsers;

    private const string USABLE_REPLY = '{"terms":[{"language":"en","term":"solar","weight":8}]}';

    public function testOneFailingUserDoesNotStopTheOthers(): void
    {
        $this->profiledAccount('terms-sweep-a@example.test');
        $this->profiledAccount('terms-sweep-b@example.test');
        $this->chat()->queueFailure(new \RuntimeException('boom'));
        $this->chat()->queueContent(self::USABLE_REPLY);

        $report = $this->sweep()->refreshDue();

        self::assertSame(
            ['due' => 2, 'stored' => 1, 'failed' => 0, 'raritiesRefreshed' => 0],
            $report->toLogContext(),
        );
        self::assertCount(1, $this->entityManager->getRepository(InterestTermSet::class)->findAll());
    }

    public function testNoUserStartsAfterTheDeadline(): void
    {
        $this->profiledAccount('terms-sweep-late@example.test');

        $report = $this->sweep()->refreshDueBefore(new \DateTimeImmutable('2000-01-01 00:00:00'));

        self::assertSame(
            ['due' => 1, 'stored' => 0, 'failed' => 0, 'raritiesRefreshed' => 0],
            $report->toLogContext(),
        );
        self::assertSame([], $this->chat()->calls());
    }

    public function testEveryDueUserStartsBeforeTheDeadline(): void
    {
        $this->profiledAccount('terms-sweep-early@example.test');
        $this->chat()->queueContent(self::USABLE_REPLY);

        $report = $this->sweep()->refreshDueBefore(new \DateTimeImmutable('+1 hour'));

        self::assertSame(1, $report->stored);
        $sets = self::getContainer()->get(InterestTermSetRepository::class);
        self::assertInstanceOf(InterestTermSetRepository::class, $sets);
        self::assertCount(1, $sets->findAll());
    }

    private function sweep(): InterestTermSweep
    {
        $sweep = self::getContainer()->get(InterestTermSweep::class);
        self::assertInstanceOf(InterestTermSweep::class, $sweep);

        return $sweep;
    }

    private function chat(): StubChatClient
    {
        $chat = self::getContainer()->get(StubChatClient::class);
        self::assertInstanceOf(StubChatClient::class, $chat);

        return $chat;
    }
}
