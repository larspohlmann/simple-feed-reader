<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Bluesky;

use App\Service\Social\Bluesky\BlueskyComposerDefaults;
use App\Service\Social\Publishing\Model\ComposerDefaultsModel;
use App\Service\Social\Publishing\Model\SharedLinkModel;
use App\Tests\Support\SocialApiWiring;
use PHPUnit\Framework\TestCase;

final class BlueskyComposerDefaultsTest extends TestCase
{
    public function testAnAccountsOwnPostHoldsThreeHundredGraphemes(): void
    {
        self::assertEquals(
            new ComposerDefaultsModel(300),
            (new BlueskyComposerDefaults())->forAccount(SocialApiWiring::blueskyAccount()),
        );
    }

    public function testALinkPostKeepsTheFullLimitBecauseTheLinkRidesInTheCard(): void
    {
        self::assertEquals(
            new ComposerDefaultsModel(300),
            (new BlueskyComposerDefaults())->forLinkFrom(
                SocialApiWiring::blueskyAccount(),
                new SharedLinkModel('https://example.com', 'A title'),
            ),
        );
    }
}
