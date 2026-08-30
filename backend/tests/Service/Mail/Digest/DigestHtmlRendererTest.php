<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\Digest;

use App\Tests\Support\FixedPublicBaseUrl;
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

    private function card(string $title, ?string $imageUrl): DigestEntry
    {
        return new DigestEntry(
            $title,
            'ZDFheute',
            'A short summary.',
            'https://reader.example/?entry=1',
            new \DateTimeImmutable('2026-08-30T09:48:00Z'),
            $imageUrl,
            null,
            null,
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
}
