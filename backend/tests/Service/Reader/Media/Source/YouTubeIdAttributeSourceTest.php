<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media\Source;

use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\Provider\YouTubeEmbedProvider;
use App\Service\Reader\Media\Source\YouTubeIdAttributeSource;
use PHPUnit\Framework\TestCase;

final class YouTubeIdAttributeSourceTest extends TestCase
{
    private const string PROSE =
        'The paragraph the player followed on the source page, long enough to be prose.';

    private YouTubeIdAttributeSource $source;

    protected function setUp(): void
    {
        $this->source = new YouTubeIdAttributeSource(new EmbedProviders([new YouTubeEmbedProvider()]));
    }

    /** The Guardian's youtube-atom, reached without naming the Guardian. */
    public function testAnElementNamingYouTubeWithAnIdYieldsTheEmbed(): void
    {
        $html = '<body><div data-component="youtube-atom" data-atom-id="8052ac31" '
            . 'data-video-id="pz8VRrI0p0U"></div></body>';

        $found = $this->source->find($html, 'https://x.test/whales-video');

        self::assertCount(1, $found);
        self::assertSame(MediaKind::Embed, $found[0]->kind);
        self::assertSame('https://www.youtube-nocookie.com/embed/pz8VRrI0p0U', $found[0]->url);
        self::assertSame('https://i.ytimg.com/vi/pz8VRrI0p0U/hqdefault.jpg', $found[0]->posterUrl);
        self::assertSame('Watch on YouTube', $found[0]->label);
    }

    /** zeit.de spells the marker as an id prefix. */
    public function testAYtPrefixedIdIsTheMarkerToo(): void
    {
        $html = '<body><div id="yt-JSrAQkrp1JI0" data-video-id="JSrAQkrp1JI"></div></body>';

        $found = $this->source->find($html, 'https://x.test/a');

        self::assertCount(1, $found);
        self::assertSame('https://www.youtube-nocookie.com/embed/JSrAQkrp1JI', $found[0]->url);
    }

    public function testTheTagNameCanCarryTheMarker(): void
    {
        $html = '<body><youtube-player data-video-id="M1j_uRqKMKI"></youtube-player></body>';

        self::assertCount(1, $this->source->find($html, 'https://x.test/a'));
    }

    /** Brightcove's in-page embed uses the same attribute with a numeric id. */
    public function testANumericIdOnAnotherProvidersPlayerYieldsNothing(): void
    {
        $html = '<body><video-js data-account="665003303001" data-player="6tKQRAx7lu" '
            . 'data-video-id="6404487520112"></video-js></body>';

        self::assertSame([], $this->source->find($html, 'https://x.test/a'));
    }

    public function testAnIdOnAnElementThatDoesNotNameYouTubeYieldsNothing(): void
    {
        $html = '<body><div class="player" data-video-id="pz8VRrI0p0U"></div></body>';

        self::assertSame([], $this->source->find($html, 'https://x.test/a'));
    }

    public function testATenCharacterIdYieldsNothing(): void
    {
        $html = '<body><div data-component="youtube-atom" data-video-id="pz8VRrI0p0"></div></body>';

        self::assertSame([], $this->source->find($html, 'https://x.test/a'));
    }

    public function testAnOccurrenceInsideFurnitureYieldsNothing(): void
    {
        $html = '<body><aside><div data-component="youtube-atom" data-video-id="pz8VRrI0p0U"></div></aside></body>';

        self::assertSame([], $this->source->find($html, 'https://x.test/a'));
    }

    public function testARepeatedIdYieldsOneCandidateAnchoredWhereItFirstAppears(): void
    {
        $html = '<body><p>' . self::PROSE . '</p>'
            . '<div data-component="youtube-atom" data-video-id="pz8VRrI0p0U"></div>'
            . '<p>Later prose, also long enough to count as a block of the article.</p>'
            . '<div class="embed--youtube" data-video-id="pz8VRrI0p0U"></div></body>';

        $found = $this->source->find($html, 'https://x.test/a');

        self::assertCount(1, $found);
        self::assertSame(self::PROSE, $found[0]->precedingText);
    }

    public function testIgnoresUnparseableHtml(): void
    {
        self::assertSame([], $this->source->find('', 'https://x.test/a'));
    }
}
