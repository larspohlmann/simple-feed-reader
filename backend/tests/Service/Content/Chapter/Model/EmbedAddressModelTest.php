<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\Chapter\Model;

use App\Service\Content\Chapter\Model\ChapteredEmbed;
use App\Service\Content\Chapter\Model\EmbedAddressModel;
use PHPUnit\Framework\TestCase;

final class EmbedAddressModelTest extends TestCase
{
    public function testTheCacheKeyNamesTheProviderAndTheId(): void
    {
        $embed = new EmbedAddressModel(ChapteredEmbed::SoundCloud, '1234567');

        self::assertSame('soundcloud.1234567', $embed->cacheKey());
    }
}
