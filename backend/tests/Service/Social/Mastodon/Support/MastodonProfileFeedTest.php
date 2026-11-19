<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Mastodon\Support;

use App\Service\Social\Mastodon\Support\MastodonProfileFeed;
use PHPUnit\Framework\TestCase;

final class MastodonProfileFeedTest extends TestCase
{
    public function testAMastodonProfileFeedIsBuiltFromTheUserAndDomain(): void
    {
        self::assertSame('https://example.social/@bob.rss', MastodonProfileFeed::buildUrl('bob', 'example.social'));
    }
}
