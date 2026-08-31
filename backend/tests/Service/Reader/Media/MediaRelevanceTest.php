<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Reader\Media\MediaRelevance;
use PHPUnit\Framework\TestCase;

final class MediaRelevanceTest extends TestCase
{
    private MediaRelevance $relevance;

    protected function setUp(): void
    {
        $this->relevance = new MediaRelevance();
    }

    /** Deutschlandradio: the page slug and the episode filename share "bildung". */
    public function testPrefersAFileWhoseNameEchoesThePageSlug(): void
    {
        $ranked = $this->relevance->rank(
            [
                'https://ondemand-mp3.dradio.de/file/dradio/2026/08/29/teaser_something_else.mp3',
                'https://ondemand-mp3.dradio.de/file/dradio/2026/08/29/bildung_wie_kann_schule_besser_werden.mp3',
            ],
            'https://www.deutschlandfunkkultur.de/bildung-100.html',
        );

        self::assertStringContainsString('bildung_wie_kann', $ranked[0]);
    }

    /** NPR: the slug says "telescope", and so does the segment filename. */
    public function testMatchesOnASharedTokenNotTheWholeSlug(): void
    {
        $ranked = $this->relevance->rank(
            [
                'https://ondemand.npr.org/anon.npr-mp3/npr/wesun/unrelated_promo.mp3',
                'https://ondemand.npr.org/anon.npr-mp3/npr/wesun/new_telescope_will_help_scientists.mp3',
            ],
            'https://www.npr.org/2026/08/30/nx-s1-5948814/launch-nancy-grace-roman-space-telescope-nasa',
        );

        self::assertStringContainsString('telescope', $ranked[0]);
    }

    /** Ranking is soft: nothing is dropped, so a no-match page still gets media. */
    public function testKeepsEveryCandidateEvenWhenNothingMatches(): void
    {
        $urls = ['https://x.test/one.mp3', 'https://x.test/two.mp3'];

        self::assertCount(2, $this->relevance->rank($urls, 'https://x.test/article-100.html'));
    }

    public function testATieKeepsSourceOrder(): void
    {
        $urls = ['https://x.test/first.mp3', 'https://x.test/second.mp3'];

        self::assertSame($urls, $this->relevance->rank($urls, 'https://x.test/article-100.html'));
    }

    /** Short and numeric slug parts are noise and must not decide the ranking. */
    public function testIgnoresShortAndNumericSlugTokens(): void
    {
        $ranked = $this->relevance->rank(
            ['https://x.test/100-promo.mp3', 'https://x.test/schule-episode.mp3'],
            'https://x.test/schule-wie-100.html',
        );

        self::assertStringContainsString('schule-episode', $ranked[0]);
    }
}
