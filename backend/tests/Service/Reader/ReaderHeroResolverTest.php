<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Service\Reader\ExtractionResult;
use App\Service\Reader\HeroImageSelector;
use App\Service\Reader\ReaderHeroResolver;
use PHPUnit\Framework\TestCase;

final class ReaderHeroResolverTest extends TestCase
{
    private ReaderHeroResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ReaderHeroResolver(new HeroImageSelector());
    }

    public function testTheArticleImageDoesNotLeadWhenTheExtractedBodyHasAnyImage(): void
    {
        // #657: the extracted body already carries a picture, so neither the
        // article og:image nor the feed image leads it — even though the body
        // image (body.jpg) is a different file from the og:image.
        $entry = $this->entry('<p>Feed body.</p>');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);

        $heroes = $this->resolver->resolve(
            $entry,
            $this->extracted('<p>Intro.</p><img src="https://cdn.test/body.jpg" alt="">', 'https://cdn.test/og.jpg'),
        );

        self::assertNull($heroes->readerHero);
    }

    public function testTheArticleImageLeadsWhenTheExtractedBodyHasNoImage(): void
    {
        // The article's own og:image leads a text-only extracted body.
        $entry = $this->entry('<p>Feed body.</p>');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);

        $heroes = $this->resolver->resolve(
            $entry,
            $this->extracted('<p>Just words.</p>', 'https://cdn.test/og.jpg'),
        );

        self::assertSame('https://cdn.test/og.jpg', $heroes->readerHero?->url);
    }

    public function testTheFeedImageDoesNotBackTheReaderHeroWhenTheExtractedBodyHasAnImage(): void
    {
        $entry = $this->entry('<p>Feed body.</p>');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);

        $heroes = $this->resolver->resolve(
            $entry,
            $this->extracted('<p>Intro.</p><img src="https://cdn.test/body.jpg" alt="">', null),
        );

        self::assertNull($heroes->readerHero);
    }

    public function testTheFeedImageDoesNotLeadTheOriginalViewWhenTheFeedBodyHasAnImage(): void
    {
        $entry = $this->entry('<p>Intro.</p><img src="https://cdn.test/body.jpg" alt="">');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);

        $heroes = $this->resolver->resolve($entry, $this->extracted('<p>Extracted.</p>', null));

        self::assertNull($heroes->originalHero);
    }

    public function testTheFeedImageLeadsWhenTheRenderedBodiesHaveNoImage(): void
    {
        $entry = $this->entry('<p>Feed body.</p>');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);

        $heroes = $this->resolver->resolve($entry, $this->extracted('<p>Extracted body.</p>', null));

        self::assertSame('https://cdn.test/feed.jpg', $heroes->readerHero?->url);
        self::assertSame('https://cdn.test/feed.jpg', $heroes->originalHero?->url);
    }

    public function testTheOriginalHeroUsesTheSummaryWhenTheEntryHasNoContent(): void
    {
        $entry = $this->entry(null, '<p>Intro.</p><img src="https://cdn.test/body.jpg" alt="">');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);

        $heroes = $this->resolver->resolve($entry, $this->extracted('<p>Extracted.</p>', null));

        self::assertNull($heroes->originalHero);
    }

    public function testAnEntryWithoutImagesResolvesNoHeroes(): void
    {
        $heroes = $this->resolver->resolve(
            $this->entry('<p>Feed body.</p>'),
            $this->extracted('<p>Extracted.</p>', null),
        );

        self::assertNull($heroes->readerHero);
        self::assertNull($heroes->originalHero);
    }

    public function testRejectsANonHttpFeedImage(): void
    {
        $entry = $this->entry('<p>Feed body.</p>');
        $entry->setImage('javascript:alert(1)', null, null);

        $heroes = $this->resolver->resolve($entry, $this->extracted('<p>Extracted.</p>', null));

        self::assertNull($heroes->readerHero);
        self::assertNull($heroes->originalHero);
    }

    public function testAFailedExtractionStillResolvesTheOriginalHero(): void
    {
        $entry = $this->entry('<p>Feed body.</p>');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);

        $heroes = $this->resolver->resolve($entry, ExtractionResult::failed('https://site.test/post', 'fetch'));

        self::assertNull($heroes->readerHero);
        self::assertSame('https://cdn.test/feed.jpg', $heroes->originalHero?->url);
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

    private function extracted(string $contentHtml, ?string $imageCandidate): ExtractionResult
    {
        return ExtractionResult::ok(
            url: 'https://site.test/post',
            title: 'Post',
            byline: null,
            siteName: null,
            contentHtml: $contentHtml,
            excerpt: null,
            imageCandidate: $imageCandidate,
        );
    }
}
