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

    /**
     * The rule is about URLs, so the cases below state URLs. Dimensions ride
     * along untouched and have their own case at the end of this class.
     */
    private function selectUrl(?string $candidateUrl, string $bodyHtml): ?string
    {
        $candidate = $candidateUrl === null ? null : new DeclaredImage($candidateUrl);

        return $this->selector->select($candidate, $bodyHtml)?->url;
    }

    public function testKeepsTheHeroWhenTheBodyHasNoImage(): void
    {
        $hero = 'https://cdn.test/hero.jpg';

        self::assertSame($hero, $this->selectUrl($hero, '<p>Just words.</p>'));
    }

    public function testSuppressesTheHeroWhenTheBodyLeadsWithAnImage(): void
    {
        $hero = 'https://cdn.test/hero.jpg';
        $body = '<figure><img src="https://cdn.test/body.jpg" alt=""></figure><p>Body.</p>';

        self::assertNull($this->selectUrl($hero, $body));
    }

    public function testSuppressesTheHeroWhenAnImageFollowsBodyText(): void
    {
        // The #657 rule is coarse: any image suppresses the hero, wherever it
        // sits. beat.de opens with a paragraph, then repeats the photo as a
        // different CDN file — a second copy on top would stack the same picture.
        $hero = 'https://cdn.test/hero.jpg';
        $body = '<p>Intro paragraph.</p><figure><img src="https://cdn.test/body.jpg" alt=""></figure>';

        self::assertNull($this->selectUrl($hero, $body));
    }

    public function testSuppressesTheHeroWhenAnInlineImageSitsInsideText(): void
    {
        $hero = 'https://cdn.test/hero.jpg';
        $body = '<p>An intro sentence <img src="https://cdn.test/body.jpg" alt=""> mid paragraph.</p>';

        self::assertNull($this->selectUrl($hero, $body));
    }

    public function testSuppressesTheHeroForAnImageWithoutASource(): void
    {
        // A sourceless <img> is still an image element in the body; the rule
        // does not read src, so it suppresses the hero all the same.
        $hero = 'https://cdn.test/hero.jpg';

        self::assertNull($this->selectUrl($hero, '<p>Intro.</p><img alt="decorative">'));
    }

    public function testIsNotFooledByAnElementWhoseNameMerelyStartsWithImg(): void
    {
        // The rule matches the element name `img` exactly, so an `<imgur-embed>`
        // is not a body image and the hero stands.
        $hero = 'https://cdn.test/hero.jpg';

        self::assertSame($hero, $this->selectUrl($hero, '<p>see the <imgur-embed></imgur-embed></p>'));
    }

    public function testAcceptsAnUppercaseScheme(): void
    {
        // The http(s) guard is case-insensitive, so an upper-cased scheme is a
        // valid hero rather than a discarded one.
        $hero = 'HTTPS://cdn.test/hero.jpg';

        self::assertSame($hero, $this->selectUrl($hero, '<p>Just words.</p>'));
    }

    public function testDiscardsANonHttpHero(): void
    {
        // The scheme guard is anchored: a `data:` URL that merely embeds an
        // http(s) address later in its payload is still rejected.
        self::assertNull($this->selectUrl('javascript:alert(1)', '<p>Body.</p>'));
        self::assertNull($this->selectUrl('data:text/html,<a href="http://evil.test">x</a>', '<p>Body.</p>'));
    }

    public function testReturnsNullWhenThereIsNoHero(): void
    {
        self::assertNull($this->selectUrl(null, '<p>Body.</p>'));
    }

    public function testKeepsAHeroWhenTheBodyIsUnparsable(): void
    {
        $hero = 'https://cdn.test/hero.jpg';

        self::assertSame($hero, $this->selectUrl($hero, "\0"));
    }

    public function testKeepsTheDeclaredDimensionsOfAnAcceptedHero(): void
    {
        // The dimensions are the client's aspect-ratio reservation, so the
        // selector must hand back the candidate itself, not a rebuilt copy.
        $hero = new DeclaredImage('https://cdn.test/hero.jpg', 800, 450);

        $selected = $this->selector->select($hero, '<p>Just words.</p>');

        self::assertSame($hero, $selected);
        self::assertSame(800, $selected->width);
        self::assertSame(450, $selected->height);
    }
}
