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

    public function testTheExtractionImageWinsTheReaderHero(): void
    {
        $entry = $this->entry('<p>Feed body.</p>');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);

        $heroes = $this->resolver->resolve(
            $entry,
            $this->extracted('<p>Extracted body.</p>', 'https://cdn.test/og.jpg'),
        );

        self::assertSame('https://cdn.test/og.jpg', $heroes->readerHero?->url);
        // readability reports no dimensions for an og:image.
        self::assertNull($heroes->readerHero->width);
    }

    public function testTheFeedImageBacksTheReaderHeroWhenTheExtractionHasNone(): void
    {
        $entry = $this->entry('<p>Feed body.</p>');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);

        $heroes = $this->resolver->resolve($entry, $this->extracted('<p>Extracted body.</p>', null));

        self::assertSame('https://cdn.test/feed.jpg', $heroes->readerHero?->url);
        self::assertSame(800, $heroes->readerHero->width);
        self::assertSame(450, $heroes->readerHero->height);
    }

    public function testTheFeedImageBacksTheReaderHeroWhenTheExtractionImageIsSuppressed(): void
    {
        // The og:image repeats a picture the extracted body already shows, so it
        // is suppressed; the feed's own, different picture may still lead.
        $entry = $this->entry('<p>Feed body.</p>');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);
        $extractedBody = '<p>Intro.</p><img src="https://cdn.test/og.jpg" alt="">';

        $heroes = $this->resolver->resolve(
            $entry,
            $this->extracted($extractedBody, 'https://cdn.test/og.jpg'),
        );

        self::assertSame('https://cdn.test/feed.jpg', $heroes->readerHero?->url);
    }

    public function testTheFeedImageDoesNotBackTheHeroWhenItIsASizeVariantOfTheBodyPhoto(): void
    {
        // The deutschlandfunk.de entry 1358618 case (#610): the og:image repeats
        // the body's lead figure and is suppressed, but the feed's own picture is
        // the SAME photo at a different size (`-1920x1920` vs the body's
        // `-1920x1080`). It must not back the hero, or the reader stacks the photo
        // twice — the whole point of judging the feed picture against the body.
        $photo = 'https://bilder.deutschlandfunk.de/0f/5a/5c/dd/uuid/ai-toys-100';
        $entry = $this->entry('<p>Feed body.</p>');
        $entry->setImage($photo . '-1920x1920.jpg', 1920, 1920);
        $extractedBody = '<p>Intro.</p><figure><img src="' . $photo . '-1920x1080.jpg" alt=""></figure>';

        $heroes = $this->resolver->resolve(
            $entry,
            $this->extracted($extractedBody, $photo . '-1920x1080.jpg'),
        );

        self::assertNull($heroes->readerHero);
    }

    public function testTheReaderHeroIsJudgedAgainstTheExtractedBodyNotTheFeedBody(): void
    {
        // The feed body repeats the feed picture and the extracted body does not.
        // Only the original hero may be suppressed.
        $entry = $this->entry('<p>Intro.</p><img src="https://cdn.test/feed.jpg" alt="">');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);

        $heroes = $this->resolver->resolve($entry, $this->extracted('<p>Extracted body.</p>', null));

        self::assertSame('https://cdn.test/feed.jpg', $heroes->readerHero?->url);
        self::assertNull($heroes->originalHero);
    }

    public function testTheOriginalHeroIsSuppressedWhenTheFeedBodyLeadsWithAnImage(): void
    {
        $entry = $this->entry('<figure><img src="https://cdn.test/other.jpg" alt=""></figure><p>x</p>');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);

        $heroes = $this->resolver->resolve($entry, $this->extracted('<p>Extracted.</p>', null));

        self::assertNull($heroes->originalHero);
    }

    public function testTheOriginalHeroFallsBackToTheSummaryWhenThereIsNoContentHtml(): void
    {
        // Many feeds populate only one of contentHtml and summary; the rule must
        // judge the body the client will actually render.
        $entry = $this->entry(null, '<p>a</p><img src="https://cdn.test/feed.jpg" alt="">');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);

        $heroes = $this->resolver->resolve($entry, $this->extracted('<p>Extracted.</p>', null));

        self::assertNull($heroes->originalHero);
    }

    public function testTheOriginalHeroLeadsAnEntryWithNoBodyAtAll(): void
    {
        $entry = $this->entry(null);
        $entry->setImage('https://cdn.test/feed.jpg', null, null);

        $heroes = $this->resolver->resolve($entry, $this->extracted('<p>Extracted.</p>', null));

        self::assertSame('https://cdn.test/feed.jpg', $heroes->originalHero?->url);
        // Unknown dimensions pass through rather than being guessed.
        self::assertNull($heroes->originalHero->width);
        self::assertNull($heroes->originalHero->height);
    }

    public function testAFailedExtractionStillResolvesTheOriginalHero(): void
    {
        // The client forces the original view on a failed extraction, where the
        // feed's picture is the only one there is.
        $entry = $this->entry('<p>Feed body.</p>');
        $entry->setImage('https://cdn.test/feed.jpg', 800, 450);

        $heroes = $this->resolver->resolve($entry, ExtractionResult::failed('https://site.test/post', 'fetch'));

        self::assertNull($heroes->readerHero);
        self::assertSame('https://cdn.test/feed.jpg', $heroes->originalHero?->url);
    }

    public function testAnEntryWithoutAPictureResolvesNoHeroes(): void
    {
        $heroes = $this->resolver->resolve($this->entry('<p>Feed body.</p>'), $this->extracted('<p>x</p>', null));

        self::assertNull($heroes->readerHero);
        self::assertNull($heroes->originalHero);
    }

    public function testANonHttpFeedPictureIsRejected(): void
    {
        // The persisted URL is https server-side, but the guard is the boundary,
        // not the expectation — a javascript: URL must never reach an <img src>.
        $entry = $this->entry('<p>Feed body.</p>');
        $entry->setImage('javascript:alert(1)', null, null);

        $heroes = $this->resolver->resolve($entry, $this->extracted('<p>x</p>', null));

        self::assertNull($heroes->readerHero);
        self::assertNull($heroes->originalHero);
    }
}
