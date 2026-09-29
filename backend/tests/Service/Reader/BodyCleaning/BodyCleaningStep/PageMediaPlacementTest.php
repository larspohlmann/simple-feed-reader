<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\BodyCleaningStep\PageMediaPlacement;
use App\Service\Reader\BodyCleaning\Model\BodyCleaningInputModel;
use App\Service\Reader\BodyCleaning\Pass\BodyCleaningPass;
use App\Service\Reader\Media\MediaMarkup;
use App\Service\Reader\Media\Model\ArticleMediaModel;
use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Model\MediaKind;
use App\Service\Reader\Media\PageMediaInserter;
use App\Service\Reader\Model\LeadImageCandidateModel;
use App\Service\Reader\ReaderLeadImage;
use App\Tests\Support\BodyCleaningInputs;
use App\Tests\Support\ParsesHtml;
use App\Tests\Support\ProseParagraphs;
use PHPUnit\Framework\TestCase;

final class PageMediaPlacementTest extends TestCase
{
    use ParsesHtml;

    private PageMediaPlacement $placement;

    protected function setUp(): void
    {
        $this->placement = new PageMediaPlacement(new PageMediaInserter(new MediaMarkup()), new ReaderLeadImage());
    }

    public function testRestoresTheHeroIntoABodyWithNoMediaToPlace(): void
    {
        $out = $this->placed(BodyCleaningInputs::withLeadImage($this->hero()));

        self::assertStringContainsString('cdn.test/hero.jpg', $out);
    }

    public function testSkipsTheHeroWhenAnEmbedIsTopPlaced(): void
    {
        $embed = new MediaCandidateModel(
            MediaKind::Embed,
            'https://www.youtube-nocookie.com/embed/ccccccccccc',
            'https://i.ytimg.example/hqdefault.jpg',
            'Watch',
        );

        $out = $this->placed(BodyCleaningInputs::withLeadImageAndMedia($this->hero(), new ArticleMediaModel([$embed])));

        self::assertStringNotContainsString('cdn.test/hero.jpg', $out);
        self::assertStringContainsString('i.ytimg.example/hqdefault.jpg', $out);
    }

    public function testSeatsATopPlacedAudioPlayerBelowTheRestoredHero(): void
    {
        $audio = new ArticleMediaModel([new MediaCandidateModel(MediaKind::Audio, 'https://x.test/a.mp3')]);

        $out = $this->placed(BodyCleaningInputs::withLeadImageAndMedia($this->hero(), $audio));

        self::assertStringContainsString('cdn.test/hero.jpg', $out);
        self::assertLessThan(strpos($out, '<audio'), strpos($out, 'cdn.test/hero.jpg'));
    }

    public function testPlacesNoDiscoveredEmbedOnceTheBodyRecoveredItsOwn(): void
    {
        $embed = new MediaCandidateModel(
            MediaKind::Embed,
            'https://www.youtube-nocookie.com/embed/bbbbbbbbbbb',
            null,
            'Watch',
        );
        $pass = new BodyCleaningPass(
            $this->document('<p>' . ProseParagraphs::SUBSTANTIAL . '</p>'),
            BodyCleaningInputs::withMedia(new ArticleMediaModel([$embed])),
        );
        $pass->recordEmbedsRecoveredInBody();

        $this->placement->cleanIn($pass);

        self::assertStringNotContainsString('bbbbbbbbbbb', $pass->document->saveHtml());
    }

    private function hero(): LeadImageCandidateModel
    {
        return new LeadImageCandidateModel('https://cdn.test/hero.jpg', BodyCleaningInputs::pageDrawingNothing());
    }

    private function placed(BodyCleaningInputModel $input): string
    {
        $pass = new BodyCleaningPass($this->document('<p>' . ProseParagraphs::SUBSTANTIAL . '</p>'), $input);
        $this->placement->cleanIn($pass);

        return $pass->document->saveHtml();
    }
}
