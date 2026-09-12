<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader\AuthorBio;

use App\Service\Html\HtmlDocumentParser;
use App\Service\Reader\AuthorBio\AuthorBioSeparator;
use PHPUnit\Framework\TestCase;

final class AuthorBioSeparatorTest extends TestCase
{
    private const string PROSE =
        'Ein ausreichend langer Absatz mit echtem Fliesstext, der die Schwelle '
        . 'fuer einen substantiellen Absatz sicher ueberschreitet und daher als '
        . 'echter Artikelinhalt zaehlt und nicht als Randblock behandelt wird.';

    private const string BIO =
        'Tim Fernholz is a journalist who writes about technology, finance and '
        . 'public policy. He has closely covered the rise of the private space '
        . 'industry and is the author of a well-known book about it. Formerly he '
        . 'was a senior reporter at a global business news site for over a decade.';

    private AuthorBioSeparator $separator;

    protected function setUp(): void
    {
        $this->separator = new AuthorBioSeparator();
    }

    public function testWrapsTheTrailingBioContainerThatFollowsTheArticleBody(): void
    {
        $body = '<div>'
            . '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p></div>'
            . '<p><em>When you purchase through links we may earn a commission.</em></p>'
            . '<div><div><p>' . self::BIO . '</p></div>'
            . '<p><a href="https://techcrunch.com/author/tim-fernholz/">View Bio</a></p></div>'
            . '</div>';

        $result = $this->separate($body);

        self::assertStringContainsString('<figure class="reader-author-bio">', $result);
        self::assertMatchesRegularExpression(
            '#<figure class="reader-author-bio">.*Tim Fernholz.*View Bio.*</figure>#s',
            $result,
        );
    }

    public function testKeepsTheArticleBodyOutsideTheBioFigure(): void
    {
        $body = '<div>'
            . '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p></div>'
            . '<div><p>' . self::BIO . '</p>'
            . '<p><a href="https://example.com/Author/jane-doe/">More by Jane</a></p></div>'
            . '</div>';

        $result = $this->separate($body);

        $beforeFigure = substr($result, 0, (int) strpos($result, '<figure'));
        self::assertStringContainsString(self::PROSE, $beforeFigure);
        self::assertStringNotContainsString(self::BIO, $beforeFigure);
    }

    public function testLeavesTheBodyUntouchedWhenNoTrailingProfileLinkIsPresent(): void
    {
        $body = '<div>'
            . '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p></div>'
            . '<p><a href="https://example.com/2024/related-story/">Related story</a></p>'
            . '</div>';

        self::assertStringNotContainsString('reader-author-bio', $this->separate($body));
    }

    public function testIgnoresAProfileLinkThatSitsInFrontOfTheArticleBody(): void
    {
        $body = '<div>'
            . '<p><a href="https://example.com/author/jane-doe/">By Jane Doe</a></p>'
            . '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p></div>'
            . '</div>';

        self::assertStringNotContainsString('reader-author-bio', $this->separate($body));
    }

    public function testDoesNotSwallowASecondArticleSectionInTheTrailingRegion(): void
    {
        // Three paragraphs make the first block the article body; the trailing
        // block still holds two, so it is a second section, not a bio, and the
        // profile link inside it does not make it one.
        $body = '<div>'
            . '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p></div>'
            . '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p>'
            . '<p><a href="https://example.com/author/jane-doe/">By Jane</a></p></div>'
            . '</div>';

        self::assertStringNotContainsString('reader-author-bio', $this->separate($body));
    }

    public function testSeparatesABioThatFollowsAShortTwoParagraphArticle(): void
    {
        $body = '<div>'
            . '<div><p>' . self::PROSE . '</p><p>' . self::PROSE . '</p></div>'
            . '<p><a href="https://example.com/author/jane-doe/">View Bio</a></p>'
            . '</div>';

        self::assertStringContainsString('reader-author-bio', $this->separate($body));
    }

    public function testLeavesAProfileLinkAloneWhenThereIsNoSubstantialArticleBody(): void
    {
        // One substantial paragraph in the whole document: there is no article
        // body to set a bio apart from, so a trailing profile link stays put.
        $body = '<div><p>' . self::PROSE . '</p>'
            . '<p><a href="https://example.com/author/jane-doe/">View Bio</a></p></div>';

        self::assertStringNotContainsString('reader-author-bio', $this->separate($body));
    }

    private function separate(string $bodyHtml): string
    {
        $document = HtmlDocumentParser::parseOrNull($bodyHtml);
        self::assertNotNull($document);
        $this->separator->separateIn($document);

        return $document->saveHtml();
    }
}
