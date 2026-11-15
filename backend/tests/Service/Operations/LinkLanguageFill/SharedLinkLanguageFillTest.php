<?php

declare(strict_types=1);

namespace App\Tests\Service\Operations\LinkLanguageFill;

use App\Entity\LinkCardContent;
use App\Entity\LinkLanguage;
use App\Enum\FeedLanguage;
use App\Repository\SharedLinkRepository;
use App\Service\Content\LinkCard\Model\NewSharedLinkModel;
use App\Service\Ingestion\Language\Factory\LanguageDetectionFactory;
use App\Service\Ingestion\Language\LanguageGuess;
use App\Service\Ingestion\Language\PreviewLanguage;
use App\Service\Operations\LinkLanguageFill\Model\LinkLanguageFillReportModel;
use App\Service\Operations\LinkLanguageFill\SharedLinkLanguageFill;
use App\Tests\DbTestCase;
use App\Tests\Support\PostEnrichers;

final class SharedLinkLanguageFillTest extends DbTestCase
{
    public const string GERMAN = 'Die Bibliothek eröffnet im zweiten Stock einen neuen Lesesaal mit langen'
        . ' Tischen, ruhigen Ecken und einem kleinen Regal mit Reiseführern, die Besucher für eine Woche ausleihen.';
    public const string FRENCH = 'La bibliothèque ouvre une nouvelle salle de lecture au deuxième étage, avec de'
        . ' longues tables, des coins calmes et une petite étagère de guides de voyage que les visiteurs empruntent.';
    public const string SPANISH = 'La biblioteca abre una nueva sala de lectura en el segundo piso, con mesas largas,'
        . ' rincones tranquilos y un pequeño estante de guías de viaje que los visitantes pueden llevarse prestadas.';

    public function testLongRowsAreFilledShortRowsStayNull(): void
    {
        $this->store(['short' => 'Kurz', 'de' => self::GERMAN, 'fr' => self::FRENCH, 'es' => self::SPANISH]);

        $report = $this->fill()->fill();

        self::assertEquals(new LinkLanguageFillReportModel(['de' => 1, 'es' => 1, 'fr' => 1], 1), $report);
        self::assertSame(
            ['de' => 'de/guessed', 'es' => 'es/guessed', 'fr' => 'fr/guessed', 'short' => null],
            $this->languagesByHash(),
        );
    }

    public function testASetLanguageIsNotTouched(): void
    {
        $this->store(['set' => self::GERMAN], LinkLanguage::stated(FeedLanguage::English));

        self::assertEquals(new LinkLanguageFillReportModel(), $this->fill()->fill());
        self::assertSame(['set' => 'en/stated'], $this->languagesByHash());
    }

    public function testDryRunWritesNothing(): void
    {
        $this->store(['de' => self::GERMAN, 'short' => 'Kurz']);

        self::assertEquals(new LinkLanguageFillReportModel(['de' => 1], 1), $this->fill()->count());
        self::assertSame(['de' => null, 'short' => null], $this->languagesByHash());
    }

    public function testASecondRunFillsNothing(): void
    {
        $this->store(['de' => self::GERMAN, 'short' => 'Kurz']);
        $this->fill()->fill();

        self::assertEquals(new LinkLanguageFillReportModel([], 1), $this->fill()->fill());
    }

    public function testTheWalkCrossesBatchBoundaries(): void
    {
        $this->store(
            ['a' => self::GERMAN, 'b' => 'Kurz', 'c' => self::FRENCH, 'd' => self::SPANISH, 'e' => self::GERMAN],
        );

        self::assertEquals(
            new LinkLanguageFillReportModel(['de' => 2, 'es' => 1, 'fr' => 1], 1),
            $this->fill(2)->fill(),
        );
        self::assertNull($this->languagesByHash()['b']);
        self::assertSame('es/guessed', $this->languagesByHash()['d']);
    }

    /** @param array<string, string> $descriptionsByHash */
    private function store(array $descriptionsByHash, ?LinkLanguage $language = null): void
    {
        $this->sharedLinks()->insertMissing(array_map(
            static fn (string $hash, string $description): NewSharedLinkModel => new NewSharedLinkModel(
                $hash,
                new LinkCardContent('https://example.com/' . $hash, 'T', $description, null, 'example.com'),
                $language,
                new \DateTimeImmutable('2026-11-15 10:00:00'),
            ),
            array_keys($descriptionsByHash),
            array_values($descriptionsByHash),
        ));
    }

    /** @return array<string, ?string> */
    private function languagesByHash(): array
    {
        $languages = [];
        foreach ($this->sharedLinks()->findBy([], ['urlHash' => 'ASC']) as $link) {
            $this->entityManager->refresh($link);
            $languages[$link->getUrlHash()] = $link->getLanguage() === null
                ? null
                : $link->getLanguage()->value . '/' . $link->getLanguageSource()?->value;
        }

        return $languages;
    }

    private function sharedLinks(): SharedLinkRepository
    {
        return PostEnrichers::sharedLinks($this->entityManager);
    }

    private function fill(int $batchSize = 500): SharedLinkLanguageFill
    {
        return new SharedLinkLanguageFill(
            $this->sharedLinks(),
            new PreviewLanguage(new LanguageGuess(new LanguageDetectionFactory()->createForFeedLanguages())),
            $this->entityManager,
            $batchSize,
        );
    }
}
