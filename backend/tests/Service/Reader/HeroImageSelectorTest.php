<?php

declare(strict_types=1);

namespace App\Tests\Service\Reader;

use App\Service\Reader\HeroImage;
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
        $candidate = $candidateUrl === null ? null : new HeroImage($candidateUrl);

        return $this->selector->select($candidate, $bodyHtml)?->url;
    }

    public function testShowsHeroWhenTheBodyImageIsADifferentPicture(): void
    {
        // The mopo entry 470210 case: the og:image hero (image id 4943510) is not
        // in the extracted body, which leads with text and only shows a later
        // photo (4943526) further down, so the hero still leads.
        $hero = 'https://image.mopo.de/4943510.jpg?imageId=4943510&width=1200';
        $body = '<p>Lead.</p><figure><img src="https://image.mopo.de/4943526.jpg?imageId=4943526" alt=""></figure>';

        self::assertSame($hero, $this->selectUrl($hero, $body));
    }

    public function testSuppressesHeroWhenTheBodyLeadsWithADifferentPhoto(): void
    {
        // The core #520 invariant: a hero must never stack on top of a body that
        // already opens with a picture, even a genuinely different one.
        $hero = 'https://cdn.test/hero.jpg';
        $body = '<figure><img src="https://cdn.test/different.jpg" alt="">'
            . '<figcaption>Credit</figcaption></figure><p>Body.</p>';

        self::assertNull($this->selectUrl($hero, $body));
    }

    public function testSuppressesHeroWhenALinkedImageLeadsTheBody(): void
    {
        // The leading image is wrapped in a link; it still opens the body.
        $hero = 'https://cdn.test/hero.jpg';
        $body = '<a href="https://cdn.test/full"><img src="https://cdn.test/different.jpg" alt=""></a><p>Body.</p>';

        self::assertNull($this->selectUrl($hero, $body));
    }

    public function testShowsHeroWhenTextPrecedesAnInlineImageInTheFirstBlock(): void
    {
        // Text renders before the image, so the two never stack at the top.
        $hero = 'https://cdn.test/hero.jpg';
        $body = '<p>An intro sentence <img src="https://cdn.test/different.jpg" alt=""> mid paragraph.</p>';

        self::assertSame($hero, $this->selectUrl($hero, $body));
    }

    public function testShowsHeroWhenANonBreakingSpaceLeadsBeforeTheImage(): void
    {
        // A non-breaking space is visible text, not layout whitespace, so it
        // counts as a text lead and the hero stays. Both ends agree on this.
        $hero = 'https://cdn.test/hero.jpg';
        $body = "<p>\u{00A0}</p><figure><img src=\"https://cdn.test/different.jpg\" alt=\"\"></figure>";

        self::assertSame($hero, $this->selectUrl($hero, $body));
    }

    public function testSuppressesHeroWhenTheBodyRepeatsTheHeroLowerDownUnderATextLead(): void
    {
        // The #505 dedup, retained by the union rule: the body leads with text,
        // so the lead-position rule does not fire, but it repeats the hero photo
        // (a size variant) lower down, so a second copy would be redundant.
        $hero = 'https://cdn.test/4943510.jpg?width=1200';
        $body = '<p>Intro.</p><figure><img src="https://cdn.test/4943510.webp?width=960" alt=""></figure>';

        self::assertNull($this->selectUrl($hero, $body));
    }

    public function testSuppressesHeroWhenLayoutWhitespacePrecedesTheLeadingImage(): void
    {
        // Pretty-printed HTML puts newlines and spaces before the first <img>;
        // that layout whitespace is not a text lead, so the image still leads.
        $hero = 'https://cdn.test/hero.jpg';
        $body = "<figure>\n    <img src=\"https://cdn.test/different.jpg\" alt=\"\">\n</figure>";

        self::assertNull($this->selectUrl($hero, $body));
    }

    public function testSuppressesHeroWhenTheHeroPhotoIsNotTheFirstBodyImage(): void
    {
        // The body leads with text and a different photo, then repeats the hero
        // photo further down; the hero would still be a redundant second copy.
        $hero = 'https://cdn.test/4943510.jpg?width=1200';
        $body = '<p>Intro.</p><img src="https://cdn.test/other.jpg" alt="">'
            . '<img src="https://cdn.test/4943510.webp?width=960" alt="">';

        self::assertNull($this->selectUrl($hero, $body));
    }

    public function testIgnoresABodyImageThatHasNoSource(): void
    {
        // An <img> without a usable src is not a picture the reader shows, so it
        // neither leads the body nor counts as a repeat of the hero.
        $hero = 'https://cdn.test/hero.jpg';
        $body = '<p>Intro.</p><img alt="decorative, no source">';

        self::assertSame($hero, $this->selectUrl($hero, $body));
    }

    public function testSuppressesHeroWhenTheBodyShowsTheSameImageUnderASizeVariantUrl(): void
    {
        // Same photo, different size and format: the body opens with the hero
        // already, so a second copy on top would be redundant.
        $hero = 'https://cdn.test/4943510.jpg?width=1200';
        $body = '<figure><img src="https://cdn.test/4943510.webp?width=960" alt=""></figure><p>Body.</p>';

        self::assertNull($this->selectUrl($hero, $body));
    }

    public function testShowsHeroWhenTheBodyHasNoImage(): void
    {
        $hero = 'https://cdn.test/hero.jpg';

        self::assertSame($hero, $this->selectUrl($hero, '<p>Just words.</p>'));
    }

    public function testSuppressesHeroWhenOnlyTheQueryStringDiffers(): void
    {
        $hero = 'https://cdn.test/photo.jpg?v=1';
        $body = '<img src="https://cdn.test/photo.jpg?v=2" alt="">';

        self::assertNull($this->selectUrl($hero, $body));
    }

    public function testMatchesTheImageIdentityRegardlessOfCase(): void
    {
        // The body leads with text, so only the repeat rule can suppress the
        // hero here: the two URLs match on identity alone, which they do only
        // because the identity is lower-cased on both sides.
        $hero = 'https://cdn.test/Photo.JPG';
        $body = '<p>Intro.</p><IMG SRC="https://cdn.test/photo.jpg" ALT="">';

        self::assertNull($this->selectUrl($hero, $body));
    }

    public function testMatchesAPathlessImageIdentityRegardlessOfCase(): void
    {
        // A pathless URL is its own identity, and that fallback is lower-cased
        // too, so a host that differs only in case is still the same picture.
        // The body leads with text, so only the repeat rule can fire.
        $hero = 'https://CDN.test';
        $body = '<p>Intro.</p><img src="https://cdn.test" alt="">';

        self::assertNull($this->selectUrl($hero, $body));
    }

    public function testShowsHeroWhenTwoPathlessUrlsDifferOnlyInTheirHost(): void
    {
        // A lone slash names no photo, so trimming it makes each URL its own
        // identity and two different hosts stay distinct — the hero still
        // leads. Without the trim both paths collapse to "/" and wrongly match.
        $hero = 'https://a.test/';
        $body = '<p>Intro.</p><img src="https://b.test/" alt="">';

        self::assertSame($hero, $this->selectUrl($hero, $body));
    }

    public function testMatchesASingleQuotedBodyImage(): void
    {
        $hero = 'https://cdn.test/hero.jpg';
        $body = "<img src='https://cdn.test/hero.webp' alt=''>";

        self::assertNull($this->selectUrl($hero, $body));
    }

    public function testAcceptsAnUppercaseScheme(): void
    {
        // The http(s) guard is case-insensitive, so an upper-cased scheme is a
        // valid hero rather than a discarded one.
        $hero = 'HTTPS://cdn.test/hero.jpg';

        self::assertSame($hero, $this->selectUrl($hero, '<p>Just words.</p>'));
    }

    public function testShowsTheHeroWhenItHasNoPathBasename(): void
    {
        // parse_url returns no path for a bare host; the hero keeps its whole
        // form as its identity, so it does not match the different body image.
        // The body leads with text, so the lead-position rule does not fire
        // either, and the hero still leads.
        $hero = 'https://cdn.test';
        $body = '<p>Intro.</p><img src="https://cdn.test/photo.jpg" alt="">';

        self::assertSame($hero, $this->selectUrl($hero, $body));
    }

    public function testSuppressesHeroWhenACdnPutsThePhotoIdentityInThePathAndTheSizeInTheBasename(): void
    {
        // The zeit.de entry 477263 case: one photo lives in an image-group
        // directory and each size is a differently named basename, so the hero
        // and the body figure are the same picture under `wide__1300x731` and
        // `wide__660x371`. A byline leads the body, so only the repeat rule can
        // catch it; it must, or the reader stacks the photo twice.
        $hero = 'https://img.zeit.de/koenigsfamilie-image-group/wide__1300x731';
        $body = '<div><span>Quelle: dpa</span></div>'
            . '<figure><img src="https://img.zeit.de/koenigsfamilie-image-group/wide__660x371" alt=""></figure>';

        self::assertNull($this->selectUrl($hero, $body));
    }

    public function testSuppressesHeroWhenTheSamePhotoUsesADifferentCropNameInTheBody(): void
    {
        // The real zeit.de entry 477263 data (#592): the CDN names crops of one
        // photo with different basename words in the same image-group directory,
        // so the feed hero is `original__640x360` while the body repeats it as
        // `wide__660x371`. The directory is the photo's identity; the crop word
        // is part of the size variant. A byline leads the body, so only the
        // repeat rule can catch it — it must, or the reader stacks the photo.
        $imageGroup = 'https://img.zeit.de/news/2026-08/24/koenigsfamilie-image-group';
        $hero = $imageGroup . '/original__640x360';
        $body = '<div><span>Quelle: dpa</span></div>'
            . '<figure><img src="' . $imageGroup . '/wide__660x371" alt=""></figure>';

        self::assertNull($this->selectUrl($hero, $body));
    }

    public function testShowsHeroWhenTheBodyPhotoBelongsToADifferentImageGroup(): void
    {
        // Distinct photos live in distinct image-group directories, so a
        // size-variant basename shared between them must not collapse the two
        // into one identity and hide a genuinely different body image.
        $hero = 'https://img.zeit.de/koenigsfamilie-image-group/wide__1300x731';
        $body = '<p>Intro.</p>'
            . '<figure><img src="https://img.zeit.de/koenigslinde-image-group/wide__660x371" alt=""></figure>';

        self::assertSame($hero, $this->selectUrl($hero, $body));
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

    public function testIsNotFooledByAnElementWhoseNameMerelyStartsWithImg(): void
    {
        // Ported from the deleted frontend spec (#592): the rule matches the
        // element name `img` exactly, so an `<imgur-embed>` is neither a leading
        // image nor a repeat of the hero.
        $hero = 'https://cdn.test/hero.jpg';

        self::assertSame($hero, $this->selectUrl($hero, '<p>see the <imgur-embed></imgur-embed></p>'));
    }

    public function testKeepsTheDeclaredDimensionsOfAnAcceptedHero(): void
    {
        // The dimensions are the client's aspect-ratio reservation, so the
        // selector must hand back the candidate itself, not a rebuilt copy.
        $hero = new HeroImage('https://cdn.test/hero.jpg', 800, 450);

        $selected = $this->selector->select($hero, '<p>Just words.</p>');

        self::assertSame($hero, $selected);
        self::assertSame(800, $selected->width);
        self::assertSame(450, $selected->height);
    }
}
