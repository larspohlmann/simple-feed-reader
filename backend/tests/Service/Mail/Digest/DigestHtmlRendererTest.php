<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Service\Mail\Digest\DigestEntry;
use App\Service\Mail\Digest\DigestHtmlRenderer;
use App\Service\Mail\Digest\DigestImageSet;
use App\Service\Mail\Digest\DigestLinkBuilder;
use App\Service\Mail\Digest\DigestPage;
use App\Service\Mail\Digest\DigestPageGroup;
use App\Tests\Support\DigestTwigEnvironment;
use App\Tests\Support\FixedPublicBaseUrl;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

final class DigestHtmlRendererTest extends TestCase
{
    private function renderer(): DigestHtmlRenderer
    {
        $translator = new Translator('en');
        $translator->addLoader('yaml', new YamlFileLoader());
        $dir = \dirname(__DIR__, 4) . '/translations';
        $translator->addResource('yaml', "{$dir}/emails.en.yaml", 'en', 'emails');
        $translator->addResource('yaml', "{$dir}/emails.de.yaml", 'de', 'emails');

        $links = new DigestLinkBuilder(new FixedPublicBaseUrl('https://reader.example'));

        return new DigestHtmlRenderer(DigestTwigEnvironment::withTranslator($translator), $links);
    }

    private function card(string $title, ?string $imageUrl): DigestEntry
    {
        return new DigestEntry(
            $title,
            'ZDFheute',
            'A short summary.',
            'https://reader.example/?entry=1',
            new \DateTimeImmutable('2026-08-30T09:48:00Z'),
            $imageUrl,
            'https://site/favicon.ico',
        );
    }

    public function testRendersCardWithImageAndTheEntryLink(): void
    {
        $group = new DigestPageGroup(
            'Thailand',
            10,
            [$this->card('Thailand-Urlaub', 'https://cdn/1.jpg')],
            7,
            'https://reader.example/?q=Thailand',
        );
        $page = new DigestPage([$group], 10);
        $images = new DigestImageSet([], ['https://cdn/1.jpg' => 'imgABC', 'https://site/favicon.ico' => 'imgFAV']);

        $html = $this->renderer()->render($page, $images, 'en');

        self::assertStringContainsString('Thailand-Urlaub', $html);
        self::assertStringContainsString('https://reader.example/?entry=1', $html);
        self::assertStringContainsString('cid:imgABC', $html);
        self::assertStringContainsString('cid:imgFAV', $html);
        self::assertStringContainsString('+7 more in "Thailand"', $html);
        self::assertStringContainsString('cid:digestlogo', $html);
        self::assertStringContainsString('simple feed reader', $html);
    }

    public function testTextOnlyCardHasNoImgTag(): void
    {
        $group = new DigestPageGroup(
            'Thailand',
            1,
            [$this->card('No image here', null)],
            0,
            'https://reader.example/?q=Thailand',
        );
        $page = new DigestPage([$group], 1);
        $images = new DigestImageSet([], ['https://site/favicon.ico' => 'imgFAV']);

        $html = $this->renderer()->render($page, $images, 'en');

        self::assertStringContainsString('No image here', $html);
        self::assertStringNotContainsString('cid:imgABC', $html);
    }

    public function testOverflowGroupRendersHeadingAndMoreLinkOnly(): void
    {
        $group = new DigestPageGroup('Bundesliga', 12, [], 12, 'https://reader.example/?q=Bundesliga');
        $page = new DigestPage([$group], 12);

        $html = $this->renderer()->render($page, new DigestImageSet([], []), 'en');

        self::assertStringContainsString('Bundesliga (12)', $html);
        self::assertStringContainsString('+12 more in "Bundesliga"', $html);
    }

    public function testZeroRemainingRendersNoMoreLink(): void
    {
        $group = new DigestPageGroup('Thailand', 1, [], 0, 'https://reader.example/?q=Thailand');
        $page = new DigestPage([$group], 1);

        $html = $this->renderer()->render($page, new DigestImageSet([], []), 'en');

        self::assertStringNotContainsString('more in', $html);
    }

    public function testHeaderShowsTheTotalEntryCount(): void
    {
        $html = $this->renderer()->render(new DigestPage([], 22), new DigestImageSet([], []), 'en');

        self::assertStringContainsString('22 new entries', $html);
    }

