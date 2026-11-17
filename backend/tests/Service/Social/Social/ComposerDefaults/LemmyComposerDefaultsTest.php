<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\ComposerDefaults;

use App\Service\Social\Social\ComposerDefaults\LemmyComposerDefaults;
use App\Service\Social\Social\Model\ComposerDefaultsModel;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class LemmyComposerDefaultsTest extends TestCase
{
    public function testALinkPostHoldsTheSameTenThousandCharactersAsAnyOtherPost(): void
    {
        self::assertEquals(
            new ComposerDefaultsModel(LemmyComposerDefaults::MAX_CHARACTERS),
            (new LemmyComposerDefaults())->forLinkFrom(SocialAccounts::mastodon()),
        );
    }
}
