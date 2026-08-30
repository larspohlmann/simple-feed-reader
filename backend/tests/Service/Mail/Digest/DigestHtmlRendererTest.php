<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Tests\Support\FixedPublicBaseUrl;
use App\Tests\Support\RecordingTranslator;
use App\Service\Mail\Digest\DigestEntry;
use App\Service\Mail\Digest\DigestHtmlRenderer;
use App\Service\Mail\Digest\DigestImageSet;
use App\Service\Mail\Digest\DigestLinkBuilder;
use App\Service\Mail\Digest\DigestPage;
use App\Service\Mail\Digest\DigestPageGroup;
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

        return new DigestHtmlRenderer($translator, $links);
    }

    /** @return array{0: DigestHtmlRenderer, 1: RecordingTranslator} */
    private function rendererWithRecordingTranslator(): array
    {
        $translator = new RecordingTranslator();
        $links = new DigestLinkBuilder(new FixedPublicBaseUrl('https://reader.example'));

        return [new DigestHtmlRenderer($translator, $links), $translator];
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

        self::assertStringContainsString('Bundesliga', $html);
        self::assertStringContainsString('+12 more in "Bundesliga"', $html);
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
        $groupPosition = strpos($html, 'Thailand');
        $footerPosition = strpos($html, 'https://reader.example/settings/email');

        self::assertNotFalse($headerPosition, 'header section is missing');
        self::assertNotFalse($introPosition, 'intro section is missing');
        self::assertNotFalse($groupPosition, 'group section is missing');
        self::assertNotFalse($footerPosition, 'footer section is missing');
        self::assertGreaterThan($headerPosition, $introPosition);
        self::assertGreaterThan($introPosition, $groupPosition);
        self::assertGreaterThan($groupPosition, $footerPosition);
    }

    public function testDocumentSkeletonWrapsTheBodyExactly(): void
    {
        $html = $this->renderer()->render(new DigestPage([], 0), new DigestImageSet([], []), 'en');

        self::assertStringContainsString(
            '<!doctype html><html><body style="margin:0;background:#f5f5f4;">',
            $html,
        );
        self::assertStringContainsString('style="background:#f5f5f4;"', $html);
        self::assertStringContainsString('padding:24px 12px', $html);
        self::assertStringEndsWith('</td></tr></table></body></html>', $html);
    }

    public function testSheetTableCarriesWidthBackgroundAndFont(): void
    {
        $html = $this->renderer()->render(new DigestPage([], 0), new DigestImageSet([], []), 'en');

        self::assertStringContainsString('width:600px;max-width:600px;background:#ffffff;', $html);
        self::assertStringContainsString(
            "font-family:system-ui,-apple-system,'Segoe UI',roboto,sans-serif;color:#2a2a2a;",
            $html,
        );
    }

    /**
     * A byte-exact rendering of every markup fragment the renderer produces: two
     * groups (one with an imaged card, a plain card and a "more" link; one pure
     * overflow group with no cards), the header, intro and footer. A wrong
     * ordering, a dropped fragment or an altered style anywhere in the renderer
     * changes this string, which is what makes it kill Concat-family mutants
     * that a keyword-only assertion lets through.
     */
    public function testFullPageRendersTheExactExpectedMarkup(): void
    {
        $imagedCard = $this->card('Thailand-Urlaub', 'https://cdn/1.jpg');
        $plainCard = new DigestEntry(
            'No image here',
            'Spiegel',
            '',
            'https://reader.example/?entry=2',
            null,
            null,
            null,
        );
        $groupWithCards = new DigestPageGroup(
            'Thailand',
            10,
            [$imagedCard, $plainCard],
            7,
            'https://reader.example/?q=Thailand',
        );
        $overflowGroup = new DigestPageGroup('Bundesliga', 12, [], 12, 'https://reader.example/?q=Bundesliga');
        $page = new DigestPage([$groupWithCards, $overflowGroup], 22);
        $images = new DigestImageSet([], ['https://cdn/1.jpg' => 'imgABC', 'https://site/favicon.ico' => 'imgFAV']);

        $html = $this->renderer()->render($page, $images, 'en');

        $today = (new \IntlDateFormatter('en', \IntlDateFormatter::FULL, \IntlDateFormatter::NONE, 'UTC'))
            ->format(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $expected = str_replace('%TODAY%', (string) $today, $this->expectedFullPageMarkup());

        self::assertSame($expected, $html);
    }

    public function testHeaderCountParameterIsPassedAsAString(): void
    {
        [$renderer, $translator] = $this->rendererWithRecordingTranslator();

        $renderer->render(new DigestPage([], 22), new DigestImageSet([], []), 'en');

        $call = $this->findTransCall($translator, 'digest.header');
        self::assertIsString($call['parameters']['%count%']);
        self::assertSame('22', $call['parameters']['%count%']);
    }

    public function testGroupHeadingCountParameterIsPassedAsAString(): void
    {
        [$renderer, $translator] = $this->rendererWithRecordingTranslator();
        $group = new DigestPageGroup('Thailand', 10, [], 0, 'https://reader.example/?q=Thailand');

        $renderer->render(new DigestPage([$group], 10), new DigestImageSet([], []), 'en');

        $call = $this->findTransCall($translator, 'digest.group_heading');
        self::assertIsString($call['parameters']['%count%']);
        self::assertSame('10', $call['parameters']['%count%']);
    }

    public function testMoreLinkCountParameterIsPassedAsAString(): void
    {
        [$renderer, $translator] = $this->rendererWithRecordingTranslator();
        $group = new DigestPageGroup('Thailand', 10, [], 3, 'https://reader.example/?q=Thailand');

        $renderer->render(new DigestPage([$group], 10), new DigestImageSet([], []), 'en');

        $call = $this->findTransCall($translator, 'digest.more_link');
        self::assertIsString($call['parameters']['%count%']);
        self::assertSame('3', $call['parameters']['%count%']);
    }

    /** @return array{id: string, parameters: array<string, mixed>} */
    private function findTransCall(RecordingTranslator $translator, string $id): array
    {
        foreach ($translator->calls as $call) {
            if ($call['id'] === $id) {
                return $call;
            }
        }

        self::fail("No trans() call was recorded for '{$id}'.");
    }

    public function testZeroRemainingRendersNoMoreLink(): void
    {
        $group = new DigestPageGroup('Thailand', 1, [], 0, 'https://reader.example/?q=Thailand');
        $page = new DigestPage([$group], 1);

        $html = $this->renderer()->render($page, new DigestImageSet([], []), 'en');

        self::assertStringNotContainsString('more in', $html);
    }

    public function testEscapedUrlKeepsASingleQuoteAsAnHtmlEntity(): void
    {
        $card = new DigestEntry(
            'Title',
            'Feed',
            '',
            "https://reader.example/?entry=1&name=O'Brien",
            null,
            null,
            null,
        );
        $group = new DigestPageGroup('Thailand', 1, [$card], 0, 'https://reader.example/?q=Thailand');
        $page = new DigestPage([$group], 1);

        $html = $this->renderer()->render($page, new DigestImageSet([], []), 'en');

        self::assertStringContainsString('name=O&#039;Brien', $html);
        self::assertStringNotContainsString("name=O'Brien", $html);
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

    private function expectedFullPageMarkup(): string
    {
        return '<!doctype html><html><body style="margin:0;background:#f5f5f4;"><'
            . 'table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
            . 'style="background:#f5f5f4;"><tr><td align="center" style="padding:24px '
            . '12px;"><table role="presentation" width="600" cellpadding="0" cellspacing="0" '
            . 'style="width:600px;max-width:600px;background:#ffffff;font-family:system-ui,-apple-system,\'Segoe '
            . 'UI\',roboto,sans-serif;color:#2a2a2a;"><tr><td style="padding:24px '
            . '24px 18px;border-bottom:1px solid #e4e4e2;"><img src="cid:digestlogo" '
            . 'width="20" height="20" alt="" style="display:inline-block;width:20px;'
            . 'height:20px;vertical-align:middle;margin-right:8px;border:0;"><span '
            . 'style="font-size:15px;font-weight:600;color:#2a2a2a;">simple feed '
            . 'reader</span><div style="margin-top:14px;font-size:13px;color:#8f8f8b;'
            . '">%TODAY% · 22 new entries</div></td></tr><tr><td style="padding:16px '
            . '24px 0;font-size:14px;line-height:1.5;color:#5f5f5c;">These are new '
            . 'entries matching your saved searches, grouped by search.</td></tr>'
            . '<tr><td style="padding:20px 24px 4px;"><div style="padding-bottom:10px;'
            . 'border-bottom:1px solid #e4e4e2;font-size:13px;font-weight:600;color:#5f5f5c;'
            . '">Thailand (10)</div><div style="padding-top:20px;"></div><table '
            . 'role="presentation" width="100%" cellpadding="0" cellspacing="0">'
            . '<tr><td valign="top" width="88" style="width:88px;padding:0 12px '
            . '0 0;"><img src="cid:imgABC" width="88" height="66" alt="" style="display:block;'
            . 'width:88px;height:66px;border-radius:8px;object-fit:cover;"></td>'
            . '<td valign="top"><div style="font-size:13px;color:#8f8f8b;"><img '
            . 'src="cid:imgFAV" width="16" height="16" alt="" style="width:16px;'
            . 'height:16px;border-radius:4px;vertical-align:middle;margin-right:6px;'
            . '">ZDFheute<span style="color:#c4c4c1;"> · </span>Aug 30, 2026, 9:48 AM<'
            . '/div><a href="https://reader.example/?entry=1" style="display:block;'
            . 'font-size:15px;font-weight:500;line-height:1.35;color:#2a2a2a;text-decoration:none;'
            . 'margin:4px 0;">Thailand-Urlaub</a><div style="font-size:13px;line-height:1.4;'
            . 'color:#5f5f5c;margin-top:4px;">A short summary.</div></td></tr></table>'
            . '<div style="margin-top:20px;padding-top:20px;border-top:1px solid '
            . '#e4e4e2;"></div><table role="presentation" width="100%" cellpadding="0" '
            . 'cellspacing="0"><tr><td valign="top"><div style="font-size:13px;color:#8f8f8b;'
            . '">Spiegel</div><a href="https://reader.example/?entry=2" style="display:block;'
            . 'font-size:15px;font-weight:500;line-height:1.35;color:#2a2a2a;text-decoration:none;'
            . 'margin:4px 0;">No image here</a></td></tr></table><a href="https://reader.example/?q=Thailand" '
            . 'style="display:inline-block;margin:12px 0 2px;font-size:13px;color:#3f8676;'
            . 'text-decoration:none;font-weight:500;">+7 more in "Thailand" →</a>'
            . '</td></tr><tr><td style="padding:20px 24px 4px;"><div style="padding-bottom:10px;'
            . 'border-bottom:1px solid #e4e4e2;font-size:13px;font-weight:600;color:#5f5f5c;'
            . '">Bundesliga (12)</div><a href="https://reader.example/?q=Bundesliga" '
            . 'style="display:inline-block;margin:12px 0 2px;font-size:13px;color:#3f8676;'
            . 'text-decoration:none;font-weight:500;">+12 more in "Bundesliga" →<'
            . '/a></td></tr><tr><td style="padding:22px 24px 26px;border-top:1px '
            . 'solid #e4e4e2;"><div style="margin-bottom:12px;"><a href="https://reader.example/" '
            . 'style="font-size:13px;color:#3f8676;text-decoration:none;">Open in '
            . 'the reader →</a></div><div style="font-size:12px;line-height:1.5;'
            . 'color:#a7a7a3;">Manage your digest in <a href="https://reader.example/settings/email" '
            . 'style="color:#8f8f8b;text-decoration:underline;">Settings → Email<'
            . '/a>.</div></td></tr></table></td></tr></table></body></html>';
    }
}
