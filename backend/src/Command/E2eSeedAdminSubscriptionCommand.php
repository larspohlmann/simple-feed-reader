<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SourceFormat;
use App\Repository\EntryRepository;
use App\Repository\EntryStateRepository;
use App\Repository\FeedRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Gives the seeded e2e admin a visible subscription to a never-fetched fixture feed: without one the reader redirects
 * to onboarding and every Playwright smoke times out. Each step is repaired on its own, so a re-run heals any of them.
 * Runs after app:e2e:seed-admin; refuses under APP_ENV=prod.
 */
#[AsCommand(
    name: 'app:e2e:seed-admin-subscription',
    description: 'Give the e2e admin a visible subscription to the fixture feed (non-prod only).',
)]
final class E2eSeedAdminSubscriptionCommand extends Command
{
    /**
     * A reserved-TLD host that never resolves, so the fixture feed is never
     * fetched even though a new feed row is due immediately. Mirrors the
     * unreachable `.example` hosts the reader smokes already rely on.
     */
    private const string FIXTURE_FEED_URL = 'https://fixtures.sfr-e2e.example/feed.xml';

    /** Long and multi-clause on purpose: the one-line-clip smokes need a magazine kicker that overflows its row. */
    private const string FIXTURE_FEED_TITLE =
        'SFR E2E Fixtures - Das Beste am Norden - Radio - Fernsehen - Nachrichten - Sport - Wetter';

    public function __construct(
        private readonly UserRepository $users,
        private readonly SubscriptionRepository $subscriptions,
        private readonly FeedRepository $feeds,
        private readonly EntryRepository $entries,
        private readonly EntryStateRepository $entryStates,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        #[Autowire('%kernel.environment%')]
        private readonly string $appEnv,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::OPTIONAL, 'Admin email', 'e2e-admin@example.com');
    }

    /**
     * @throws \DateMalformedStringException
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->appEnv) {
            $io->error('app:e2e:seed-admin-subscription is disabled in the prod environment.');

            return Command::FAILURE;
        }

        /** @var string $email */
        $email = $input->getArgument('email');

        $admin = $this->users->findOneByEmail($email);
        if (null === $admin) {
            $io->error(\sprintf('No admin %s to subscribe. Run app:e2e:seed-admin first.', $email));

            return Command::FAILURE;
        }

        $feed = $this->ensureFixtureFeed();
        $entry = $this->ensureSampleEntry($feed);
        $subscription = $this->ensureSubscribed($admin, $feed);
        $this->ensureEntryVisible($admin, $subscription, $entry);

        $io->success(\sprintf('Fixture feed ready and visible for %s.', $email));

        return Command::SUCCESS;
    }

    /**
     * @throws \DateMalformedStringException
     */
    private function ensureFixtureFeed(): Feed
    {
        $feed = $this->feeds->findOneBy(['url' => self::FIXTURE_FEED_URL]);
        if (null !== $feed) {
            return $feed;
        }

        $feed = new Feed(self::FIXTURE_FEED_URL);
        $feed->setTitle(self::FIXTURE_FEED_TITLE);
        $feed->setSourceFormat(SourceFormat::XML);
        // Already fetched, so the reader skips its post-onboarding refresh sweep
        // over a host that never answers.
        $feed->recordSuccessfulFetch($this->clock->now(), $feed->getFetchIntervalMinutes());

        $this->entityManager->persist($feed);
        $this->entityManager->flush(); // assign an id before anything references it

        return $feed;
    }

    /** Checked apart from the feed: a re-run must repair a feed that lost its entry, however that came about. */
    private function ensureSampleEntry(Feed $feed): Entry
    {
        $entry = $this->entries->findOneBy(['feed' => $feed]);
        if (null !== $entry) {
            return $entry;
        }

        $entry = $this->sampleEntry($feed, $this->clock->now());
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry;
    }

    private function ensureSubscribed(User $admin, Feed $feed): Subscription
    {
        $subscription = $this->subscriptions->findOneBy(['user' => $admin, 'feed' => $feed]);
        if (null !== $subscription) {
            return $subscription;
        }

        $subscription = new Subscription($admin, $feed, $this->clock->now());
        $subscription->setPosition($this->subscriptions->nextPositionForUser($admin->requireId()));

        $this->entityManager->persist($subscription);
        $this->entityManager->flush();

        return $subscription;
    }

    /**
     * Clears both things that hide the entry from the default unread view, the subscription's markedReadUntil
     * watermark and the admin's entry_state read mark, whatever a prior run left.
     */
    private function ensureEntryVisible(User $admin, Subscription $subscription, Entry $entry): void
    {
        $needsFlush = false;

        if (null !== $subscription->getMarkedReadUntil()) {
            $subscription->setMarkedReadUntil(null);
            $needsFlush = true;
        }

        $state = $this->entryStates->findOneForUserEntry($admin->requireId(), $entry->requireId());
        if (null !== $state && $state->isHidden()) {
            $state->markUnread();
            $needsFlush = true;
        }

        if ($needsFlush) {
            $this->entityManager->flush();
        }
    }

    private function sampleEntry(Feed $feed, \DateTimeImmutable $createdAt): Entry
    {
        $entry = new Entry(
            $feed,
            'sfr-e2e-fixture-entry-1',
            'https://fixtures.sfr-e2e.example/entry-1',
            'A sample entry for the e2e reader fixture',
            $createdAt,
            $createdAt,
        );
        $entry->setPublishedAt($createdAt);
        $entry->setContentHtml('<p>Fixture entry body; this feed never resolves, the row is seeded for smokes.</p>');

        return $entry;
    }
}
