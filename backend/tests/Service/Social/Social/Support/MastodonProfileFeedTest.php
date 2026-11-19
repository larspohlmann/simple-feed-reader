<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social\Support;

use App\Service\Social\Social\Support\MastodonProfileFeed;
use PHPUnit\Framework\TestCase;

final class MastodonProfileFeedTest extends TestCase
{
    public function testAMastodonProfileFeedIsBuiltFromTheUserAndDomain(): void
    {
        self::assertSame('https://example.social/@bob.rss', MastodonProfileFeed::buildUrl('bob', 'example.social'));
    }
}
