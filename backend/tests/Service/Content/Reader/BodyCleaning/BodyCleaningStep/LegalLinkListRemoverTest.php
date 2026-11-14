<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\BodyCleaning\BodyCleaningStep;

use App\Service\Reader\BodyCleaning\BodyCleaningStep\LegalLinkListRemover;
use App\Service\Reader\LinkListDetector;
use App\Tests\Support\BodyCleaningPasses;
use App\Tests\Support\ParsesHtml;
use App\Tests\Support\ProseParagraphs;
use PHPUnit\Framework\TestCase;

final class LegalLinkListRemoverTest extends TestCase
{
    use ParsesHtml;

    private const string SITE_FOOTER = '<p><a href="/popular/searches">Popular searches</a>'
        . ' <a href="/pages/privacy">Privacy Policy</a> <a href="/pages/cookies">Cookie Policy</a></p>';

    private LegalLinkListRemover $remover;

    protected function setUp(): void
    {
        $this->remover = new LegalLinkListRemover(new LinkListDetector());
    }

    public function testRemovesTheSiteFooterBelowAnArticle(): void
    {
        $result = $this->cleaned('<div><p>' . ProseParagraphs::SUBSTANTIAL . '</p>' . self::SITE_FOOTER . '</div>');

        self::assertStringNotContainsString('Privacy Policy', $result);
        self::assertStringContainsString(ProseParagraphs::SUBSTANTIAL, $result);
    }

    public function testRemovesTheSiteFooterBelowAThinTrackPage(): void
    {
        $result = $this->cleaned(
            '<div><dl><dt>Genre</dt><dd><a href="/tags/Techno">Techno</a></dd></dl>' . self::SITE_FOOTER . '</div>'
        );

        self::assertStringNotContainsString('Popular searches', $result);
        self::assertStringContainsString('Techno', $result);
    }

    public function testRemovesOnlyTheInnermostList(): void
    {
        $result = $this->cleaned('<div><p>Short note <a href="/x">here</a>.</p>' . self::SITE_FOOTER . '</div>');

        self::assertStringContainsString('Short note', $result);
        self::assertStringNotContainsString('Cookie Policy', $result);
    }

    public function testKeepsALinkListWhoseLinkOnlyMentionsALegalPage(): void
    {
        $sources = '<p><a href="/a">Our privacy policy changed</a> <a href="/b">Source two</a>'
            . ' <a href="/c">Source three</a></p>';

        self::assertStringContainsString('Source three', $this->cleaned('<div>' . $sources . '</div>'));
    }

    public function testKeepsALegalLinkInsideProse(): void
    {
        $note = '<p>Details in our <a href="/privacy">Privacy Policy</a>, which explains how we store data.</p>';

        self::assertStringContainsString('Privacy Policy', $this->cleaned('<div>' . $note . '</div>'));
    }

    public function testKeepsAListOfFewerThanThreeLinks(): void
    {
        $pair = '<p><a href="/privacy">Privacy Policy</a> <a href="/imprint">Impressum</a></p>';

        self::assertStringContainsString('Impressum', $this->cleaned('<div>' . $pair . '</div>'));
    }

    private function cleaned(string $bodyHtml): string
    {
        $document = $this->document($bodyHtml);
        $this->remover->cleanIn(BodyCleaningPasses::over($document));

        return $document->saveHtml();
    }
}
