<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\ComposerDefaults;

use App\Service\Social\ComposerDefaults\BlueskyComposerDefaults;
use App\Service\Social\Model\ComposerDefaultsModel;
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
