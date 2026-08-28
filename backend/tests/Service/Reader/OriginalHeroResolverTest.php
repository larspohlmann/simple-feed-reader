<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Service\Reader\HeroImageSelector;
use App\Service\Reader\OriginalHeroResolver;
use PHPUnit\Framework\TestCase;

final class OriginalHeroResolverTest extends TestCase
{
    private OriginalHeroResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new OriginalHeroResolver(new HeroImageSelector());
    }

    public function testTheFeedImageLeadsWhenTheFeedBodyHasNoImage(): void
    {
        $entry = $this->entry('<p>Feed body.</p>');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);

        self::assertSame('https://cdn.test/feed.jpg', $this->resolver->resolve($entry)?->url);
    }

    public function testTheFeedImageDoesNotLeadWhenTheFeedBodyHasAnImage(): void
    {
        $entry = $this->entry('<p>Intro.</p><img src="https://cdn.test/body.jpg" alt="">');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);

        self::assertNull($this->resolver->resolve($entry));
    }

    public function testUsesTheSummaryWhenTheEntryHasNoContent(): void
    {
        $entry = $this->entry(null, '<p>Intro.</p><img src="https://cdn.test/body.jpg" alt="">');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);

        self::assertNull($this->resolver->resolve($entry));
    }

    public function testResolvesNoHeroWhenTheEntryHasNoImage(): void
    {
        self::assertNull($this->resolver->resolve($this->entry('<p>Feed body.</p>')));
    }

    public function testRejectsANonHttpFeedImage(): void
    {
        $entry = $this->entry('<p>Feed body.</p>');
        $entry->setImage('javascript:alert(1)', null, null);

        self::assertNull($this->resolver->resolve($entry));
    }

    private function entry(?string $contentHtml, ?string $summary = null): Entry
    {
        $entry = new Entry(
            new Feed('https://site.test/feed.xml'),
            'guid-1',
            'https://site.test/post',
            'Post',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $entry->setContentHtml($contentHtml);
        $entry->setSummary($summary);

        return $entry;
    }
}
