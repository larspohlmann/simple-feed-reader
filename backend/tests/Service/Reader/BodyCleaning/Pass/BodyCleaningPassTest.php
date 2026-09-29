<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\BodyCleaning\Pass;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\Media\Model\ArticleMediaModel;
use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Model\MediaKind;
use App\Tests\Support\BodyCleaningInputs;
use PHPUnit\Framework\TestCase;

final class BodyCleaningPassTest extends TestCase
{
    public function testTheDiscoveredMediaAreTheInputMediaWhileTheBodyRecoveredNoEmbed(): void
    {
        $media = $this->embedAndAudio();

        $pass = new BodyCleaningPass(HtmlDocumentParser::parse('<p>Body.</p>'), BodyCleaningInputs::withMedia($media));

        self::assertSame($media, $pass->discoveredMedia());
    }

    public function testARecoveredEmbedDropsTheDiscoveredEmbedsButKeepsTheAudio(): void
    {
        $pass = new BodyCleaningPass(
            HtmlDocumentParser::parse('<p>Body.</p>'),
            BodyCleaningInputs::withMedia($this->embedAndAudio()),
        );

        $pass->recordEmbedsRecoveredInBody();
        $urls = array_map(
            static fn (MediaCandidateModel $candidate): string => $candidate->url,
            $pass->discoveredMedia()->candidates,
        );

        self::assertSame(['https://x.test/a.mp3'], $urls);
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
