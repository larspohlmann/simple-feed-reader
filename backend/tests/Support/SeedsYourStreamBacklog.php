<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\EntryText;
use App\Entity\Feed;
use App\Entity\InterestTermSet;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\FeedLanguage;
use App\Repository\EntryMembershipSweepRepository;
use App\Repository\InterestTermRepository;
use App\Repository\InterestTermSetRepository;
use App\Repository\YourStreamMembershipRepository;
use App\Repository\YourStreamSourceRepository;
use App\Service\Curation\YourStream\Factory\EntryStemmingFactory;
use App\Service\Curation\YourStream\YourStreamChunkMatcher;
use App\Service\Curation\YourStream\YourStreamScorer;
use App\Service\Curation\YourStream\YourStreamSweep;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Library\Search\Membership\MarkGroupWalker;
use App\Service\Library\Search\Membership\SettledCeiling;
use Psr\Clock\ClockInterface;

/** One account with one settled entry the For you stream has not yet walked, and a container sweep on a given clock. */
trait SeedsYourStreamBacklog
{
    private function seedOneEntryBacklog(string $email): void
    {
        $user = new User($email, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $user->approve(new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $feed = new Feed('https://example.com/backlog-' . md5($email) . '.xml');
        $this->entityManager->persist($user);
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01')));
        $at = new \DateTimeImmutable('2026-11-01 00:00:00');
        $this->entityManager->persist(new InterestTermSet($user, $at, $at, 0));
        $entry = new Entry(
            $feed,
            'backlog-' . md5($email),
            null,
            'Filler',
            new \DateTimeImmutable('2026-11-27 09:00:00'),
            new \DateTimeImmutable('2026-11-27 08:00:00'),
        );
        $entry->recordText(new EntryText(null, null, FeedLanguage::English));
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }

    private function sweepOnClock(ClockInterface $clock): YourStreamSweep
    {
        $container = self::getContainer();
        $entries = $container->get(EntryMembershipSweepRepository::class);
        $sets = $container->get(InterestTermSetRepository::class);
        $terms = $container->get(InterestTermRepository::class);
        $memberships = $container->get(YourStreamMembershipRepository::class);
        self::assertInstanceOf(EntryMembershipSweepRepository::class, $entries);
        self::assertInstanceOf(InterestTermSetRepository::class, $sets);
        self::assertInstanceOf(InterestTermRepository::class, $terms);
        self::assertInstanceOf(YourStreamMembershipRepository::class, $memberships);

        return new YourStreamSweep(
            $sets,
            new YourStreamSourceRepository($this->entityManager),
            $terms,
            new SettledCeiling($entries, $clock),
            new MarkGroupWalker($entries, $this->entityManager, $clock),
            new EntryStemmingFactory(StemmingWiring::equivalents(), StemmingWiring::stopWordStems()),
            new YourStreamChunkMatcher(
                new YourStreamSourceRepository($this->entityManager),
                new YourStreamScorer(),
            ),
            $memberships,
            new NaiveUtcClock($clock),
            new RecordingLogger(),
        );
    }
}
