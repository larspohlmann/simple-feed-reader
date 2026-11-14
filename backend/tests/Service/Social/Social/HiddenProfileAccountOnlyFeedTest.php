<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Entity\User;
use App\Enum\SourceFormat;
use App\Repository\SocialAccountRepository;
use App\Service\Sourcing\Discovery\Model\FeedCandidateModel;
use App\Service\Sourcing\Discovery\Model\NoPreviewReason;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\HiddenProfileAccountOnlyFeed;
use App\Service\Social\Social\Model\HiddenProfileModel;
use App\Service\Social\Social\TolerantHiddenProfiles;
use App\Tests\DbTestCase;
use App\Tests\Support\BlueskyProfiles;
use App\Tests\Support\ConnectsBlueskyAccounts;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubHiddenProfiles;

final class HiddenProfileAccountOnlyFeedTest extends DbTestCase
{
    use ConnectsBlueskyAccounts;
    use SeedsUsers;

    private const string PAGE = 'https://bsky.app/profile/rusty-17.bsky.social';

    private User $user;
    private StubHiddenProfiles $hiddenProfiles;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->user('hidden-discover@example.test');
        $this->hiddenProfiles = new StubHiddenProfiles();
        $this->hiddenProfiles->hide(new HiddenProfileModel(BlueskyProfiles::RUSTY, 'Rusty'), 'rusty-17.bsky.social');
        $this->logger = new RecordingLogger();
    }

    public function testAHiddenProfileTheConnectedAccountCanReadIsOfferedWithoutAPreview(): void
    {
        $this->blueskyAccountOf($this->user);

        self::assertEquals(
            new FeedCandidateModel(self::PAGE, 'Rusty', SourceFormat::BLUESKY_API, NoPreviewReason::HiddenProfile),
            $this->feed()->candidateFor($this->user, self::PAGE),
        );
    }

    public function testWithoutAConnectedAccountTheCandidateSaysToConnectOne(): void
    {
        self::assertEquals(
            new FeedCandidateModel(
                self::PAGE,
                'Rusty',
                SourceFormat::BLUESKY_API,
                NoPreviewReason::ConnectBlueskyAccount,
            ),
            $this->feed()->candidateFor($this->user, self::PAGE),
        );
    }

    public function testAnAccountThatNeedsAReconnectCannotReadTheProfile(): void
    {
        $account = $this->blueskyAccountOf($this->user);
        $account->markNeedsReconnect();
        $this->entityManager->flush();

        self::assertSame(
            NoPreviewReason::ConnectBlueskyAccount,
            $this->feed()->candidateFor($this->user, self::PAGE)?->noPreviewReason,
        );
    }

    public function testAPublicProfileIsLeftToDiscovery(): void
    {
        self::assertNull($this->feed()->candidateFor($this->user, 'https://bsky.app/profile/bob.bsky.social'));
    }

    public function testAnyOtherUrlIsLeftToDiscoveryUnasked(): void
    {
        self::assertNull($this->feed()->candidateFor($this->user, 'https://example.com/feed'));
        self::assertSame([], $this->hiddenProfiles->asked);
    }

    public function testAnUnansweredLabelCheckIsLeftToDiscoveryAndLogged(): void
    {
        $failure = new SocialNetworkUnreachableException('down');
        $this->hiddenProfiles->failure = $failure;

        self::assertNull($this->feed()->candidateFor($this->user, self::PAGE));
        self::assertSame('warning', $this->logger->records[0]['level'] ?? null);
        self::assertSame($failure, $this->logger->records[0]['context']['exception'] ?? null);
    }

    private function feed(): HiddenProfileAccountOnlyFeed
    {
        $socialAccounts = self::getContainer()->get(SocialAccountRepository::class);
        self::assertInstanceOf(SocialAccountRepository::class, $socialAccounts);

        return new HiddenProfileAccountOnlyFeed(
            new TolerantHiddenProfiles($this->hiddenProfiles, $this->logger),
            $socialAccounts,
        );
    }
}
