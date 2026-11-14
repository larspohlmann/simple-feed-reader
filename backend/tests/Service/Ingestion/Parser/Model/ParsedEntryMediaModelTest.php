<?php

declare(strict_types=1);

namespace App\Tests\Service\Parser\Model;

use App\Service\Image\Model\DeclaredImageModel;
use App\Service\Parser\Model\FeedMediaKind;
use App\Service\Parser\Model\ParsedAttachmentModel;
use App\Service\Parser\Model\ParsedEntryMediaModel;
use App\Service\Parser\Model\ParsedMediaBundleModel;
use PHPUnit\Framework\TestCase;

final class ParsedEntryMediaModelTest extends TestCase
{
    private DeclaredImageModel $showArtwork;

    protected function setUp(): void
    {
        $this->showArtwork = new DeclaredImageModel('https://i/show.jpg');
    }

    public function testAnEpisodeWithoutAnImageTakesTheShowArtwork(): void
    {
        $episode = new ParsedEntryMediaModel(null, new ParsedMediaBundleModel([], [
            new ParsedAttachmentModel('https://c/ep.mp3', FeedMediaKind::Audio),
        ]));

        self::assertSame($this->showArtwork, $episode->withShowArtwork($this->showArtwork)->image);
    }

    public function testAnEntryWithoutMediaStaysWithoutAnImage(): void
    {
        $media = new ParsedEntryMediaModel();

        self::assertSame($media, $media->withShowArtwork($this->showArtwork));
    }
}
