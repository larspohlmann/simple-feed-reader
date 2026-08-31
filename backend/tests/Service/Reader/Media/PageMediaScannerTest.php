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

    /** A declared file beats a scanned one: the first layer to yield a kind owns it. */
    public function testTheFirstSourceToYieldAKindWins(): void
    {
        $scanner = new PageMediaScanner([
            $this->source([new MediaCandidate(MediaKind::Audio, 'https://x.test/declared.mp3')]),
            $this->source([new MediaCandidate(MediaKind::Audio, 'https://x.test/scanned.mp3')]),
        ]);

        $media = $scanner->scan('<html></html>', 'https://x.test/a');

        self::assertCount(1, $media->candidates);
        self::assertStringContainsString('declared', $media->candidates[0]->url);
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
}
