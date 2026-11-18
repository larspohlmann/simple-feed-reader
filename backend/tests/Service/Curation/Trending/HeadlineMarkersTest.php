<?php

declare(strict_types=1);

namespace App\Tests\Service\Curation\Trending;

use App\Enum\FeedLanguage;
use App\Service\Curation\Trending\HeadlineMarkers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HeadlineMarkersTest extends TestCase
{
    /** @return iterable<string, array{string, ?string, ?FeedLanguage, string, bool}> */
    public static function headlines(): iterable
    {
        $de = FeedLanguage::German;

        yield 'outlet suffix with dash' => [
            'Hafenstreik legt Hamburg lahm – Spiegel',
            'DER SPIEGEL - Schlagzeilen',
            $de,
            'Hafenstreik legt Hamburg lahm',
            true,
        ];
        yield 'outlet suffix with pipe' => [
            'Hafenstreik legt Hamburg lahm | tagesschau.de',
            'tagesschau.de - Die Nachrichten der ARD',
            $de,
            'Hafenstreik legt Hamburg lahm',
            true,
        ];
        yield 'tail not in feed title' => ['Merz – der Kanzler', 'DER SPIEGEL', $de, 'Merz – der Kanzler', true];
        yield 'german label' => ['Liveblog: Hafenstreik in Hamburg', 'NDR', $de, 'Hafenstreik in Hamburg', false];
        yield 'ticker run' => ['+++ Hafenstreik in Hamburg +++', 'NDR', $de, 'Hafenstreik in Hamburg', false];
        yield 'french label with space before colon' => [
            'EN DIRECT : grève au port du Havre',
            'Le Monde',
            FeedLanguage::French,
            'grève au port du Havre',
            false,
        ];
        yield 'spanish label' => [
            'Última hora: huelga en el puerto',
            'El País',
            FeedLanguage::Spanish,
            'huelga en el puerto',
            false,
        ];
        yield 'label and suffix' => [
            'Breaking: Port strike in Hamburg - BBC News',
            'BBC News - World',
            FeedLanguage::English,
            'Port strike in Hamburg',
            false,
        ];
        yield 'no separator' => ['Spiegel', 'DER SPIEGEL', $de, 'Spiegel', true];
        yield 'null language applies every list' => ['Exklusiv: Hafenstreik', null, null, 'Hafenstreik', false];
        yield 'label without colon is no label' => ['Update – Spiegel', 'Spiegel', $de, 'Update', true];
        yield 'rest too short' => ['Ab – Spiegel', 'Spiegel', $de, 'Ab – Spiegel', true];
        yield 'no-break space after colon' => [
            "Live:\u{00A0}Hafenstreik",
            null,
            FeedLanguage::English,
            'Hafenstreik',
            false,
        ];
        yield 'label of another language stays' => [
            'Exklusiv: Hafenstreik',
            null,
            FeedLanguage::English,
            'Exklusiv: Hafenstreik',
            true,
        ];
        yield 'only the last separator is cut' => ['A - B - Spiegel', 'Spiegel', $de, 'A - B', true];
    }

    #[DataProvider('headlines')]
    public function testCleansAHeadline(
        string $headline,
        ?string $feedTitle,
        ?FeedLanguage $language,
        string $text,
        bool $markerFree,
    ): void {
        $cleaned = (new HeadlineMarkers())->clean($headline, $feedTitle, $language);

        self::assertSame($text, $cleaned->text);
        self::assertSame($markerFree, $cleaned->markerFree);
    }
}
