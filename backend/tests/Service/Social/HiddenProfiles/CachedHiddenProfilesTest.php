<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\HiddenProfiles;

use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\HiddenProfiles\CachedHiddenProfiles;
use App\Service\Social\Model\HiddenProfileModel;
use App\Service\Social\ProfileVisibilityCache;
use App\Tests\Support\StubHiddenProfiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class CachedHiddenProfilesTest extends TestCase
{
    private const string RUSTY = 'did:plc:hczfd45prvqtdrw77256syag';

    private StubHiddenProfiles $appView;
    private ProfileVisibilityCache $profileVisibility;
    private CachedHiddenProfiles $hiddenProfiles;

    protected function setUp(): void
    {
        $this->appView = new StubHiddenProfiles();
        $this->appView->hide(new HiddenProfileModel(self::RUSTY, 'Rusty'));
        $this->profileVisibility = new ProfileVisibilityCache(new ArrayAdapter());
        $this->hiddenProfiles = new CachedHiddenProfiles($this->appView, $this->profileVisibility);
    }

    public function testAnAnswerIsRememberedAndNotAskedForAgain(): void
    {
        $rusty = new HiddenProfileModel(self::RUSTY, 'Rusty');

        self::assertEquals([self::RUSTY => $rusty], $this->hiddenProfiles->hiddenAmong([self::RUSTY, 'did:plc:bob']));
        self::assertEquals([self::RUSTY => $rusty], $this->hiddenProfiles->hiddenAmong([self::RUSTY, 'did:plc:bob']));
        self::assertSame([[self::RUSTY, 'did:plc:bob']], $this->appView->asked);
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
            $this->hiddenProfiles->hiddenAmong([self::RUSTY]);
            self::fail('The failure should have surfaced.');
        } catch (SocialNetworkUnreachableException) {
        }

        self::assertTrue($this->profileVisibility->visibilityOf(self::RUSTY)->isUnknown());
    }
}
