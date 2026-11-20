<?php

declare(strict_types=1);

namespace App\Tests\Service\Content\LinkCard;

use App\Entity\LinkCardContent;
use App\Entity\SharedLinkResolution;
use App\Enum\FeedLanguage;
use App\Enum\LanguageSource;
use App\Enum\LinkResolution;
use App\Repository\SharedLinkRepository;
use App\Repository\SharedLinkResolutionRepository;
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

        $first = $writer->ensureLink(self::card('https://example.com/a', 'First'), null);
        $again = $writer->ensureLink(self::card('https://example.com/a', 'Second'), FeedLanguage::French);
        $tracked = $writer->ensureLink(self::card('https://example.com/a?utm_source=x', 'Third'), null);

        self::assertSame($first->requireId(), $again->requireId());
        self::assertSame($first->requireId(), $tracked->requireId());
        $this->entityManager->clear();
        $stored = $writer->ensureLink(self::card('https://example.com/a', 'Fourth'), null);
        self::assertSame('First', $stored->preview()->title);
        self::assertSame('https://example.com/a', $stored->preview()->url);
        self::assertNull($stored->getLanguage());
    }

    public function testAShortLinkIsQueued(): void
    {
        $link = $this->writer()->ensureLink(self::card('https://n.pr/4kcyxuV', 'Short'), null);

        self::assertSame(LinkResolution::Pending, $this->resolutionOf($link->requireId()));
    }

    public function testAnArticleUrlIsNotQueued(): void
    {
        $link = $this->writer()->ensureLink(self::card('https://example.com/2026/11/some-story', 'Story'), null);

        self::assertNull($this->resolutionOf($link->requireId()));
    }

    public function testAKnownRowKeepsItsResolution(): void
    {
        $link = $this->writer()->ensureLink(self::card('https://n.pr/4kcyxuV', 'Short'), null);
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE shared_link_resolution SET status = 'unchanged'",
        );

        $this->writer()->ensureLinks([
            self::card('https://n.pr/4kcyxuV', 'Again'),
            self::card('https://n.pr/new', 'New'),
        ]);

        self::assertSame(LinkResolution::Unchanged, $this->resolutionOf($link->requireId()));
        self::assertSame(2, $this->rowsOf('shared_link_resolution'));
    }

    public function testANewUrlIsStoredWithItsLanguage(): void
    {
        $link = $this->writer()->ensureLink(self::card('https://example.com/fr', 'Bonjour'), FeedLanguage::French);

        $this->entityManager->clear();
        $stored = $this->writer()->ensureLink(self::card('https://example.com/fr', 'Other'), null);
        self::assertSame($link->requireId(), $stored->requireId());
        self::assertSame(FeedLanguage::French, $stored->getLanguage());
        self::assertSame(LanguageSource::Stated, $stored->getLanguageSource());
        self::assertEquals(new \DateTimeImmutable('2026-11-15 10:00:00'), $stored->getCreatedAt());
    }

    public function testAReuseClearsTheUnreferencedMark(): void
    {
        $writer = $this->writer();
        $writer->ensureLink(self::card('https://example.com/marked', 'Marked'), null);
        $writer->ensureLinkIds([self::card('https://example.com/batch', 'Batch')]);
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE shared_link SET unreferenced_since = '2026-11-01 10:00:00'",
        );

        $writer->ensureLink(self::card('https://example.com/marked', 'Again'), null);
        $writer->ensureLinkIds([self::card('https://example.com/batch', 'Again')]);

        self::assertSame(
            [null, null],
            $this->entityManager->getConnection()->fetchFirstColumn('SELECT unreferenced_since FROM shared_link'),
        );
    }

    public function testABatchKeepsOneRowPerUrlAndItsStatedLanguages(): void
    {
        $links = $this->writer()->ensureLinks(
            [
                self::card('https://example.com/a', 'A'),
                self::card('https://example.com/a?utm_source=x', 'A again'),
                self::card('https://example.com/b', 'B'),
            ],
            ['https://example.com/b' => FeedLanguage::Spanish],
        );

        self::assertSame(
            ['https://example.com/a', 'https://example.com/a?utm_source=x', 'https://example.com/b'],
            array_keys($links),
        );
        self::assertSame($links['https://example.com/a'], $links['https://example.com/a?utm_source=x']);
        self::assertSame('A', $links['https://example.com/a?utm_source=x']->preview()->title);
        self::assertSame(FeedLanguage::Spanish, $links['https://example.com/b']->getLanguage());
        self::assertSame(2, $this->storedRows());
    }

    public function testKnownLinksAreFoundByUrlAndClaimed(): void
    {
        $writer = $this->writer();
        $writer->ensureLink(self::card('https://example.com/known', 'Known'), null);
        $this->entityManager->getConnection()->executeStatement(
            "UPDATE shared_link SET unreferenced_since = '2026-11-01 10:00:00'",
        );

        $known = $writer->claimKnownLinks([
            'https://example.com/known?utm_source=x',
            'https://example.com/new',
            '/relative',
        ]);

        self::assertSame(['https://example.com/known?utm_source=x'], array_keys($known));
        self::assertSame(1, $this->storedRows());
        self::assertNull($this->entityManager->getConnection()->fetchOne('SELECT unreferenced_since FROM shared_link'));
    }

    public function testAnExistingLinkIsReadWithoutCreatingOne(): void
    {
        $writer = $this->writer();
        $writer->ensureLink(self::card('https://example.com/known', 'Known'), null);

        self::assertSame('Known', $writer->existing('https://example.com/known?utm_source=x')?->preview()->title);
        self::assertNull($writer->existing('https://example.com/new'));
        self::assertNull($writer->existing('/relative'));
        self::assertSame(1, $this->storedRows());
    }

    public function testAUrlThatDoesNotHashHasNoLink(): void
    {
        $this->expectException(UnhashableLinkException::class);

        $this->writer()->ensureLink(self::card('/relative', 'Relative'), null);
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

        $this->writer()->ensureLink(self::card('https://example.com/gone', 'Gone'), null);
    }

    public function testABatchWithAUrlThatDoesNotHashIsRefused(): void
    {
        $this->expectException(UnhashableLinkException::class);

        $this->writer()->ensureLinkIds([self::card('/relative', 'Relative')]);
    }

    public function testIdsForMergesABatch(): void
    {
        $writer = $this->writer();
        $existing = $writer->ensureLink(self::card('https://example.com/known', 'Known'), null)->requireId();

        $ids = $writer->ensureLinkIds([
            self::card('https://example.com/known?utm_source=x', 'Known again'),
            self::card('https://example.com/new', 'New'),
            self::card('https://example.com/new#part', 'New again'),
        ]);

        self::assertSame($existing, $ids['https://example.com/known?utm_source=x']);
        self::assertSame($ids['https://example.com/new'], $ids['https://example.com/new#part']);
        self::assertNotSame($existing, $ids['https://example.com/new']);
        self::assertSame(2, $this->storedRows());
        $this->entityManager->clear();
        $known = $writer->ensureLink(self::card('https://example.com/known', 'X'), null);
        self::assertSame('Known', $known->preview()->title);
        self::assertSame(
            'New',
            $writer->ensureLink(self::card('https://example.com/new', 'X'), null)->preview()->title,
        );
    }

    public function testIdsForNoCardsIsEmpty(): void
    {
        self::assertSame([], $this->writer()->ensureLinkIds([]));
    }

    public function testIdsForGuessesTheLanguageOfALongCard(): void
    {
        $description = 'Die Bibliothek eröffnet im zweiten Stock einen neuen Lesesaal mit langen Tischen, ruhigen Ecken'
            . ' und einem kleinen Regal mit Reiseführern, die Besucher für eine Woche ausleihen dürfen.';
        $card = new LinkCardContent('https://example.com/de', 'Lesesaal', $description, null, 'example.com');

        $this->writer()->ensureLinkIds([$card]);

        $stored = $this->writer()->ensureLink($card, null);
        self::assertSame(FeedLanguage::German, $stored->getLanguage());
        self::assertSame(LanguageSource::Guessed, $stored->getLanguageSource());
    }

    public function testAReusedUrlBurnsNoId(): void
    {
        $writer = $this->writer();
        $first = $writer->ensureLink(self::card('https://example.com/a', 'A'), null)->requireId();
        $writer->ensureLink(self::card('https://example.com/a', 'A again'), null);
        $writer->ensureLinkIds([self::card('https://example.com/a', 'A in a batch')]);

        $next = $writer->ensureLink(self::card('https://example.com/b', 'B'), null)->requireId();

        self::assertSame($first + 1, $next);
    }

    private static function card(string $url, string $title): LinkCardContent
    {
        return new LinkCardContent($url, $title, null, null, 'example.com');
    }

    private function resolutionOf(int $linkId): ?LinkResolution
    {
        $this->entityManager->clear();

        return $this->entityManager->find(SharedLinkResolution::class, $linkId)?->getStatus();
    }

    private function storedRows(): int
    {
        return $this->rowsOf('shared_link');
    }

    private function rowsOf(string $table): int
    {
        $count = $this->entityManager->getConnection()->fetchOne('SELECT COUNT(*) FROM ' . $table);
        self::assertIsNumeric($count);

        return (int) $count;
    }

    private function writer(): SharedLinkWriter
    {
        $sharedLinks = self::getContainer()->get(SharedLinkRepository::class);
        self::assertInstanceOf(SharedLinkRepository::class, $sharedLinks);

        $linkResolutions = self::getContainer()->get(SharedLinkResolutionRepository::class);
        self::assertInstanceOf(SharedLinkResolutionRepository::class, $linkResolutions);

        return new SharedLinkWriter(
            $sharedLinks,
            $linkResolutions,
            new UrlNormalizer(),
            new PreviewLanguage(new LanguageGuess(new LanguageDetectionFactory()->createForFeedLanguages())),
            new NaiveUtcClock(new MockClock('2026-11-15 10:00:00')),
        );
    }
}
