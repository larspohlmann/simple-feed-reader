<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\ArticleReadability;
use App\Service\Reader\FetchedPageNormalizer;
use App\Service\Reader\Media\EmbedProviders;
use App\Service\Reader\PageResponse;
use App\Service\Reader\RelatedTeaserGridRemover;
use PHPUnit\Framework\TestCase;

final class ArticleReadabilityTest extends TestCase
{
    private const string TEASER_TEXT = 'Ein kurzer Anrisstext, der die verlinkte Schlagzeile begleitet und '
        . 'zusammen mit ihr die Teaser-Karte bildet, lang genug um als Inhalt zu zaehlen fuer den Test.';

    public function testDropsTheRelatedTeaserGridFromTheCollapsedVariantToo(): void
    {
        // article-block-components.html only wins its extraction through the
        // wrapper-chain collapse (#235): the fixture's deeply wrapped rich-text
        // blocks score too low uncollapsed, so richest() picks the collapsed
        // variant. A related-teaser grid appended to that same article (#1002)
        // must be stripped from the collapsed document too, or its orphan
        // thumbnails leak into the winning extraction.
        $html = str_replace(
            '</article>',
            $this->relatedTeaserGrid() . '</article>',
            (string) file_get_contents(__DIR__ . '/../../Fixtures/reader/article-block-components.html'),
        );
        $normalizer = new FetchedPageNormalizer([]);
        $readability = new ArticleReadability($normalizer, new RelatedTeaserGridRemover(), new EmbedProviders([]));
        $page = new PageResponse('https://site.test/post', $html);

        $article = $readability->richest($normalizer->normalize($html), $page, []);

        self::assertNotNull($article);
        self::assertStringNotContainsString('t1.webp', (string) $article->content);
    }

    private function relatedTeaserGrid(): string
    {
        return '<div class="contentbox list">'
            . $this->teaserCard('t1.webp', '/nachrichten/a,prozess-1.html', 'Prozess: Zwoelf Jahre Haft')
            . $this->teaserCard('t2.webp', '/nachrichten/b,prozess-2.html', 'Lange Haftstrafen nach Kokainfund')
            . $this->teaserCard('t3.webp', '/nachrichten/c,prozess-3.html', 'Ermittler stellen Rekordmenge sicher')
            . '</div>';
    }

    private function teaserCard(string $image, string $href, string $headline): string
    {
        return '<div class="teaser"><div class="teaserimage"><img src="https://images.ndr.de/' . $image . '" alt="" />'
            . '</div><div class="teaserpadding"><div class="headlinewrapper"><h2>'
            . '<a href="' . $href . '">' . $headline . '</a></h2></div>'
            . '<div class="teasertext"><p>' . self::TEASER_TEXT . '</p></div></div></div>';
    }
}
