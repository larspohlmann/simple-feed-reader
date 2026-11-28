<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\EntryText;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Entity\YourStreamState;
use App\Enum\FeedLanguage;
use App\Repository\EntryMembershipSweepRepository;
use App\Repository\YourStreamMembershipRepository;
use App\Repository\YourStreamSourceRepository;
use App\Repository\YourStreamStateRepository;
use App\Repository\YourStreamWordRepository;
use App\Service\Curation\YourStream\Factory\EntryStemmingFactory;
use App\Service\Curation\YourStream\YourStreamChunkMatcher;
use App\Service\Curation\YourStream\YourStreamScorer;
use App\Service\Curation\YourStream\YourStreamSweep;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Library\Search\Membership\MarkGroupWalker;
use App\Service\Library\Search\Membership\SettledCeiling;
use Psr\Clock\ClockInterface;

/** One account with one settled entry Your stream has not yet walked, and a container sweep on a given clock. */
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
        $this->entityManager->persist(new YourStreamState($user, 0));
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
        $states = $container->get(YourStreamStateRepository::class);
        $words = $container->get(YourStreamWordRepository::class);
        $memberships = $container->get(YourStreamMembershipRepository::class);
        self::assertInstanceOf(EntryMembershipSweepRepository::class, $entries);
        self::assertInstanceOf(YourStreamStateRepository::class, $states);
        self::assertInstanceOf(YourStreamWordRepository::class, $words);
        self::assertInstanceOf(YourStreamMembershipRepository::class, $memberships);

        return new YourStreamSweep(
            $states,
            new YourStreamSourceRepository($this->entityManager),
            $words,
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
