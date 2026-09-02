<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaCandidateSourceInterface;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\PageMediaScanner;
use PHPUnit\Framework\TestCase;

final class PageMediaScannerTest extends TestCase
{
    /** @param list<MediaCandidate> $candidates */
    private function source(array $candidates): MediaCandidateSourceInterface
    {
        return new class ($candidates) implements MediaCandidateSourceInterface {
            /** @param list<MediaCandidate> $candidates */
            public function __construct(private readonly array $candidates)
            {
            }

            public function find(string $pageHtml, string $pageUrl): array
            {
                return $this->candidates;
            }
        };
    }

    /** A declared file and a scanned one at the same URL are one candidate, and the declaration's data stands. */
    public function testTheSameUrlFromTwoSourcesIsOneCandidateWithTheDeclaredData(): void
    {
        $scanner = new PageMediaScanner([
            $this->source([
                new MediaCandidate(MediaKind::Video, 'https://x.test/a.mp4', 'https://x.test/declared.jpg'),
            ]),
            $this->source([
                new MediaCandidate(MediaKind::Video, 'https://x.test/a.mp4', 'https://x.test/scanned.jpg'),
            ]),
        ]);

        $media = $scanner->scan('<html></html>', 'https://x.test/a');

        self::assertCount(1, $media->candidates);
        self::assertSame('https://x.test/declared.jpg', $media->candidates[0]->posterUrl);
    }

    /** vice 495401: JSON-LD declares the first video from the <head>, with no prose anchor; the page scan knows where it stands. */
    public function testALaterSourceFillsTheAnchorTheDeclarationLacks(): void
    {
        $url = 'https://www.youtube-nocookie.com/embed/aaaaaaaaaaa';
        $scanner = new PageMediaScanner([
            $this->source([
                new MediaCandidate(MediaKind::Embed, $url, 'https://i.ytimg.com/vi/aaaaaaaaaaa/hqdefault.jpg'),
            ]),
            $this->source([
                new MediaCandidate(MediaKind::Embed, $url, null, null, 'The section the player follows.'),
            ]),
        ]);

        $media = $scanner->scan('<html></html>', 'https://x.test/a');

        self::assertCount(1, $media->candidates);
        self::assertSame('The section the player follows.', $media->candidates[0]->precedingText);
        self::assertSame('https://i.ytimg.com/vi/aaaaaaaaaaa/hqdefault.jpg', $media->candidates[0]->posterUrl);
    }

    /** vice 495401: JSON-LD declares one of four videos; the other three exist only as page embeds. */
    public function testALaterSourceAddsTheUrlsTheEarlierOneNeverNamed(): void
    {
        $embed = static fn (string $id): MediaCandidate => new MediaCandidate(
            MediaKind::Embed,
            'https://www.youtube-nocookie.com/embed/' . $id,
        );
        $scanner = new PageMediaScanner([
            $this->source([$embed('aaaaaaaaaaa')]),
            $this->source([$embed('aaaaaaaaaaa'), $embed('bbbbbbbbbbb'), $embed('ccccccccccc'), $embed('ddddddddddd')]),
        ]);

        $urls = array_map(
            static fn (MediaCandidate $c): string => $c->url,
            $scanner->scan('<html></html>', 'https://x.test/a')->candidates,
        );

        self::assertSame([
            'https://www.youtube-nocookie.com/embed/aaaaaaaaaaa',
            'https://www.youtube-nocookie.com/embed/bbbbbbbbbbb',
            'https://www.youtube-nocookie.com/embed/ccccccccccc',
            'https://www.youtube-nocookie.com/embed/ddddddddddd',
        ], $urls);
    }

    /** ARD: a lower source that re-confirms no URL of a claimed kind is seeing a rendition, not a new player — its unique URL is dropped (#788). */
    public function testALowerSourceThatConfirmsNothingAddsNoUrlOfAClaimedKind(): void
    {
        $scanner = new PageMediaScanner([
            $this->source([new MediaCandidate(MediaKind::Video, 'https://x.test/declared.webxxl.mp4')]),
            $this->source([new MediaCandidate(MediaKind::Video, 'https://x.test/scanned.webs.mp4')]),
        ]);

        $media = $scanner->scan('<html></html>', 'https://x.test/a');

        self::assertCount(1, $media->candidates);
        self::assertStringContainsString('declared', $media->candidates[0]->url);
    }

    public function testTheCapAppliesToTheMergedListAcrossSources(): void
    {
        // The second source re-confirms e0 so the guard trusts the rest of its embeds.
        $first = [];
        $second = [new MediaCandidate(MediaKind::Embed, 'https://x.test/e0')];
        for ($i = 0; $i < 15; $i++) {
            $first[] = new MediaCandidate(MediaKind::Embed, 'https://x.test/e' . $i);
            $second[] = new MediaCandidate(MediaKind::Embed, 'https://x.test/f' . $i);
        }

        $scanner = new PageMediaScanner([$this->source($first), $this->source($second)]);

        self::assertCount(ArticleMedia::MAX_ITEMS, $scanner->scan('<html></html>', 'https://x.test/a')->candidates);
    }

    /** Kinds are independent, so NPR keeps both its video embed and its audio. */
    public function testADifferentKindStillComesThroughALaterSource(): void
    {
        $scanner = new PageMediaScanner([
            $this->source([new MediaCandidate(MediaKind::Embed, 'https://www.youtube-nocookie.com/embed/aaaaaaaaaaa')]),
            $this->source([new MediaCandidate(MediaKind::Audio, 'https://x.test/companion.mp3')]),
        ]);

        $media = $scanner->scan('<html></html>', 'https://x.test/a');

        self::assertCount(2, $media->candidates);
    }

    /** OZORA: many embeds from one source all survive; only later SOURCES lose. */
    public function testOneSourceMayYieldManyOfAKind(): void
    {
        $scanner = new PageMediaScanner([
            $this->source([
                new MediaCandidate(MediaKind::Embed, 'https://www.youtube-nocookie.com/embed/aaaaaaaaaaa'),
                new MediaCandidate(MediaKind::Embed, 'https://www.youtube-nocookie.com/embed/bbbbbbbbbbb'),
            ]),
        ]);

        self::assertCount(2, $scanner->scan('<html></html>', 'https://x.test/a')->candidates);
    }

    public function testTheCapStillApplies(): void
    {
        $many = [];
        for ($i = 0; $i < ArticleMedia::MAX_ITEMS + 5; $i++) {
            $many[] = new MediaCandidate(MediaKind::Embed, 'https://x.test/e' . $i);
        }

        $scanner = new PageMediaScanner([$this->source($many)]);

        self::assertCount(ArticleMedia::MAX_ITEMS, $scanner->scan('<html></html>', 'https://x.test/a')->candidates);
    }

    /** The guard is per-source: a new URL from a source that re-confirms nothing is dropped even when a still-later source re-confirms the kind (#788). */
    public function testANewUrlFromANonReconfirmingSourceIsDroppedEvenIfALaterSourceReconfirms(): void
    {
        $scanner = new PageMediaScanner([
            $this->source([new MediaCandidate(MediaKind::Embed, 'https://x.test/a')]),
            $this->source([new MediaCandidate(MediaKind::Embed, 'https://x.test/b')]),
            $this->source([new MediaCandidate(MediaKind::Embed, 'https://x.test/a')]),
        ]);

        $urls = array_map(
            static fn (MediaCandidate $candidate): string => $candidate->url,
            $scanner->scan('<html></html>', 'https://x.test/a')->candidates,
        );

        self::assertSame(['https://x.test/a'], $urls);
    }
}
