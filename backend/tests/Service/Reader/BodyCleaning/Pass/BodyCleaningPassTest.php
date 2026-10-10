<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\BodyCleaning\Pass;

use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\Media\Model\ArticleMediaModel;
use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Model\MediaKind;
use App\Tests\Support\BodyCleaningInputs;
use App\Tests\Support\ParsesHtml;
use App\Tests\Support\ReadsCandidateUrls;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BodyCleaningPassTest extends TestCase
{
    use ParsesHtml;
    use ReadsCandidateUrls;

    public function testTheDiscoveredMediaAreTheInputMediaWhileTheBodyRecoveredNoEmbed(): void
    {
        $media = $this->embedAndAudio();

        $pass = new BodyCleaningPass($this->document('<p>Body.</p>'), BodyCleaningInputs::withMedia($media));

        self::assertSame($media, $pass->discoveredMedia());
    }

    public function testARecoveredEmbedDropsTheDiscoveredEmbedsButKeepsTheAudio(): void
    {
        $pass = new BodyCleaningPass(
            $this->document('<p>Body.</p>'),
            BodyCleaningInputs::withMedia($this->embedAndAudio()),
        );

        $pass->recordEmbedsRecoveredInBody();

        self::assertSame(['https://x.test/a.mp3'], $this->urlsOf($pass->discoveredMedia()->candidates));
    }

    /** @return iterable<string, array{string}> */
    public static function bodyPlayers(): iterable
    {
        yield 'a video with a src' => ['<video controls src="https://x.test/body.mp4"></video>'];
        yield 'a video with sources' => ['<video controls><source src="https://x.test/body.mp4"></video>'];
    }

    #[DataProvider('bodyPlayers')]
    public function testABodyThatPlaysAVideoDropsThePageVideosButKeepsTheRest(string $player): void
    {
        $pass = new BodyCleaningPass(
            $this->document('<p>Body.</p>' . $player),
            BodyCleaningInputs::withMedia($this->everyKind()),
        );

        self::assertSame(
            ['https://www.youtube-nocookie.com/embed/bbbbbbbbbbb', 'https://x.test/a.mp3'],
            $this->urlsOf($pass->discoveredMedia()->candidates),
        );
    }

    public function testADecorativeBodyVideoWithoutControlsKeepsThePageVideos(): void
    {
        $media = $this->everyKind();

        $pass = new BodyCleaningPass(
            $this->document('<p>Body.</p><video autoplay loop muted src="https://x.test/loop.mp4"></video>'),
            BodyCleaningInputs::withMedia($media),
        );

        self::assertSame($media, $pass->discoveredMedia());
    }

    private function everyKind(): ArticleMediaModel
    {
        return new ArticleMediaModel([
            new MediaCandidateModel(MediaKind::Video, 'https://x.test/master.mov'),
            ...$this->embedAndAudio()->candidates,
            new MediaCandidateModel(MediaKind::Stream, 'https://x.test/master.m3u8'),
        ]);
    }

    private function embedAndAudio(): ArticleMediaModel
    {
        return new ArticleMediaModel([
            new MediaCandidateModel(
                MediaKind::Embed,
                'https://www.youtube-nocookie.com/embed/bbbbbbbbbbb',
                null,
                'Watch',
            ),
            new MediaCandidateModel(MediaKind::Audio, 'https://x.test/a.mp3'),
        ]);
    }
}
