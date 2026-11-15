<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\LinkCard;

use App\Entity\LinkCardContent;
use App\Enum\FeedLanguage;
use App\Enum\LanguageSource;
use App\Repository\SharedLinkRepository;
use App\Service\Content\LinkCard\Exception\SharedLinkNotReadBackException;
use App\Service\Content\LinkCard\Exception\UnhashableLinkException;
use App\Service\Content\LinkCard\SharedLinkWriter;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Foundation\Url\UrlNormalizer;
use App\Service\Ingestion\Language\Factory\LanguageDetectionFactory;
use App\Service\Ingestion\Language\LanguageGuess;
use App\Service\Ingestion\Language\PreviewLanguage;
use App\Tests\DbTestCase;
use Symfony\Component\Clock\MockClock;

final class SharedLinkWriterTest extends DbTestCase
{
    public function testAKnownUrlKeepsItsFirstPreview(): void
    {
        $writer = $this->writer();

        $first = $writer->linkFor(self::card('https://example.com/a', 'First'), null);
        $again = $writer->linkFor(self::card('https://example.com/a', 'Second'), FeedLanguage::French);
        $tracked = $writer->linkFor(self::card('https://example.com/a?utm_source=x', 'Third'), null);

        self::assertSame($first->requireId(), $again->requireId());
        self::assertSame($first->requireId(), $tracked->requireId());
        $this->entityManager->clear();
        $stored = $writer->linkFor(self::card('https://example.com/a', 'Fourth'), null);
        self::assertSame('First', $stored->preview()->title);
        self::assertSame('https://example.com/a', $stored->preview()->url);
        self::assertNull($stored->getLanguage());
    }

    public function testANewUrlIsStoredWithItsLanguage(): void
    {
        $link = $this->writer()->linkFor(self::card('https://example.com/fr', 'Bonjour'), FeedLanguage::French);

        $this->entityManager->clear();
        $stored = $this->writer()->linkFor(self::card('https://example.com/fr', 'Other'), null);
        self::assertSame($link->requireId(), $stored->requireId());
        self::assertSame(FeedLanguage::French, $stored->getLanguage());
        self::assertSame(LanguageSource::Stated, $stored->getLanguageSource());
        self::assertEquals(new \DateTimeImmutable('2026-11-15 10:00:00'), $stored->getCreatedAt());
    }

    public function testAUrlThatDoesNotHashHasNoLink(): void
    {
        $this->expectException(UnhashableLinkException::class);

        $this->writer()->linkFor(self::card('/relative', 'Relative'), null);
    }

    public function testARowThatCannotBeReadBackNamesThatCause(): void
    {
        $this->skipUnlessSqlite();
        $this->entityManager->getConnection()->executeStatement(
            'CREATE TEMP TRIGGER vanishing_link AFTER INSERT ON shared_link'
            . ' BEGIN DELETE FROM shared_link WHERE id = new.id; END',
        );

        $this->expectException(SharedLinkNotReadBackException::class);
        $this->expectExceptionMessage('https://example.com/gone');

        $this->writer()->linkFor(self::card('https://example.com/gone', 'Gone'), null);
    }

    public function testABatchWithAUrlThatDoesNotHashIsRefused(): void
    {
        $this->expectException(UnhashableLinkException::class);

        $this->writer()->idsFor([self::card('/relative', 'Relative')]);
    }

    public function testIdsForMergesABatch(): void
    {
        $writer = $this->writer();
        $existing = $writer->linkFor(self::card('https://example.com/known', 'Known'), null)->requireId();

        $ids = $writer->idsFor([
            self::card('https://example.com/known?utm_source=x', 'Known again'),
            self::card('https://example.com/new', 'New'),
            self::card('https://example.com/new#part', 'New again'),
        ]);

        self::assertSame($existing, $ids['https://example.com/known?utm_source=x']);
        self::assertSame($ids['https://example.com/new'], $ids['https://example.com/new#part']);
        self::assertNotSame($existing, $ids['https://example.com/new']);
        self::assertSame(2, $this->storedRows());
        $this->entityManager->clear();
        $known = $writer->linkFor(self::card('https://example.com/known', 'X'), null);
        self::assertSame('Known', $known->preview()->title);
        self::assertSame('New', $writer->linkFor(self::card('https://example.com/new', 'X'), null)->preview()->title);
    }

    public function testIdsForNoCardsIsEmpty(): void
    {
        self::assertSame([], $this->writer()->idsFor([]));
    }

    public function testIdsForGuessesTheLanguageOfALongCard(): void
    {
        $description = 'Die Bibliothek eröffnet im zweiten Stock einen neuen Lesesaal mit langen Tischen, ruhigen Ecken'
            . ' und einem kleinen Regal mit Reiseführern, die Besucher für eine Woche ausleihen dürfen.';
        $card = new LinkCardContent('https://example.com/de', 'Lesesaal', $description, null, 'example.com');

        $this->writer()->idsFor([$card]);

        $stored = $this->writer()->linkFor($card, null);
        self::assertSame(FeedLanguage::German, $stored->getLanguage());
        self::assertSame(LanguageSource::Guessed, $stored->getLanguageSource());
    }

    public function testAReusedUrlBurnsNoId(): void
    {
        $writer = $this->writer();
        $first = $writer->linkFor(self::card('https://example.com/a', 'A'), null)->requireId();
        $writer->linkFor(self::card('https://example.com/a', 'A again'), null);
        $writer->idsFor([self::card('https://example.com/a', 'A in a batch')]);

        $next = $writer->linkFor(self::card('https://example.com/b', 'B'), null)->requireId();

        self::assertSame($first + 1, $next);
    }

    private static function card(string $url, string $title): LinkCardContent
    {
        return new LinkCardContent($url, $title, null, null, 'example.com');
    }

    private function storedRows(): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM shared_link');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function writer(): SharedLinkWriter
    {
        $sharedLinks = self::getContainer()->get(SharedLinkRepository::class);
        self::assertInstanceOf(SharedLinkRepository::class, $sharedLinks);

        return new SharedLinkWriter(
            $sharedLinks,
            new UrlNormalizer(),
            new PreviewLanguage(new LanguageGuess(new LanguageDetectionFactory()->createForFeedLanguages())),
            new NaiveUtcClock(new MockClock('2026-11-15 10:00:00')),
        );
    }
}
