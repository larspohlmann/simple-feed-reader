<?php

declare(strict_types=1);

namespace App\Tests\Service\LinkCard\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Share;
use App\Service\LinkCard\Support\ShareTargets;
use PHPUnit\Framework\TestCase;

final class ShareTargetsTest extends TestCase
{
    private static function entry(?string $body): Entry
    {
        $now = new \DateTimeImmutable();
        $entry = new Entry(new Feed('https://feeds.example/feed'), 'g', 'https://example.com/story', 'T', $now, $now);
        $entry->setContentHtml($body);

        return $entry;
    }

    public function testATitledShareHasOnlyItsTarget(): void
    {
        $entry = self::entry('<p><a href="https://other.example/x">https://other.example/x</a></p>');
        $entry->setShare(Share::link('https://example.com/story'));

        self::assertSame(['https://example.com/story'], ShareTargets::uncardedOf($entry, []));
        self::assertSame([], ShareTargets::uncardedOf($entry, ['https://example.com/story']));
    }

    public function testAPostHasTheLinksItsBodyShares(): void
    {
        $entry = self::entry('<p><a href="https://a.example/1">https://a.example/1</a> '
            . '<a href="https://b.example/2">https://b.example/2</a></p>');
        $entry->setShare(Share::link(null));

        self::assertSame(['https://b.example/2'], ShareTargets::uncardedOf($entry, ['https://a.example/1']));
    }
}
