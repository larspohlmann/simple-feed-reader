<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\Media;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaMarkup;
use App\Service\Reader\Media\PageMediaInserter;
use PHPUnit\Framework\TestCase;

final class PageMediaInserterTest extends TestCase
{
    private PageMediaInserter $inserter;

    protected function setUp(): void
    {
        $this->inserter = new PageMediaInserter(new MediaMarkup());
    }

    private function insert(string $html, ArticleMedia $media): string
    {
        $document = HtmlDocumentParser::parseOrNull($html);
        self::assertNotNull($document);
        $plan = $this->inserter->plan($document, $media);
        $this->inserter->apply($document, $plan);

        return $document->saveHtml();
    }

    public function testPutsAudioAtTheTopAboveTheTeaser(): void
    {
        $media = new ArticleMedia([new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3')]);

        $out = $this->insert('<body><p>Teaser</p></body>', $media);

        self::assertStringContainsString('<audio controls="" preload="none" src="https://x.test/a.mp3">', $out);
        self::assertLessThan(strpos($out, 'Teaser'), strpos($out, '<audio'));
    }

    public function testVideoCarriesItsPoster(): void
    {
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Video, 'https://x.test/v.mp4', 'https://x.test/p.jpg'),
        ]);

        $out = $this->insert('<body><p>Teaser</p></body>', $media);

        self::assertStringContainsString('poster="https://x.test/p.jpg"', $out);
        self::assertStringContainsString('preload="none"', $out);
    }

    public function testAudioNeverCarriesAPosterAttribute(): void
    {
        // Defect i: <audio> has no poster attribute, so even a candidate that
        // somehow carries a posterUrl must not render one.
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3', 'https://x.test/p.jpg'),
        ]);

        $out = $this->insert('<body><p>Teaser</p></body>', $media);

        self::assertStringNotContainsString('poster', $out);
    }

    public function testAnEmbedBecomesTheSameLinkShapeAsAnInBodyOne(): void
    {
        $media = new ArticleMedia([new MediaCandidate(
            MediaKind::Embed,
            'https://w.soundcloud.com/player/?url=https%3A%2F%2Fapi.soundcloud.com%2Ftracks%2F1',
            null,
            'Listen on SoundCloud',
        )]);

        $out = $this->insert('<body><p>Teaser</p></body>', $media);

        self::assertStringContainsString('Listen on SoundCloud', $out);
        self::assertStringContainsString('w.soundcloud.com/player/', $out);
    }

    public function testEmptyMediaLeavesTheBodyAlone(): void
    {
        $out = $this->insert('<body><p>Teaser</p></body>', ArticleMedia::none());

        self::assertStringNotContainsString('<audio', $out);
        self::assertStringContainsString('Teaser', $out);
    }

    public function testKeepsSourceOrder(): void
    {
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Audio, 'https://x.test/first.mp3'),
            new MediaCandidate(MediaKind::Audio, 'https://x.test/second.mp3'),
        ]);

        $out = $this->insert('<body><p>Teaser</p></body>', $media);

        self::assertLessThan(strpos($out, 'second.mp3'), strpos($out, 'first.mp3'));
    }

    public function testReconcilesAMatchingBodyImageInPlace(): void
    {
        // tagesschau 491512: the same asset UUID, a different rendition.
        $poster = 'https://media.tagesschau.de/image/7ad74081-1234-5678-9abc-def012345678/AAAAAA/16x9-1920/p.jpg';
        $bodyImg = 'https://media.tagesschau.de/image/7ad74081-1234-5678-9abc-def012345678/BBBBBB/16x9-big/t.jpg';
        $media = new ArticleMedia([new MediaCandidate(MediaKind::Video, 'https://x.test/v.mp4', $poster)]);
        $html = '<body><p>Intro</p><figure><img src="' . $bodyImg . '" alt=""></figure><p>Tail</p></body>';

        $out = $this->insert($html, $media);

        self::assertStringNotContainsString('<img', $out);
        self::assertStringContainsString('<video', $out);
        self::assertStringContainsString('poster="' . $poster . '"', $out);
        self::assertLessThan(strpos($out, 'Tail'), strpos($out, '<video'));
        self::assertLessThan(strpos($out, '<video'), strpos($out, 'Intro'));
    }

    public function testDoesNotReconcileAnImageInsideAnAnchor(): void
    {
        // The <a> guard: protects embed poster anchors, SubstackPosterLink's
        // output, and #627's gated placeholder even when the asset matches.
        $poster = 'https://media.tagesschau.de/image/7ad74081-1234-5678-9abc-def012345678/AAAAAA/16x9-1920/p.jpg';
        $bodyImg = 'https://media.tagesschau.de/image/7ad74081-1234-5678-9abc-def012345678/BBBBBB/16x9-big/t.jpg';
        $media = new ArticleMedia([new MediaCandidate(MediaKind::Video, 'https://x.test/v.mp4', $poster)]);
        $html = '<body><p>Intro</p><a href="https://x.test"><img src="' . $bodyImg . '" alt=""></a></body>';

        $out = $this->insert($html, $media);

        self::assertStringContainsString('<img', $out);
        self::assertStringContainsString('<video', $out);
    }

    public function testACandidateWithNoMatchingImageIsTopPlaced(): void
    {
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Video, 'https://x.test/v.mp4', 'https://x.test/no-match-poster.jpg'),
        ]);
        $html = '<body><p>Intro</p><figure><img src="https://x.test/unrelated-photo.jpg" alt=""></figure></body>';

        $out = $this->insert($html, $media);

        self::assertStringContainsString('<img', $out);
        self::assertLessThan(strpos($out, 'Intro'), strpos($out, '<video'));
    }

    public function testTwoCandidatesOneMatchingImageReconcilesOneAndTopPlacesTheOther(): void
    {
        // tagesschau 491912 mix: video1 reconciles, video2 has no match and goes
        // to the top, and an unrelated map img is left untouched.
        $video1Poster = 'https://media.tagesschau.de/image/80085f9c-1234-5678-9abc-def012345678/A/16x9-1920/p.jpg';
        $video1Body = 'https://media.tagesschau.de/image/80085f9c-1234-5678-9abc-def012345678/B/16x9-big/t.jpg';
        $video2Poster = 'https://media.tagesschau.de/image/58e272fd-1234-5678-9abc-def012345678/A/16x9-1920/p.jpg';
        $mapImg = 'https://media.tagesschau.de/image/deadbeef-0000-0000-0000-000000000000/A/map.jpg';

        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Video, 'https://x.test/v1.mp4', $video1Poster),
            new MediaCandidate(MediaKind::Video, 'https://x.test/v2.mp4', $video2Poster),
        ]);
        $html = '<body><p>Intro</p>'
            . '<figure><img src="' . $video1Body . '" alt=""></figure>'
            . '<figure><img src="' . $mapImg . '" alt=""></figure>'
            . '</body>';

        $out = $this->insert($html, $media);

        self::assertSame(2, substr_count($out, '<video'));
        self::assertSame(1, substr_count($out, '<img'));
        self::assertStringContainsString($mapImg, $out);
        self::assertStringContainsString('v2.mp4', $out);
        self::assertStringContainsString($video1Poster, $out);
        self::assertLessThan(strpos($out, 'v1.mp4'), strpos($out, 'v2.mp4'), 'the top-placed video leads');
    }

    private const string PROSE = 'The paragraph the player followed on the source page, long enough to be prose.';

    public function testPlacesACandidateAfterTheBlockItFollowedOnThePage(): void
    {
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Video, 'https://x.test/v.mp4', 'https://x.test/p.jpg', null, self::PROSE),
        ]);
        $html = '<body><p>Intro</p><p>' . self::PROSE . '</p><p>Tail</p></body>';

        $out = $this->insert($html, $media);

        self::assertGreaterThan(strpos($out, self::PROSE), strpos($out, '<video'));
        self::assertLessThan(strpos($out, 'Tail'), strpos($out, '<video'));
    }

    public function testMatchesTheBlockOnCollapsedText(): void
    {
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3', null, null, self::PROSE),
        ]);
        $html = '<body><p>Intro</p><p>  The paragraph the player followed on the source page,' . "
