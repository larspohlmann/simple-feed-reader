<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenImportFollows;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\Model\SocialImportFeedModel;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StubHiddenProfiles;
use PHPUnit\Framework\TestCase;

final class HiddenImportFollowsTest extends TestCase
{
    private StubHiddenProfiles $hiddenProfiles;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->hiddenProfiles = new StubHiddenProfiles();
        $this->hiddenProfiles->hide(new HiddenProfileModel('did:plc:rusty', 'Rusty'));
        $this->logger = new RecordingLogger();
    }

    public function testTheHiddenFollowsAreFoundByTheirIdentity(): void
    {
        $this->hiddenProfiles->hide(new HiddenProfileModel('did:plc:carol', 'Carol'));

        $hidden = $this->follows()->among(SocialAccounts::bluesky(), [
            new SocialImportFeedModel('did:plc:rusty', 'Rusty'),
            new SocialImportFeedModel('did:plc:bob', 'Bob'),
            new SocialImportFeedModel('did:plc:carol', 'Carol'),
        ]);

        self::assertEquals([
            'did:plc:rusty' => new HiddenProfileModel('did:plc:rusty', 'Rusty'),
            'did:plc:carol' => new HiddenProfileModel('did:plc:carol', 'Carol'),
        ], $hidden);
    }

    public function testAHandleIsAskedLowerCasedAndAnsweredByTheIdentityChosen(): void
    {
        $this->hiddenProfiles->hide(new HiddenProfileModel('did:plc:rusty', 'Rusty'), 'rusty-17.bsky.social');

        $hidden = $this->follows()->among(SocialAccounts::bluesky(), [
            new SocialImportFeedModel('Rusty-17.bsky.social', 'Rusty'),
        ]);

        self::assertEquals(['Rusty-17.bsky.social' => new HiddenProfileModel('did:plc:rusty', 'Rusty')], $hidden);
        self::assertSame([['rusty-17.bsky.social']], $this->hiddenProfiles->asked);
    }

    public function testAnIdentityThatIsNoActorIsNeverAsked(): void
    {
        $this->follows()->among(SocialAccounts::bluesky(), [
            new SocialImportFeedModel('did:plc:rusty', null),
            new SocialImportFeedModel('not an actor', null),
        ]);

        self::assertSame([['did:plc:rusty']], $this->hiddenProfiles->asked);
    }

    public function testAMastodonImportAsksNothing(): void
    {
        self::assertSame([], $this->follows()->among(SocialAccounts::mastodon(), [
            new SocialImportFeedModel('did:plc:rusty', null),
        ]));
        self::assertSame([], $this->hiddenProfiles->asked);
    }

    public function testNothingToAskAsksNothing(): void
    {
        self::assertSame([], $this->follows()->among(SocialAccounts::bluesky(), []));
        self::assertSame([], $this->hiddenProfiles->asked);
    }

    public function testAnUnansweredCheckLeavesEveryFollowToRssAndIsLogged(): void
    {
        $failure = new SocialNetworkUnreachableException('down');
        $this->hiddenProfiles->failure = $failure;

        self::assertSame([], $this->follows()->among(SocialAccounts::bluesky(), [
            new SocialImportFeedModel('did:plc:rusty', null),
        ]));
        self::assertSame('warning', $this->logger->records[0]['level'] ?? null);
        self::assertSame($failure, $this->logger->records[0]['context']['exception'] ?? null);
    }

    private function follows(): HiddenImportFollows
    {
        return new HiddenImportFollows($this->hiddenProfiles, $this->logger);
    }
}
