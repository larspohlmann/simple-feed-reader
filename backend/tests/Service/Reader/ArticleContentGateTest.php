<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\ArticleContentGate;
use App\Service\Reader\Exception\ArticleNotExtractedException;
use App\Service\Reader\Media\Model\ArticleMediaModel;
use App\Service\Reader\Media\Model\MediaCandidateModel;
use App\Service\Reader\Media\Model\MediaKind;
use App\Service\Reader\Model\ExtractionFailure;
use fivefilters\Readability\Article;
use PHPUnit\Framework\TestCase;

final class ArticleContentGateTest extends TestCase
{
    public function testHandsBackTheContentOfALongEnoughArticle(): void
    {
        $article = $this->article('<p>body</p>', str_repeat('a', 200));

        self::assertSame('<p>body</p>', (new ArticleContentGate())->contentOf(
            $article,
            ArticleMediaModel::none(),
        ));
    }

    public function testRefusesAnArticleReadabilityFoundNoContentFor(): void
    {
        $this->assertRefused($this->article(null, str_repeat('a', 500)), ArticleMediaModel::none());
    }

    public function testRefusesAShortArticleWithoutMedia(): void
    {
        $this->assertRefused($this->article('<p>body</p>', str_repeat('a', 199)), ArticleMediaModel::none());
    }

    public function testAcceptsAShortArticleWhoseMediaCarriesIt(): void
    {
        $media = new ArticleMediaModel([new MediaCandidateModel(MediaKind::Video, 'https://x.test/clip.mp4')]);
        $gate = new ArticleContentGate();

        self::assertSame('<p>body</p>', $gate->contentOf($this->article('<p>body</p>', 'short'), $media));
    }

    public function testCountsTheCharactersOfTheTrimmedText(): void
    {
        $padded = '   ' . str_repeat('ü', 199) . '   ';

        $this->assertRefused($this->article('<p>body</p>', $padded), ArticleMediaModel::none());
    }

    private function assertRefused(Article $article, ArticleMediaModel $media): void
    {
        try {
            (new ArticleContentGate())->contentOf($article, $media);
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
