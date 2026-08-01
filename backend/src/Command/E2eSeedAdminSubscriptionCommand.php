<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\SourceFormat;
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
 * Ensures the seeded e2e admin owns at least one subscription. With zero
 * subscriptions and a populated catalog the reader shell redirects to the
 * onboarding picker instead of mounting, so every Playwright smoke that waits
 * for reader chrome times out (#222).
 *
 * The subscription points at a reserved fixture feed that never resolves and so
 * is never fetched. The feed is pre-populated with one entry and a long,
 * multi-clause title: the reader shell mounts on subscription count alone, but
 * the magazine-kicker smokes measure a rendered row and need both an entry to
 * render and a source title long enough to exercise the one-line clip (#155).
 * The feed is marked already-fetched so the shell skips its post-onboarding
 * refresh sweep over a host that cannot answer.
 *
 * Runs after app:e2e:seed-admin, which creates the admin this attaches to.
 * Idempotent: an admin that already owns any subscription is left untouched, so
 * repeated runs never duplicate the fixture. Refuses to run under
 * APP_ENV=prod, the same guard as its sibling app:e2e:seed-admin.
 */
#[AsCommand(
    name: 'app:e2e:seed-admin-subscription',
    description: 'Give the e2e admin one subscription so the reader shell renders (non-prod only).',
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

        $existing = $this->subscriptions->countForUser((int) $admin->getId());
        if ($existing > 0) {
            $io->success(\sprintf('Admin %s already owns %d subscription(s); nothing to do.', $email, $existing));

            return Command::SUCCESS;
        }

        $this->subscribeToFixtureFeed($admin);

        $io->success(\sprintf('Subscribed %s to the fixture feed.', $email));

        return Command::SUCCESS;
    }

    private function subscribeToFixtureFeed(User $admin): void
    {
        $subscription = new Subscription($admin, $this->fixtureFeed(), $this->clock->now());
        $subscription->setPosition($this->subscriptions->nextPositionForUser((int) $admin->getId()));

        $this->em->persist($subscription);
        $this->em->flush();
    }

    private function fixtureFeed(): Feed
    {
        $feed = $this->feeds->findOneBy(['url' => self::FIXTURE_FEED_URL]);
        if (null !== $feed) {
            return $feed;
        }

        $now = $this->clock->now();

        $feed = new Feed(self::FIXTURE_FEED_URL);
        $feed->setTitle(self::FIXTURE_FEED_TITLE);
        $feed->setSourceFormat(SourceFormat::XML);
        // Already fetched, so the reader skips its post-onboarding refresh sweep
        // over a host that never answers.
        $feed->setLastFetchedAt($now);

        $this->em->persist($feed);
        $this->em->persist($this->sampleEntry($feed, $now));
        $this->em->flush(); // assign ids before the subscription references the feed

        return $feed;
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
