<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\LeadImageSelector;
use PHPUnit\Framework\TestCase;

final class LeadImageSelectorTest extends TestCase
{
    private LeadImageSelector $selector;

    protected function setUp(): void
    {
        $this->selector = new LeadImageSelector();
    }

    public function testShowsHeroWhenTheBodyImageIsADifferentPicture(): void
    {
        // The mopo entry 470210 case: the og:image hero (image id 4943510) is not
        // in the extracted body, which only shows a later photo (4943526).
        $hero = 'https://image.mopo.de/4943510.jpg?imageId=4943510&width=1200';
        $body = '<p>Lead.</p><figure><img src="https://image.mopo.de/4943526.jpg?imageId=4943526" alt=""></figure>';

        self::assertSame($hero, $this->selector->select($hero, $body));
    }

    public function testSuppressesHeroWhenTheBodyShowsTheSameImageUnderASizeVariantUrl(): void
    {
        // Same photo, different size and format: the body opens with the hero
        // already, so a second copy on top would be redundant.
        $hero = 'https://cdn.test/4943510.jpg?width=1200';
        $body = '<figure><img src="https://cdn.test/4943510.webp?width=960" alt=""></figure><p>Body.</p>';

        self::assertNull($this->selector->select($hero, $body));
    }

    public function testShowsHeroWhenTheBodyHasNoImage(): void
    {
        $hero = 'https://cdn.test/hero.jpg';

        self::assertSame($hero, $this->selector->select($hero, '<p>Just words.</p>'));
    }

    public function testSuppressesHeroWhenOnlyTheQueryStringDiffers(): void
    {
        $hero = 'https://cdn.test/photo.jpg?v=1';
        $body = '<img src="https://cdn.test/photo.jpg?v=2" alt="">';

        self::assertNull($this->selector->select($hero, $body));
    }

    public function testMatchesTheImageIdentityRegardlessOfCase(): void
    {
        $hero = 'https://cdn.test/Photo.JPG';
        $body = '<IMG SRC="https://cdn.test/photo.jpg" ALT="">';

        self::assertNull($this->selector->select($hero, $body));
    }

    public function testMatchesASingleQuotedBodyImage(): void
    {
        $hero = 'https://cdn.test/hero.jpg';
        $body = "<img src='https://cdn.test/hero.webp' alt=''>";

        self::assertNull($this->selector->select($hero, $body));
    }

    public function testAcceptsAnUppercaseScheme(): void
    {
        // The http(s) guard is case-insensitive, so an upper-cased scheme is a
        // valid hero rather than a discarded one.
        $hero = 'HTTPS://cdn.test/hero.jpg';

        self::assertSame($hero, $this->selector->select($hero, '<p>Just words.</p>'));
    }

    public function testShowsTheHeroWhenItHasNoPathBasename(): void
    {
        // parse_url returns no path for a bare host; the hero keeps its whole
        // form as its identity and still leads over a different body image.
        $hero = 'https://cdn.test';
        $body = '<img src="https://cdn.test/photo.jpg" alt="">';

        self::assertSame($hero, $this->selector->select($hero, $body));
    }

    public function testDiscardsANonHttpHero(): void
    {
        // The scheme guard is anchored: a `data:` URL that merely embeds an
        // http(s) address later in its payload is still rejected.
        self::assertNull($this->selector->select('javascript:alert(1)', '<p>Body.</p>'));
        self::assertNull($this->selector->select('data:text/html,<a href="http://evil.test">x</a>', '<p>Body.</p>'));
    }

    public function testReturnsNullWhenThereIsNoHero(): void
    {
        self::assertNull($this->selector->select(null, '<p>Body.</p>'));
    }
}
