<?php

declare(strict_types=1);

namespace App\Tests\Service\Ingestion\Language;

use App\Entity\LinkCardContent;
use App\Entity\LinkLanguage;
use App\Enum\FeedLanguage;
use App\Service\Ingestion\Language\Factory\LanguageDetectionFactory;
use App\Service\Ingestion\Language\LanguageGuess;
use App\Service\Ingestion\Language\PreviewLanguage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PreviewLanguageTest extends TestCase
{
    private const string ENGLISH = 'The library opens a new reading room on the second floor, with long tables, quiet'
        . ' corners and a small shelf of travel guides that visitors may borrow for a week.';

    public function testAStatedLanguageWins(): void
    {
        self::assertEquals(
            LinkLanguage::stated(FeedLanguage::German),
            self::previewLanguage()->resolveLanguage(
                self::preview('Reading room', self::ENGLISH),
                FeedLanguage::German,
            ),
        );
    }

    #[DataProvider('longTexts')]
    public function testALongTextIsGuessed(string $description, FeedLanguage $expected): void
    {
        self::assertEquals(
            LinkLanguage::guessed($expected),
            self::previewLanguage()->resolveLanguage(self::preview('Title', $description), null),
        );
    }

    /** @return iterable<string, array{string, FeedLanguage}> */
    public static function longTexts(): iterable
    {
        yield 'French' => [
            'La bibliothèque ouvre une nouvelle salle de lecture au deuxième étage, avec de longues tables, des coins'
            . ' calmes et une petite étagère de guides de voyage que les visiteurs peuvent emprunter pour une semaine.',
            FeedLanguage::French,
        ];
        yield 'Spanish' => [
            'La biblioteca abre una nueva sala de lectura en el segundo piso, con mesas largas, rincones tranquilos y'
            . ' un pequeño estante de guías de viaje que los visitantes pueden llevarse prestadas durante una semana.',
            FeedLanguage::Spanish,
        ];
        yield 'German' => [
            'Die Bibliothek eröffnet im zweiten Stock einen neuen Lesesaal mit langen Tischen, ruhigen Ecken und einem'
            . ' kleinen Regal mit Reiseführern, die Besucher für eine Woche ausleihen dürfen.',
            FeedLanguage::German,
        ];
        yield 'English' => [self::ENGLISH, FeedLanguage::English];
    }

    public function testAShortTextWithoutAStatementIsNull(): void
    {
        $description = str_repeat('a', 149 - mb_strlen("Title\n"));

        self::assertNull(self::previewLanguage()->resolveLanguage(self::preview('Title', $description), null));
    }

    public function testATextOfTheThresholdIsGuessed(): void
    {
        $description = mb_substr(self::ENGLISH, 0, 150 - mb_strlen("Title\n"));

        self::assertNotNull(self::previewLanguage()->resolveLanguage(self::preview('Title', $description), null));
    }

    public function testAShortTextWithAStatementIsStated(): void
    {
        self::assertEquals(
            LinkLanguage::stated(FeedLanguage::Spanish),
            self::previewLanguage()->resolveLanguage(self::preview('Hola', null), FeedLanguage::Spanish),
        );
    }

    public function testALongTextWithoutLettersIsNull(): void
    {
        self::assertNull(
            self::previewLanguage()->resolveLanguage(self::preview('2031', str_repeat('42 % 12:30 ', 20)), null),
        );
    }

    private static function preview(string $title, ?string $description): LinkCardContent
    {
        return new LinkCardContent('https://example.com/a', $title, $description, null, 'example.com');
    }

    private static function previewLanguage(): PreviewLanguage
    {
        return new PreviewLanguage(new LanguageGuess(new LanguageDetectionFactory()->createForFeedLanguages()));
    }
}
