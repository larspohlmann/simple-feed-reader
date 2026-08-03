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
 * Ensures the seeded e2e admin owns a VISIBLE subscription to the fixture
 * feed. With zero subscriptions and a populated catalog the reader shell
 * redirects to the onboarding picker instead of mounting, so every Playwright
 * smoke that waits for reader chrome times out (#222).
 *
 * The subscription points at a reserved fixture feed that never resolves and
 * so is never fetched. The feed is pre-populated with one entry and a long,
 * multi-clause title: the reader shell mounts on subscription count alone,
 * but the magazine-kicker smokes measure a rendered row and need both an
 * entry to render and a source title long enough to exercise the one-line
 * clip (#155). The feed is marked already-fetched so the shell skips its
 * post-onboarding refresh sweep over a host that cannot answer.
 *
 * Every step is checked and repaired independently, rather than inferring all
 * of them from one "does a subscription exist" guard: a feed can outlive its
 * entry, and an entry can survive while becoming invisible in the default
 * unread view (an entry_state read marker, a subscription markedReadUntil
 * watermark past its effective date). Either failure reproduces the exact
 * same symptom as no fixture at all — an empty reader with the subscription
 * still showing in the sidebar — which silently disarms the three #155 clip
 * specs while the suite still reports green. That is the precise failure
 * mode this e2e workflow exists to catch, so this seed command must not trust
 * a coarse guard to rule it out.
 *
 * Runs after app:e2e:seed-admin, which creates the admin this attaches to.
 * Refuses to run under APP_ENV=prod, the same guard as its sibling
 * app:e2e:seed-admin.
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

    /**
     * Deliberately long and multi-clause: it is the "source" the magazine kicker
     * line renders, and the one-line-clip smokes need a title that overflows a
     * narrow row so the ellipsis path is exercised (#155).
     */
    private const string FIXTURE_FEED_TITLE =
        'SFR E2E Fixtures - Das Beste am Norden - Radio - Fernsehen - Nachrichten - Sport - Wetter';

    public function __construct(
        private readonly UserRepository $users,
        private readonly SubscriptionRepository $subscriptions,
        private readonly FeedRepository $feeds,
        private readonly EntryRepository $entries,
        private readonly EntryStateRepository $entryStates,
        private readonly EntityManagerInterface $em,
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
        $feed->setLastFetchedAt($this->clock->now());

        $this->em->persist($feed);
        $this->em->flush(); // assign an id before anything references it

        return $feed;
    }

    /**
     * Checked independently of feed creation: nothing else in this codebase
     * deletes an Entry without its Feed today, but this command must not
     * assume that stays true forever, and a re-run must repair a feed that
     * somehow lost its entry just as readily as one that never had it.
     */
    private function ensureSampleEntry(Feed $feed): Entry
    {
        $entry = $this->entries->findOneBy(['feed' => $feed]);
        if (null !== $entry) {
            return $entry;
        }

        $entry = $this->sampleEntry($feed, $this->clock->now());
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function ensureSubscribed(User $admin, Feed $feed): Subscription
    {
        $subscription = $this->subscriptions->findOneBy(['user' => $admin, 'feed' => $feed]);
        if (null !== $subscription) {
            return $subscription;
        }

        $subscription = new Subscription($admin, $feed, $this->clock->now());
        $subscription->setPosition($this->subscriptions->nextPositionForUser((int) $admin->getId()));

        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }

    /**
     * EntryRepository's unread filter (the reader's default view) hides an
     * entry once the subscription's markedReadUntil watermark reaches its
     * effective date, or once the caller's entry_state row marks it read.
     * Both are cleared unconditionally rather than trusted from a prior run,
     * because either one alone reproduces the same symptom as a missing
     * entry: an empty reader with the subscription still in the sidebar.
     */
    private function ensureEntryVisible(User $admin, Subscription $subscription, Entry $entry): void
    {
        $needsFlush = false;

        if (null !== $subscription->getMarkedReadUntil()) {
            $subscription->setMarkedReadUntil(null);
            $needsFlush = true;
        }

        $state = $this->entryStates->findOneForUserEntry((int) $admin->getId(), (int) $entry->getId());
        if (null !== $state && $state->isRead()) {
            $state->setIsRead(false);
            $needsFlush = true;
        }

        if ($needsFlush) {
            $this->em->flush();
        }
    }

    private function sampleEntry(Feed $feed, \DateTimeImmutable $publishedAt): Entry
    {
        $entry = new Entry(
            $feed,
            'sfr-e2e-fixture-entry-1',
            'https://fixtures.sfr-e2e.example/entry-1',
            'A sample entry for the e2e reader fixture',
            $publishedAt,
        );
        $entry->setPublishedAt($publishedAt);
        $entry->setContentHtml('<p>Fixture entry body; this feed never resolves, the row is seeded for smokes.</p>');

        return $entry;
    }
}
