<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Image\DeclaredImage;
use App\Service\Reader\HeroImageSelector;
use PHPUnit\Framework\TestCase;

final class HeroImageSelectorTest extends TestCase
{
    private HeroImageSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new HeroImageSelector();
    }

    public function testKeepsTheArticleHeroWhenTextPrecedesABodyImage(): void
    {
        $hero = new DeclaredImage('https://cdn.test/article.jpg');
        $body = '<p>Intro.</p><img src="https://cdn.test/body.jpg" alt="">';

        self::assertSame($hero, $this->selector->selectArticleHero($hero, $body));
    }

    public function testSuppressesTheArticleHeroWhenTheBodyLeadsWithAnImage(): void
    {
        $hero = new DeclaredImage('https://cdn.test/article.jpg');
        $body = '<figure><img src="https://cdn.test/body.jpg" alt=""></figure><p>Intro.</p>';

        self::assertNull($this->selector->selectArticleHero($hero, $body));
    }

    public function testSuppressesTheFeedHeroWhenTheBodyContainsAnImage(): void
    {
        $hero = new DeclaredImage('https://cdn.test/feed.jpg');
        $body = '<p>Intro.</p><img src="https://cdn.test/article.jpg" alt="">';

        self::assertNull($this->selector->selectFeedHero($hero, $body));
    }

    public function testKeepsTheArticleHeroWhenTheBodyRepeatsItAfterText(): void
    {
        $hero = new DeclaredImage('https://cdn.test/article.jpg');
        $body = '<p>Intro.</p><img src="https://cdn.test/article.jpg" alt="">';

        self::assertSame($hero, $this->selector->selectArticleHero($hero, $body));
    }

    public function testKeepsTheArticleHeroWhenANonBreakingSpacePrecedesAnImage(): void
    {
        $hero = new DeclaredImage('https://cdn.test/article.jpg');
        $body = "<p>\u{00A0}</p><img src=\"https://cdn.test/body.jpg\" alt=\"\">";

        self::assertSame($hero, $this->selector->selectArticleHero($hero, $body));
    }

    public function testDoesNotMistakeAnImageNamedElementForAnImage(): void
    {
        $hero = new DeclaredImage('https://cdn.test/feed.jpg');

        self::assertSame($hero, $this->selector->selectFeedHero($hero, '<imgur-embed></imgur-embed>'));
    }

    public function testSuppressesTheFeedHeroForAnImageWithoutASource(): void
    {
        $hero = new DeclaredImage('https://cdn.test/feed.jpg');

        self::assertNull($this->selector->selectFeedHero($hero, '<p>Intro.</p><img alt="decorative">'));
    }

    public function testKeepsTheFeedHeroWhenTheBodyHasNoImage(): void
    {
        $hero = new DeclaredImage('https://cdn.test/feed.jpg', 800, 450);

        $selected = $this->selector->selectFeedHero($hero, '<p>Just words.</p>');

        self::assertSame($hero, $selected);
        self::assertSame(800, $selected->width);
        self::assertSame(450, $selected->height);
    }

    public function testRejectsANonHttpHeroFromEitherSource(): void
    {
        $hero = new DeclaredImage('javascript:alert(1)');

        self::assertNull($this->selector->selectArticleHero($hero, '<p>Body.</p>'));
        self::assertNull($this->selector->selectFeedHero($hero, '<p>Body.</p>'));
    }

    public function testKeepsAHeroWhenTheBodyIsUnparsable(): void
    {
        $hero = new DeclaredImage('https://cdn.test/hero.jpg');

        self::assertSame($hero, $this->selector->selectFeedHero($hero, "\0"));
    }
}