"
            . '   long enough to be prose.  </p><p>Tail</p></body>';

        $out = $this->insert($html, $media);

        self::assertLessThan(strpos($out, 'Tail'), strpos($out, '<audio'));
        self::assertGreaterThan(strpos($out, 'Intro'), strpos($out, '<audio'));
    }

    public function testTwoCandidatesAfterTheSameBlockKeepSourceOrder(): void
    {
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Audio, 'https://x.test/first.mp3', null, null, self::PROSE),
            new MediaCandidate(MediaKind::Audio, 'https://x.test/second.mp3', null, null, self::PROSE),
        ]);
        $html = '<body><p>' . self::PROSE . '</p><p>Tail</p></body>';

        $out = $this->insert($html, $media);

        self::assertGreaterThan(strpos($out, self::PROSE), strpos($out, 'first.mp3'));
        self::assertLessThan(strpos($out, 'second.mp3'), strpos($out, 'first.mp3'));
        self::assertLessThan(strpos($out, 'Tail'), strpos($out, 'second.mp3'));
    }

    public function testFollowsAListItemBlockWithTheWholeList(): void
    {
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3', null, null, self::PROSE),
        ]);
        $html = '<body><ul><li>' . self::PROSE . '</li><li>Second item</li></ul><p>Tail</p></body>';

        $out = $this->insert($html, $media);

        self::assertGreaterThan(strpos($out, '</ul>'), strpos($out, '<audio'));
        self::assertLessThan(strpos($out, 'Tail'), strpos($out, '<audio'));
    }

    public function testACandidateWhoseBlockTheBodyLostIsTopPlaced(): void
    {
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3', null, null, 'A block readability removed'),
        ]);

        $out = $this->insert('<body><p>Intro</p></body>', $media);

        self::assertLessThan(strpos($out, 'Intro'), strpos($out, '<audio'));
    }

    public function testAMatchingBodyImageBeatsTheTextAnchor(): void
    {
        $poster = 'https://media.tagesschau.de/image/7ad74081-1234-5678-9abc-def012345678/AAAAAA/16x9-1920/p.jpg';
        $bodyImg = 'https://media.tagesschau.de/image/7ad74081-1234-5678-9abc-def012345678/BBBBBB/16x9-big/t.jpg';
        $media = new ArticleMedia([
            new MediaCandidate(MediaKind::Video, 'https://x.test/v.mp4', $poster, null, self::PROSE),
        ]);
        $html = '<body><figure><img src="' . $bodyImg . '" alt=""></figure><p>' . self::PROSE . '</p></body>';

        $out = $this->insert($html, $media);

        self::assertSame(1, substr_count($out, '<video'));
        self::assertStringNotContainsString('<img', $out);
        self::assertLessThan(strpos($out, self::PROSE), strpos($out, '<video'));
    }
}
