<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\HiddenProfiles;

use App\Service\Bluesky\AppViewClient;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenProfiles\CachedHiddenProfiles;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\ProfileVisibilityCache;
use App\Tests\Support\BlueskyProfiles;
use App\Tests\Support\StubHiddenProfiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class CachedHiddenProfilesTest extends TestCase
{
    private StubHiddenProfiles $appView;
    private ProfileVisibilityCache $profileVisibility;
    private CachedHiddenProfiles $hiddenProfiles;

    protected function setUp(): void
    {
        $this->appView = new StubHiddenProfiles();
        $this->appView->hide(new HiddenProfileModel(BlueskyProfiles::RUSTY, 'Rusty'));
        $this->profileVisibility = new ProfileVisibilityCache(new ArrayAdapter());
        $this->hiddenProfiles = new CachedHiddenProfiles($this->appView, $this->profileVisibility);
    }

    public function testAnAnswerIsRememberedAndNotAskedForAgain(): void
    {
        $rusty = new HiddenProfileModel(BlueskyProfiles::RUSTY, 'Rusty');
        $actors = [BlueskyProfiles::RUSTY, 'did:plc:bob'];

        self::assertEquals([BlueskyProfiles::RUSTY => $rusty], $this->hiddenProfiles->hiddenAmong($actors));
        self::assertEquals([BlueskyProfiles::RUSTY => $rusty], $this->hiddenProfiles->hiddenAmong($actors));
        self::assertSame([[BlueskyProfiles::RUSTY, 'did:plc:bob']], $this->appView->asked);
    }

    public function testOnlyTheActorsOfUnknownVisibilityAreAsked(): void
    {
        $this->profileVisibility->remember(['did:plc:bob'], []);

        $this->hiddenProfiles->hiddenAmong(['did:plc:bob', 'did:plc:carol', 'did:plc:carol']);

        self::assertSame([['did:plc:carol']], $this->appView->asked);
    }

    public function testAnUnansweredAskIsNotRemembered(): void
    {
        $this->appView->failure = new SocialNetworkUnreachableException('down');

        try {
            $this->hiddenProfiles->hiddenAmong([BlueskyProfiles::RUSTY]);
            self::fail('The failure should have surfaced.');
        } catch (SocialNetworkUnreachableException) {
        }

        self::assertTrue($this->profileVisibility->visibilitiesOf([BlueskyProfiles::RUSTY])[BlueskyProfiles::RUSTY]
            ->isUnknown());
    }

    public function testActorsKnownUnknownAreAskedWithoutReadingTheirVerdictAgain(): void
    {
        $this->profileVisibility->remember([BlueskyProfiles::RUSTY], []);

        $hidden = $this->hiddenProfiles->askUnknown([BlueskyProfiles::RUSTY]);

        self::assertEquals([BlueskyProfiles::RUSTY => self::rusty()], $hidden);
        self::assertSame([[BlueskyProfiles::RUSTY]], $this->appView->asked);
    }

    public function testTheCallsAnsweredBeforeAFailureAreRemembered(): void
    {
        $answered = array_map(
            static fn (int $index): string => 'did:plc:public' . $index,
            range(1, AppViewClient::URIS_PER_CALL),
        );
        $this->appView->failure = new SocialNetworkUnreachableException('down');
        $this->appView->answeredBeforeFailure = 1;

        try {
            $this->hiddenProfiles->askUnknown([...$answered, BlueskyProfiles::RUSTY]);
            self::fail('The failure should have surfaced.');
        } catch (SocialNetworkUnreachableException) {
        }

        $visibilities = $this->profileVisibility->visibilitiesOf([...$answered, BlueskyProfiles::RUSTY]);
        self::assertTrue($visibilities['did:plc:public1']->isPublic());
        self::assertTrue($visibilities[BlueskyProfiles::RUSTY]->isUnknown());
        self::assertCount(2, $this->appView->asked);
    }

    private static function rusty(): HiddenProfileModel
    {
        return new HiddenProfileModel(BlueskyProfiles::RUSTY, 'Rusty');
    }
}