    public function testGroupHeadingShowsTermAndItsCount(): void
    {
        $group = new DigestPageGroup('Thailand', 10, [], 0, 'https://reader.example/?q=Thailand');

        $html = $this->renderer()->render(new DigestPage([$group], 10), new DigestImageSet([], []), 'en');

        self::assertStringContainsString('Thailand (10)', $html);
    }

    public function testFooterCarriesTheSettingsLink(): void
    {
        $page = new DigestPage([], 0);

        $html = $this->renderer()->render($page, new DigestImageSet([], []), 'en');

        self::assertStringContainsString('https://reader.example/settings/email', $html);
        self::assertStringContainsString('Settings → Email', $html);
    }

    public function testRenderedPageCarriesAllFourBodySectionsInOrder(): void
    {
        $group = new DigestPageGroup(
            'Thailand',
            10,
            [$this->card('Thailand-Urlaub', 'https://cdn/1.jpg')],
            7,
            'https://reader.example/?q=Thailand',
        );
        $page = new DigestPage([$group], 10);
        $images = new DigestImageSet([], ['https://cdn/1.jpg' => 'imgABC', 'https://site/favicon.ico' => 'imgFAV']);

        $html = $this->renderer()->render($page, $images, 'en');

        $headerPosition = strpos($html, 'simple feed reader');
        $introPosition = strpos($html, 'saved searches');
        $groupPosition = strpos($html, 'Thailand-Urlaub');
        $footerPosition = strpos($html, 'https://reader.example/settings/email');

        self::assertNotFalse($headerPosition, 'header section is missing');
        self::assertNotFalse($introPosition, 'intro section is missing');
        self::assertNotFalse($groupPosition, 'group section is missing');
        self::assertNotFalse($footerPosition, 'footer section is missing');
        self::assertGreaterThan($headerPosition, $introPosition);
        self::assertGreaterThan($introPosition, $groupPosition);
        self::assertGreaterThan($groupPosition, $footerPosition);
    }

    public function testDocumentIsMobileReadyWithViewportAndOutlookGhostTable(): void
    {
        $html = $this->renderer()->render(new DigestPage([], 0), new DigestImageSet([], []), 'en');

        self::assertStringContainsString('<meta name="viewport" content="width=device-width,initial-scale=1">', $html);
        self::assertStringContainsString('text-size-adjust: 100%', $html);
        self::assertStringContainsString(
            '<!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0"><tr><td><![endif]-->',
            $html,
        );
        self::assertStringContainsString('<!--[if mso]></td></tr></table><![endif]-->', $html);
    }

    public function testSheetIsFluidWithMaxWidthAndTightOuterFrame(): void
    {
        $html = $this->renderer()->render(new DigestPage([], 0), new DigestImageSet([], []), 'en');

        self::assertStringContainsString('max-width: 600px', $html);
        self::assertStringContainsString('padding: 12px 0', $html);
    }

    public function testStylesAreInlinedOntoElements(): void
    {
        $group = new DigestPageGroup(
            'Thailand',
            1,
            [$this->card('Thailand-Urlaub', null)],
            0,
            'https://reader.example/?q=Thailand',
        );
        $page = new DigestPage([$group], 1);

        $html = $this->renderer()->render($page, new DigestImageSet([], []), 'en');

        self::assertMatchesRegularExpression(
            '/<a[^>]+style="[^"]*font-size: 16px[^"]*"[^>]*>Thailand-Urlaub<\/a>/',
            $html,
        );
    }

    public function testTitleTextIsHtmlEscaped(): void
    {
        $group = new DigestPageGroup(
            'Thailand',
            1,
            [$this->card('<script>alert(1)</script>', null)],
            0,
            'https://reader.example/?q=Thailand',
        );
        $page = new DigestPage([$group], 1);

        $html = $this->renderer()->render($page, new DigestImageSet([], []), 'en');

        self::assertStringNotContainsString('<script>alert(1)</script>', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testEscapedTextSubstitutesInvalidUtf8Bytes(): void
    {
        $card = new DigestEntry(
            "Broken \xFF title",
            'Feed',
            '',
            'https://reader.example/?entry=1',
            null,
            null,
            null,
        );
        $group = new DigestPageGroup('Thailand', 1, [$card], 0, 'https://reader.example/?q=Thailand');
        $page = new DigestPage([$group], 1);

        $html = $this->renderer()->render($page, new DigestImageSet([], []), 'en');

        self::assertStringContainsString("Broken \u{FFFD} title", $html);
    }
}
