<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\BodyCleaning\BodyCleaningInput;
use App\Service\Reader\BodyCleaning\BodyCleaningPass;
use App\Service\Reader\BodyCleaning\BodyCleaningStep\PageMediaPlacement;
use App\Service\Reader\LeadImageCandidate;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use App\Service\Reader\Media\MediaMarkup;
use App\Service\Reader\Media\PageMediaInserter;
use App\Service\Reader\ReaderLeadImage;
use App\Tests\Support\BodyCleaningInputs;
use PHPUnit\Framework\TestCase;

final class PageMediaPlacementTest extends TestCase
{
    private const string PROSE =
        'Ein ausreichend langer Absatz mit echtem Fliesstext, der die Schwelle '
        . 'fuer einen substantiellen Absatz sicher ueberschreitet und daher als '
        . 'echter Artikelinhalt zaehlt und nicht als Randblock behandelt wird.';

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
        $embed = new MediaCandidate(
            MediaKind::Embed,
            'https://www.youtube-nocookie.com/embed/ccccccccccc',
            'https://i.ytimg.example/hqdefault.jpg',
            'Watch',
        );

        $out = $this->placed(BodyCleaningInputs::withLeadImageAndMedia($this->hero(), new ArticleMedia([$embed])));

        self::assertStringNotContainsString('cdn.test/hero.jpg', $out);
        self::assertStringContainsString('i.ytimg.example/hqdefault.jpg', $out);
    }

    /** #907: narration audio is not a lead visual, so the hero stays and the player sits below it. */
    public function testSeatsATopPlacedAudioPlayerBelowTheRestoredHero(): void
    {
        $audio = new ArticleMedia([new MediaCandidate(MediaKind::Audio, 'https://x.test/a.mp3')]);

        $out = $this->placed(BodyCleaningInputs::withLeadImageAndMedia($this->hero(), $audio));

        self::assertStringContainsString('cdn.test/hero.jpg', $out);
        self::assertLessThan(strpos($out, '<audio'), strpos($out, 'cdn.test/hero.jpg'));
    }

    public function testPlacesNoDiscoveredEmbedOnceTheBodyRecoveredItsOwn(): void
    {
        $embed = new MediaCandidate(
            MediaKind::Embed,
            'https://www.youtube-nocookie.com/embed/bbbbbbbbbbb',
            null,
            'Watch',
        );
        $pass = new BodyCleaningPass(
            HtmlDocumentParser::parse('<p>' . self::PROSE . '</p>'),
            BodyCleaningInputs::withMedia(new ArticleMedia([$embed])),
        );
        $pass->recordEmbedsRecoveredInBody();

        $this->placement->cleanIn($pass);

        self::assertStringNotContainsString('bbbbbbbbbbb', $pass->document->saveHtml());
    }

    private function hero(): LeadImageCandidate
    {
        return new LeadImageCandidate('https://cdn.test/hero.jpg', BodyCleaningInputs::pageDrawingNothing());
    }

    private function placed(BodyCleaningInput $input): string
    {
        $pass = new BodyCleaningPass(HtmlDocumentParser::parse('<p>' . self::PROSE . '</p>'), $input);
        $this->placement->cleanIn($pass);

        return $pass->document->saveHtml();
    }
}
