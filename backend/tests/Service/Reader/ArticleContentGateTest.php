<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\ArticleContentGate;
use App\Service\Reader\Exception\ArticleNotExtractedException;
use App\Service\Reader\ExtractionFailure;
use App\Service\Reader\Media\ArticleMedia;
use App\Service\Reader\Media\MediaCandidate;
use App\Service\Reader\Media\MediaKind;
use fivefilters\Readability\Article;
use PHPUnit\Framework\TestCase;

final class ArticleContentGateTest extends TestCase
{
    public function testHandsBackTheContentOfALongEnoughArticle(): void
    {
        $article = $this->article('<p>body</p>', str_repeat('a', 200));

        self::assertSame('<p>body</p>', ArticleContentGate::contentOf($article, ArticleMedia::none()));
    }

    public function testRefusesAnArticleReadabilityFoundNoContentFor(): void
    {
        $this->assertRefused($this->article(null, str_repeat('a', 500)), ArticleMedia::none());
    }

    public function testRefusesAShortArticleWithoutMedia(): void
    {
        $this->assertRefused($this->article('<p>body</p>', str_repeat('a', 199)), ArticleMedia::none());
    }

    /** #748: recovered media is itself evidence of an article, so a thin text passes. */
    public function testAcceptsAShortArticleWhoseMediaCarriesIt(): void
    {
        $media = new ArticleMedia([new MediaCandidate(MediaKind::Video, 'https://x.test/clip.mp4')]);

        self::assertSame('<p>body</p>', ArticleContentGate::contentOf($this->article('<p>body</p>', 'short'), $media));
    }

    public function testCountsTheCharactersOfTheTrimmedText(): void
    {
        $padded = '   ' . str_repeat('ü', 199) . '   ';

        $this->assertRefused($this->article('<p>body</p>', $padded), ArticleMedia::none());
    }

    private function assertRefused(Article $article, ArticleMedia $media): void
    {
        try {
            ArticleContentGate::contentOf($article, $media);
            self::fail('Expected the article to be refused.');
        } catch (ArticleNotExtractedException $refusal) {
            self::assertSame(ExtractionFailure::Empty, $refusal->failure);
        }
    }

    private function article(?string $content, string $textContent): Article
    {
        return new Article(
            title: 'Title',
            byline: null,
            dir: null,
            lang: null,
            content: $content,
            textContent: $textContent,
            length: mb_strlen($textContent),
            excerpt: null,
            siteName: null,
            publishedTime: null,
            image: null,
            images: [],
            contentElement: null,
        );
    }
}
