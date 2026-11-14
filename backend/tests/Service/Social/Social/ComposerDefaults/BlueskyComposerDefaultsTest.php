<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\ComposerDefaults;

use App\Service\Social\Social\ComposerDefaults\BlueskyComposerDefaults;
use App\Service\Social\Social\Model\ComposerDefaultsModel;
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
}
