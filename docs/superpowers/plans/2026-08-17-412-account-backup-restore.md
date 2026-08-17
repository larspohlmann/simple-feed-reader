# Account Backup and Restore (#412) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** One gzipped-NDJSON file backs up everything an account owns; uploading it to another instance wipes the target account and reproduces the source account there.

**Architecture:** A streaming exporter yields NDJSON lines per kind (header, account, tag, feed, subscription, entry, entryState, footer) which a `StreamedResponse` gzips incrementally. The restore holds the raw gzip body in memory (~4 MiB real, 25 MiB cap), makes two full passes over it with a zlib stream filter — pass 1 validates and counts, pass 2 loads — and only wipes (via a standalone `AccountReset`) between the passes, after the fit check accepted the whole file. Entries insert via batched 500-row SQL; everything else goes through the ORM.

**Tech Stack:** Symfony 7.4, PHP 8.4, Doctrine ORM/DBAL, Angular 20 signals, Transloco.

**Spec:** `docs/superpowers/specs/2026-08-17-412-account-backup-restore-design.md`. Read it before starting any task. One deliberate addition over the spec: a `footer` line with per-kind counts, so a gzip truncated exactly at a line boundary cannot pass validation silently ("it refuses; it never truncates").

## Global Constraints

- `declare(strict_types=1)` in every PHP file; PSR-12; PHPStan level max, no new baselines.
- **Every touched `src` file must be PHPMD-clean before commit** (codesize ruleset) — fix the design, not the threshold.
- House style: `final readonly class`, constructor promotion, guard clauses, no boolean flag parameters, ≤3 params (else DTO), errors are typed exceptions in `Service/*/Exception/` or `src/Exception/`.
- Controllers stay thin (`ThinControllerRule`): no private methods carrying responsibility; JSON assembly lives in `src/Http/*Json.php`.
- Datetimes persist as **naive UTC** — normalise every parsed date to UTC before persisting.
- phptramp: don't thread a parameter through 3+ methods across 2+ classes; give it a home (context object / per-pass collaborator).
- Frontend: standalone components + signals, styles in sibling `.scss` (no hex colours / raw `px` outside `theme/`), Prettier 100-col, every new i18n key in **both** `en.json` and `de.json`.
- Run backend commands from `backend/`, frontend from `frontend/`.
- Tests are production code. Bulk-DQL deletion tests must `$this->em->clear()` before asserting a row is gone.
- Commit after every task with `type(#412): message`.

## File Map

| File | Role |
|---|---|
| `backend/src/Service/Backup/BackupSchema.php` | Version + kind constants, shared vocabulary |
| `backend/src/Service/Backup/GzipLineReader.php` | gzip bytes → generator of lines |
| `backend/src/Service/Backup/Dto/*.php` | One readonly DTO per line kind |
| `backend/src/Service/Backup/BackupReader.php` | lines → validated, ordered DTO stream |
| `backend/src/Service/Backup/Exception/InvalidBackupException.php` | 422 `invalid_backup` |
| `backend/src/Service/Backup/Exception/BackupDoesNotFitException.php` | 409 `backup_does_not_fit` |
| `backend/src/Service/Backup/AccountBackupExporter.php` | account → generator of NDJSON strings |
| `backend/src/Service/Backup/BackupDownloadResponseFactory.php` | generator → gzipped `StreamedResponse` |
| `backend/src/Service/Backup/BackupInventory.php` | pass-1 result: counts per kind + header |
| `backend/src/Service/Backup/BackupInspector.php` | pass 1: full validation + counting |
| `backend/src/Service/Backup/BackupFitCheck.php` | refusals (limit, ceiling) |
| `backend/src/Service/Backup/RestorePreview.php` / `RestorePreviewer.php` | dry-run report |
| `backend/src/Service/Backup/EntryBatchInserter.php` | 500-row multi-VALUES entry inserts |
| `backend/src/Service/Backup/RestoreLoader.php` | pass 2: write everything |
| `backend/src/Service/Backup/AccountRestorer.php` | confirm → inspect → fit → reset → load |
| `backend/src/Service/Account/AccountReset.php` | the wipe, standalone |
| `backend/src/Controller/Api/AccountBackupController.php` | 3 thin actions |
| `backend/src/Http/RestorePreviewJson.php`, `RestoreResultJson.php` | response shapes |
| `docker/nginx/default.conf` | 25m body cap on the restore route |
| `frontend/src/app/core/save-as.ts` | shared blob-download helper |
| `frontend/src/app/reader/reader-api.ts` | 3 new API methods |
| `frontend/src/app/settings/backup-section.component.*` | UI section |
| `frontend/public/i18n/en.json`, `de.json` | strings |

---

### Task 1: GzipLineReader

The restore reads the raw gzip body twice (validate, then load). `compress.zlib://php://input` reads once and needs a file path; instead the body string goes into a `php://memory` stream with a `zlib.inflate` filter, and `fgets()` does the line splitting (its internal buffer is the "carry" the spec warns about — O(n), never repeated `substr()`).

**Files:**
- Create: `backend/src/Service/Backup/GzipLineReader.php`
- Create: `backend/src/Service/Backup/Exception/InvalidBackupException.php`
- Test: `backend/tests/Service/Backup/GzipLineReaderTest.php`

**Interfaces:**
- Produces: `GzipLineReader::lines(string $gzipBytes): \Generator<int, string>` — yields lines without their trailing `\n`, skips a final empty line. Throws `InvalidBackupException` on bytes that are not gzip.
- Produces: `InvalidBackupException extends ApiException` — `('invalid_backup', 422, 'Invalid backup file', $detail)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Service\Backup\Exception\InvalidBackupException;
use App\Service\Backup\GzipLineReader;
use PHPUnit\Framework\TestCase;

final class GzipLineReaderTest extends TestCase
{
    public function testYieldsEachLineWithoutItsNewline(): void
    {
        $gzip = (string) gzencode("first\nsecond\nthird\n");

        self::assertSame(['first', 'second', 'third'], iterator_to_array(GzipLineReader::lines($gzip), false));
    }

    public function testAFinalLineWithoutNewlineStillArrives(): void
    {
        $gzip = (string) gzencode("first\nlast-no-newline");

        self::assertSame(['first', 'last-no-newline'], iterator_to_array(GzipLineReader::lines($gzip), false));
    }

    public function testALineLongerThanAnyInternalBufferSurvivesIntact(): void
    {
        $long = str_repeat('x', 2_000_000);
        $gzip = (string) gzencode($long . "\nshort\n");

        $lines = iterator_to_array(GzipLineReader::lines($gzip), false);

        self::assertSame([$long, 'short'], $lines);
    }

    public function testBytesThatAreNotGzipAreRefused(): void
    {
        $this->expectException(InvalidBackupException::class);

        iterator_to_array(GzipLineReader::lines('this is not gzip'), false);
    }

    public function testEmptyInputIsRefused(): void
    {
        $this->expectException(InvalidBackupException::class);

        iterator_to_array(GzipLineReader::lines(''), false);
    }
}
```

- [ ] **Step 2: Run the test, expect failure**

Run from `backend/`: `php bin/phpunit tests/Service/Backup/GzipLineReaderTest.php`
Expected: errors — class not found.

- [ ] **Step 3: Implement**

`backend/src/Service/Backup/Exception/InvalidBackupException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Backup\Exception;

use App\Exception\ApiException;

/**
 * The uploaded bytes do not parse as a backup: not gzip, not NDJSON, a
 * missing or misordered line, or a schema version this instance cannot read.
 * Always raised BEFORE any deletion — a file that cannot be fully read must
 * never cost the account anything.
 */
final class InvalidBackupException extends ApiException
{
    public function __construct(string $detail)
    {
        parent::__construct('invalid_backup', 422, 'Invalid backup file', $detail);
    }
}
```

`backend/src/Service/Backup/GzipLineReader.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Backup\Exception\InvalidBackupException;

/**
 * Streams the lines of a gzipped text held in memory. The restore reads its
 * upload twice (validate, then load), and php://input yields its bytes only
 * once — so the caller holds the ~4 MiB gzip body as a string and this class
 * inflates it lazily per pass. fgets() does the line assembly in C with an
 * internal carry; never re-split a shared buffer with substr() — that is
 * O(n²) and measured 100× slower (see the spec's appendix).
 */
final readonly class GzipLineReader
{
    /**
     * A gzip stream starts 0x1f 0x8b; anything else would make zlib.inflate
     * produce silent garbage instead of an error, so refuse it up front.
     */
    private const string GZIP_MAGIC = "\x1f\x8b";

    /**
     * @return \Generator<int, string> the lines, each without its trailing newline
     *
     * @throws InvalidBackupException
     */
    public static function lines(string $gzipBytes): \Generator
    {
        if (!str_starts_with($gzipBytes, self::GZIP_MAGIC)) {
            throw new InvalidBackupException('The file is not gzip-compressed.');
        }

        $stream = fopen('php://memory', 'r+b');
        if (false === $stream) {
            throw new \RuntimeException('Cannot open an in-memory stream.');
        }

        try {
            fwrite($stream, $gzipBytes);
            rewind($stream);
            // window 15+32: accept a gzip (or zlib) header, matching gzdecode().
            stream_filter_append($stream, 'zlib.inflate', \STREAM_FILTER_READ, ['window' => 15 + 32]);

            while (false !== ($line = fgets($stream))) {
                yield rtrim($line, "\n");
            }
        } finally {
            fclose($stream);
        }
    }
}
```

Note: `fgets()` without a length grows its buffer to the full line, so the 2 MB-line test passes without tuning.

- [ ] **Step 4: Run the test, expect green**

Run: `php bin/phpunit tests/Service/Backup/GzipLineReaderTest.php`
Expected: 5 passing.

- [ ] **Step 5: Static gates on the new files**

Run: `composer cs && composer stan && composer md` (warm the cache first if PHPStan asks: `bin/console cache:warmup`).
Expected: clean.

- [ ] **Step 6: Commit**

```bash
git add src/Service/Backup tests/Service/Backup
git commit -m "feat(#412): stream lines out of an in-memory gzip body"
```

---

### Task 2: Line DTOs and BackupReader

One readonly DTO per line kind, built from a decoded JSON array with strict key checks, and a reader that enforces the file grammar: header first, kinds in order, account exactly once, footer last with matching counts.

**Files:**
- Create: `backend/src/Service/Backup/BackupSchema.php`
- Create: `backend/src/Service/Backup/Dto/BackupHeader.php`
- Create: `backend/src/Service/Backup/Dto/AccountLine.php`
- Create: `backend/src/Service/Backup/Dto/TagLine.php`
- Create: `backend/src/Service/Backup/Dto/FeedLine.php`
- Create: `backend/src/Service/Backup/Dto/SubscriptionTagRef.php`
- Create: `backend/src/Service/Backup/Dto/SubscriptionLine.php`
- Create: `backend/src/Service/Backup/Dto/EntryLine.php`
- Create: `backend/src/Service/Backup/Dto/EntryStateLine.php`
- Create: `backend/src/Service/Backup/Dto/FooterLine.php`
- Create: `backend/src/Service/Backup/Dto/LineField.php`
- Create: `backend/src/Service/Backup/BackupReader.php`
- Test: `backend/tests/Service/Backup/BackupReaderTest.php`

**Interfaces:**
- Produces: `BackupSchema::VERSION = 1`; kind constants `KIND_HEADER = 'header'`, `KIND_ACCOUNT = 'account'`, `KIND_TAG = 'tag'`, `KIND_FEED = 'feed'`, `KIND_SUBSCRIPTION = 'subscription'`, `KIND_ENTRY = 'entry'`, `KIND_ENTRY_STATE = 'entryState'`, `KIND_FOOTER = 'footer'`.
- Produces: `BackupReader::read(string $gzipBytes): \Generator<int, object>` yielding, in order: one `BackupHeader`, one `AccountLine`, then `TagLine`*, `FeedLine`*, `SubscriptionLine`*, `EntryLine`*, `EntryStateLine`* — the footer is consumed and verified, not yielded. Throws `InvalidBackupException` (bad JSON, unknown kind, misorder, missing header/account/footer, count mismatch, unsupported schema version).
- Produces the DTO shapes below — later tasks construct and consume them verbatim.
- Produces: `LineField` — static helpers each dto factory uses: `string(array $line, string $key): string`, `stringOrNull(...): ?string`, `int(...): int`, `intOrNull(...): ?int`, `bool(...): bool`, `date(...): \DateTimeImmutable`, `dateOrNull(...): ?\DateTimeImmutable`. Each throws `InvalidBackupException` naming the key on a missing/mistyped value; dates parse ISO-8601 and are normalised with `->setTimezone(new \DateTimeZone('UTC'))`.

DTO shapes (all `final readonly class` in `App\Service\Backup\Dto`, all with a `public static function fromLine(array $line): self` factory using `LineField`, all constructor-promoted public properties):

```php
BackupHeader:       int $schemaVersion, \DateTimeImmutable $createdAt, ?string $sourceUrl, ?string $sourceEmail
AccountLine:        string $locale, bool $scrapeFallbackEnabled, ?RecommendationSettingsValues $recommendationSettings
TagLine:            string $name, ?string $color, ?string $icon, int $position
FeedLine:           string $url, ?string $siteUrl, ?string $title, ?string $description, ?string $faviconUrl, string $sourceFormat
SubscriptionTagRef: string $name, int $position
SubscriptionLine:   string $feedUrl, ?string $customTitle, int $position, ?\DateTimeImmutable $markedReadUntil, \DateTimeImmutable $createdAt, /** @var list<SubscriptionTagRef> */ array $tags
EntryLine:          string $feedUrl, string $guid, string $guidHash, ?string $url, string $title, ?string $author, ?string $summary, ?string $contentHtml, ?string $imageUrl, ?int $imageWidth, ?int $imageHeight, ?\DateTimeImmutable $publishedAt, \DateTimeImmutable $createdAt, \DateTimeImmutable $effectiveDate
EntryStateLine:     string $feedUrl, string $guidHash, bool $isRead, bool $isFavorite, bool $isKept, ?\DateTimeImmutable $readAt, bool $isViewed, ?\DateTimeImmutable $viewedAt
FooterLine:         /** @var array<string, int> */ array $counts   // kind => count over tag/feed/subscription/entry/entryState
```

Notes on two of them:

- `EntryStateLine` carries `guidHash`, not `guid`: the restore matches states to rows by `(feed_id, guid_hash)`, and hashing a guid outside the `Entry` constructor is forbidden — so the export writes the hash it already has.
- `AccountLine.recommendationSettings` reuses `App\Service\Recommendation\RecommendationSettingsValues` — the existing stored-shape DTO — so the field list cannot drift. Its factory builds it explicitly (`new RecommendationSettingsValues(guidancePrompt: LineField::stringOrNull($rs, 'guidancePrompt'), favoritesCap: LineField::int($rs, 'favoritesCap'), …)` for all 11 fields); `null` for the whole object means "no settings row on the source".
- `EntryLine` has 14 properties — mirror the row 1:1 and add `@SuppressWarnings("PHPMD.ExcessiveParameterList")` with the same justification comment `RecommendationSettingsValues` uses ("pure data carrier that mirrors the row 1:1"). Same for `SubscriptionLine`/`EntryStateLine` if PHPMD objects.

- [ ] **Step 1: Write the failing test**

`backend/tests/Service/Backup/BackupReaderTest.php` — build files with a helper:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Service\Backup\BackupReader;
use App\Service\Backup\Dto\AccountLine;
use App\Service\Backup\Dto\BackupHeader;
use App\Service\Backup\Dto\EntryLine;
use App\Service\Backup\Dto\EntryStateLine;
use App\Service\Backup\Dto\FeedLine;
use App\Service\Backup\Dto\SubscriptionLine;
use App\Service\Backup\Dto\TagLine;
use App\Service\Backup\Exception\InvalidBackupException;
use PHPUnit\Framework\TestCase;

final class BackupReaderTest extends TestCase
{
    /** @param list<array<string, mixed>> $lines */
    private static function gzipOf(array $lines): string
    {
        $ndjson = implode("\n", array_map(
            static fn (array $line): string => json_encode($line, \JSON_THROW_ON_ERROR),
            $lines,
        )) . "\n";

        return (string) gzencode($ndjson);
    }

    /** @return array<string, mixed> */
    private static function header(int $schemaVersion = 1): array
    {
        return [
            'kind' => 'header',
            'schemaVersion' => $schemaVersion,
            'createdAt' => '2026-08-17T09:00:00+00:00',
            'sourceUrl' => 'https://source.example',
            'sourceEmail' => 'source@example.com',
        ];
    }

    /** @return array<string, mixed> */
    private static function account(): array
    {
        return [
            'kind' => 'account',
            'locale' => 'de',
            'scrapeFallbackEnabled' => true,
            'recommendationSettings' => null,
        ];
    }

    /** @return array<string, mixed> */
    private static function footer(array $counts = []): array
    {
        return ['kind' => 'footer', 'counts' => $counts + [
            'tag' => 0, 'feed' => 0, 'subscription' => 0, 'entry' => 0, 'entryState' => 0,
        ]];
    }

    public function testReadsAMinimalValidFile(): void
    {
        $gzip = self::gzipOf([self::header(), self::account(), self::footer()]);

        $objects = iterator_to_array(new BackupReader()->read($gzip), false);

        self::assertCount(2, $objects);
        self::assertInstanceOf(BackupHeader::class, $objects[0]);
        self::assertSame(1, $objects[0]->schemaVersion);
        self::assertSame('source@example.com', $objects[0]->sourceEmail);
        self::assertInstanceOf(AccountLine::class, $objects[1]);
        self::assertSame('de', $objects[1]->locale);
        self::assertTrue($objects[1]->scrapeFallbackEnabled);
        self::assertNull($objects[1]->recommendationSettings);
    }

    public function testReadsEveryKindInOrderAndNormalisesDatesToUtc(): void
    {
        $gzip = self::gzipOf([
            self::header(),
            self::account(),
            ['kind' => 'tag', 'name' => 'Tech', 'color' => '#aabbcc', 'icon' => 'bolt', 'position' => 2],
            ['kind' => 'feed', 'url' => 'https://f.example/feed.xml', 'siteUrl' => null, 'title' => 'F',
                'description' => null, 'faviconUrl' => null, 'sourceFormat' => 'xml'],
            ['kind' => 'subscription', 'feedUrl' => 'https://f.example/feed.xml', 'customTitle' => null,
                'position' => 0, 'markedReadUntil' => null, 'createdAt' => '2026-07-01T02:00:00+02:00',
                'tags' => [['name' => 'Tech', 'position' => 1]]],
            ['kind' => 'entry', 'feedUrl' => 'https://f.example/feed.xml', 'guid' => 'g1',
                'guidHash' => hash('sha256', 'g1'), 'url' => null, 'title' => 'One', 'author' => null,
                'summary' => null, 'contentHtml' => '<p>x</p>', 'imageUrl' => null, 'imageWidth' => null,
                'imageHeight' => null, 'publishedAt' => null, 'createdAt' => '2026-08-01T00:00:00+00:00',
                'effectiveDate' => '2026-08-01T00:00:00+00:00'],
            ['kind' => 'entryState', 'feedUrl' => 'https://f.example/feed.xml',
                'guidHash' => hash('sha256', 'g1'), 'isRead' => true, 'isFavorite' => false,
                'isKept' => false, 'readAt' => '2026-08-02T00:00:00+00:00', 'isViewed' => false,
                'viewedAt' => null],
            self::footer(['tag' => 1, 'feed' => 1, 'subscription' => 1, 'entry' => 1, 'entryState' => 1]),
        ]);

        $objects = iterator_to_array(new BackupReader()->read($gzip), false);

        self::assertCount(7, $objects);
        self::assertInstanceOf(TagLine::class, $objects[2]);
        self::assertInstanceOf(FeedLine::class, $objects[3]);
        $subscription = $objects[4];
        self::assertInstanceOf(SubscriptionLine::class, $subscription);
        // +02:00 wall clock normalised to naive-UTC storage time.
        self::assertSame('2026-07-01 00:00:00', $subscription->createdAt->format('Y-m-d H:i:s'));
        self::assertSame('Tech', $subscription->tags[0]->name);
        self::assertInstanceOf(EntryLine::class, $objects[5]);
        self::assertInstanceOf(EntryStateLine::class, $objects[6]);
    }

    public function testRefusesANewerSchemaVersion(): void
    {
        $gzip = self::gzipOf([self::header(schemaVersion: 2), self::account(), self::footer()]);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/schema version/i');

        iterator_to_array(new BackupReader()->read($gzip), false);
    }

    public function testRefusesWhenTheFirstLineIsNotAHeader(): void
    {
        $gzip = self::gzipOf([self::account(), self::header(), self::footer()]);

        $this->expectException(InvalidBackupException::class);

        iterator_to_array(new BackupReader()->read($gzip), false);
    }

    public function testRefusesKindsOutOfOrder(): void
    {
        $gzip = self::gzipOf([
            self::header(),
            self::account(),
            ['kind' => 'subscription', 'feedUrl' => 'https://f.example/feed.xml', 'customTitle' => null,
                'position' => 0, 'markedReadUntil' => null, 'createdAt' => '2026-07-01T00:00:00+00:00',
                'tags' => []],
            ['kind' => 'feed', 'url' => 'https://f.example/feed.xml', 'siteUrl' => null, 'title' => null,
                'description' => null, 'faviconUrl' => null, 'sourceFormat' => 'xml'],
            self::footer(['subscription' => 1, 'feed' => 1]),
        ]);

        $this->expectException(InvalidBackupException::class);

        iterator_to_array(new BackupReader()->read($gzip), false);
    }

    public function testRefusesAFileWithoutAFooter(): void
    {
        // Simulates a truncation that happens to cut exactly at a line boundary.
        $gzip = self::gzipOf([self::header(), self::account()]);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/truncated/i');

        iterator_to_array(new BackupReader()->read($gzip), false);
    }

    public function testRefusesAFooterWhoseCountsDisagree(): void
    {
        $gzip = self::gzipOf([
            self::header(),
            self::account(),
            ['kind' => 'tag', 'name' => 'Tech', 'color' => null, 'icon' => null, 'position' => 0],
            self::footer(['tag' => 2]),
        ]);

        $this->expectException(InvalidBackupException::class);

        iterator_to_array(new BackupReader()->read($gzip), false);
    }

    public function testRefusesLinesAfterTheFooter(): void
    {
        $gzip = self::gzipOf([
            self::header(), self::account(), self::footer(),
            ['kind' => 'tag', 'name' => 'Late', 'color' => null, 'icon' => null, 'position' => 0],
        ]);

        $this->expectException(InvalidBackupException::class);

        iterator_to_array(new BackupReader()->read($gzip), false);
    }

    public function testRefusesBrokenJsonWithTheLineNumber(): void
    {
        $ndjson = json_encode(self::header(), \JSON_THROW_ON_ERROR) . "\n{broken\n";
        $gzip = (string) gzencode($ndjson);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/line 2/');

        iterator_to_array(new BackupReader()->read($gzip), false);
    }

    public function testRefusesAMistypedFieldNamingTheKey(): void
    {
        $gzip = self::gzipOf([
            self::header(),
            self::account(),
            ['kind' => 'tag', 'name' => 42, 'color' => null, 'icon' => null, 'position' => 0],
        ]);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessageMatches('/name/');

        iterator_to_array(new BackupReader()->read($gzip), false);
    }
}
```

- [ ] **Step 2: Run it, expect class-not-found failures**

Run: `php bin/phpunit tests/Service/Backup/BackupReaderTest.php`

- [ ] **Step 3: Implement**

`BackupSchema` (all consts as in Interfaces above). `LineField` example (all other helpers follow the same pattern):

```php
public static function string(array $line, string $key): string
{
    $value = $line[$key] ?? null;
    if (!\is_string($value)) {
        throw new InvalidBackupException(sprintf('Field "%s" is missing or not a string.', $key));
    }

    return $value;
}

public static function date(array $line, string $key): \DateTimeImmutable
{
    try {
        return (new \DateTimeImmutable(self::string($line, $key)))
            ->setTimezone(new \DateTimeZone('UTC'));
    } catch (\DateMalformedStringException) {
        throw new InvalidBackupException(sprintf('Field "%s" is not a valid date.', $key));
    }
}
```

`BackupReader` core:

```php
/**
 * Reads a backup file front to back, enforcing its grammar: one header first,
 * one account line, then tags, feeds, subscriptions, entries and entry states
 * in that order, closed by a footer whose counts must match what was read.
 * The footer is the truncation guard — without it, a gzip cut exactly at a
 * line boundary would read as a smaller, valid backup and the restore would
 * silently load a partial account.
 */
final readonly class BackupReader
{
    private const array KIND_RANK = [
        BackupSchema::KIND_HEADER => 0,
        BackupSchema::KIND_ACCOUNT => 1,
        BackupSchema::KIND_TAG => 2,
        BackupSchema::KIND_FEED => 3,
        BackupSchema::KIND_SUBSCRIPTION => 4,
        BackupSchema::KIND_ENTRY => 5,
        BackupSchema::KIND_ENTRY_STATE => 6,
        BackupSchema::KIND_FOOTER => 7,
    ];

    /** @return \Generator<int, object> */
    public function read(string $gzipBytes): \Generator
    { /* iterate GzipLineReader::lines(); skip fully empty lines; decode with
         json_decode($line, true, flags: JSON_THROW_ON_ERROR) wrapped to
         InvalidBackupException carrying the 1-based line number; dispatch on
         'kind' via a match to Dto::fromLine(); track rank monotonicity,
         header-first, single account, counts per kind; on footer, compare
         counts and set a done flag; any line after footer refuses; at EOF
         without footer throw InvalidBackupException('The file is truncated —
         the closing footer line is missing.'). */ }
}
```

Keep `read()` under PHPMD's method-length radar by extracting private helpers (`decodeLine`, `assertOrdered`, `toDto`, `verifyFooter`) — each doing one thing.

- [ ] **Step 4: Run the test, expect green**

Run: `php bin/phpunit tests/Service/Backup/`

- [ ] **Step 5: Static gates**

Run: `composer check && composer md`
Expected: clean (fix any finding in the touched files before committing).

- [ ] **Step 6: Commit**

```bash
git add src/Service/Backup tests/Service/Backup
git commit -m "feat(#412): decode and validate the backup NDJSON grammar"
```

---

### Task 3: AccountBackupExporter with a pinned streaming property

The exporter yields NDJSON strings for one account in file order. Entries are read per feed in ascending-id keyset batches with `$em->clear()` between them — the appendix's 349.6 MiB buffered read is the failure mode this task's last test exists to prevent.

**Files:**
- Create: `backend/src/Service/Backup/AccountBackupExporter.php`
- Modify: `backend/src/Repository/EntryRepository.php` (add `forFeedAfterId`)
- Modify: `backend/src/Repository/EntryStateRepository.php` (add `forUserAfterEntryId`)
- Test: `backend/tests/Service/Backup/AccountBackupExporterTest.php`

**Interfaces:**
- Produces: `AccountBackupExporter::lines(User $user, ?string $sourceUrl): \Generator<int, string>` — yields JSON strings WITHOUT trailing newlines, in file order, footer last.
- Produces: `EntryRepository::forFeedAfterId(int $feedId, int $afterId, int $limit): list<Entry>` — ascending id keyset, feed join NOT needed (caller knows the feed).
- Produces: `EntryStateRepository::forUserAfterEntryId(int $userId, int $afterEntryId, int $limit): list<EntryState>` — ascending entry-id keyset, `addSelect('e', 'f')->join('s.entry', 'e')->join('e.feed', 'f')` so serialising a state costs no extra query.
- Consumes: `BackupSchema` constants, `TagRepository::findForUser`, `SubscriptionRepository::findForUserWithTags`, `RecommendationSettingsRepository::findForUser`, `Subscription::getSubscriptionTags()` (ordered join rows), `User::getPreferences()`, `ClockInterface`.

Serialisation rules:

- Dates: `$date->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM)`, null stays null.
- `json_encode($line, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)`.
- Feed lines: one per distinct subscribed feed, fields exactly `url, siteUrl, title, description, faviconUrl, sourceFormat` — never etag/lastModified/status/schedule.
- `recommendationSettings`: `null` when `RecommendationSettingsRepository::findForUser()` returns null, else the 11 public properties of `values()` by name.
- Entry batches: `ENTRY_BATCH = 500`; after each batch `$this->em->clear()`. Feed ids and the User are re-read as scalars **before** the entry loop (`$userId`, `list<int> $feedIds` from the subscription pass) so the clear cannot detach anything still needed. Entry-state batches likewise, keyset on entry id.
- The exporter counts tag/feed/subscription/entry/entryState lines as it yields and closes with the footer.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Backup\AccountBackupExporter;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountBackupExporterTest extends DbTestCase
{
    private function makeUser(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email, locale: 'de');
    }

    private function exporter(): AccountBackupExporter
    {
        $exporter = self::getContainer()->get(AccountBackupExporter::class);
        self::assertInstanceOf(AccountBackupExporter::class, $exporter);

        return $exporter;
    }

    /** @return list<array<string, mixed>> */
    private function decodedLines(User $user): array
    {
        $lines = [];
        foreach ($this->exporter()->lines($user, 'https://source.example') as $line) {
            $decoded = json_decode($line, true, flags: \JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded);
            $lines[] = $decoded;
        }

        return $lines;
    }

    public function testExportsEveryKindInFileOrderWithAClosingFooter(): void
    {
        $user = $this->makeUser('export-order@example.com');
        $feed = new Feed('https://one.example/feed.xml');
        $feed->setTitle('One');
        $feed->setSiteUrl('https://one.example');
        $this->em->persist($feed);
        $tag = new Tag($user, 'Tech');
        $tag->setColor('#a1b2c3');
        $tag->setPosition(1);
        $this->em->persist($tag);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $subscription->setCustomTitle('My One');
        $subscription->setPosition(4);
        $subscription->setMarkedReadUntil(new \DateTimeImmutable('2026-08-01T00:00:00Z'));
        $subscription->addTag($tag, 3);
        $this->em->persist($subscription);
        $entry = new Entry(
            $feed,
            'guid-1',
            'https://one.example/a',
            'Article',
            new \DateTimeImmutable('2026-08-02T00:00:00Z'),
            new \DateTimeImmutable('2026-08-02T00:00:00Z'),
        );
        $entry->setContentHtml('<p>body</p>');
        $this->em->persist($entry);
        $state = new EntryState($user, $entry);
        $state->setIsFavorite(true);
        $state->markViewed(new \DateTimeImmutable('2026-08-03T00:00:00Z'));
        $this->em->persist($state);
        $this->em->flush();

        $lines = $this->decodedLines($user);

        self::assertSame(
            ['header', 'account', 'tag', 'feed', 'subscription', 'entry', 'entryState', 'footer'],
            array_column($lines, 'kind'),
        );
        self::assertSame(1, $lines[0]['schemaVersion']);
        self::assertSame('export-order@example.com', $lines[0]['sourceEmail']);
        self::assertSame('https://source.example', $lines[0]['sourceUrl']);
        self::assertSame('de', $lines[1]['locale']);
        self::assertSame('Tech', $lines[2]['name']);
        self::assertSame(1, $lines[2]['position']);
        self::assertSame('https://one.example/feed.xml', $lines[3]['url']);
        self::assertArrayNotHasKey('etag', $lines[3]);
        self::assertArrayNotHasKey('status', $lines[3]);
        self::assertSame('My One', $lines[4]['customTitle']);
        self::assertSame(4, $lines[4]['position']);
        self::assertSame([['name' => 'Tech', 'position' => 3]], $lines[4]['tags']);
        self::assertSame('guid-1', $lines[5]['guid']);
        self::assertSame(hash('sha256', 'guid-1'), $lines[5]['guidHash']);
        self::assertSame('<p>body</p>', $lines[5]['contentHtml']);
        self::assertTrue($lines[6]['isFavorite']);
        self::assertTrue($lines[6]['isViewed']);
        self::assertSame(
            ['tag' => 1, 'feed' => 1, 'subscription' => 1, 'entry' => 1, 'entryState' => 1],
            $lines[7]['counts'],
        );
    }

    public function testExportsOnlyTheGivenUsersRows(): void
    {
        $user = $this->makeUser('export-mine@example.com');
        $other = $this->makeUser('export-other@example.com');
        $feed = new Feed('https://shared.example/feed.xml');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->persist(new Subscription($other, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $otherTag = new Tag($other, 'Not yours');
        $this->em->persist($otherTag);
        $entry = new Entry(
            $feed,
            'g',
            null,
            'A',
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
        );
        $this->em->persist($entry);
        $this->em->persist(new EntryState($other, $entry));
        $this->em->flush();

        $lines = $this->decodedLines($user);

        $kinds = array_count_values(array_column($lines, 'kind'));
        self::assertSame(1, $kinds['subscription']);
        self::assertSame(1, $kinds['entry']);
        self::assertArrayNotHasKey('tag', $kinds);
        self::assertArrayNotHasKey('entryState', $kinds);
    }

    public function testEntryReadingStaysBatchedNotBuffered(): void
    {
        $user = $this->makeUser('export-streams@example.com');
        $feed = new Feed('https://big.example/feed.xml');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        for ($i = 0; $i < 1201; ++$i) {
            $entry = new Entry(
                $feed,
                'guid-' . $i,
                null,
                'Entry ' . $i,
                new \DateTimeImmutable('2026-08-01T00:00:00Z'),
                new \DateTimeImmutable('2026-08-01T00:00:00Z'),
            );
            $this->em->persist($entry);
            if (0 === $i % 200) {
                $this->em->flush();
            }
        }
        $this->em->flush();
        $this->em->clear();
        $user = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $user);

        $entryLines = 0;
        foreach ($this->exporter()->lines($user, null) as $line) {
            if (!str_contains($line, '"kind":"entry"')) {
                continue;
            }
            ++$entryLines;
            // The identity map must never hold the whole corpus: a buffered
            // SELECT hydrates all 1201 entries before the first yield, which
            // is exactly the 349.6 MiB failure the spec measured.
            $identityMap = $this->em->getUnitOfWork()->getIdentityMap();
            $held = \count($identityMap[Entry::class] ?? []);
            self::assertLessThanOrEqual(500, $held, 'entry hydration is not batched');
        }
        self::assertSame(1201, $entryLines);
    }
}
```

- [ ] **Step 2: Run it, expect failure**

Run: `php bin/phpunit tests/Service/Backup/AccountBackupExporterTest.php`

- [ ] **Step 3: Implement the repository methods**

`EntryRepository::forFeedAfterId` — same shape as the existing `entriesAfterId` (`id > :afterId`, `orderBy e.id ASC`, `setMaxResults`), plus `andWhere('e.feed = :feed')`, no feed join. `EntryStateRepository::forUserAfterEntryId`:

```php
/**
 * One user's states in ascending entry-id slices — the backup's keyset walk.
 * Entry and feed ride along eagerly: the serialiser needs guidHash and the
 * feed URL for every row, and a lazy load here would cost two queries per
 * state.
 *
 * @return list<EntryState>
 */
public function forUserAfterEntryId(int $userId, int $afterEntryId, int $limit): array
{
    /** @var list<EntryState> $states */
    $states = $this->createQueryBuilder('s')
        ->addSelect('e', 'f')
        ->join('s.entry', 'e')
        ->join('e.feed', 'f')
        ->andWhere('s.user = :userId')
        ->andWhere('e.id > :afterEntryId')
        ->setParameter('userId', $userId)
        ->setParameter('afterEntryId', $afterEntryId)
        ->orderBy('e.id', 'ASC')
        ->setMaxResults($limit)
        ->getQuery()
        ->getResult();

    return $states;
}
```

- [ ] **Step 4: Implement the exporter**

Structure (keeps every method single-purpose for PHPMD):

```php
final readonly class AccountBackupExporter
{
    private const int ENTRY_BATCH = 500;

    public function __construct(
        private EntityManagerInterface $em,
        private TagRepository $tags,
        private SubscriptionRepository $subscriptions,
        private EntryRepository $entries,
        private EntryStateRepository $entryStates,
        private RecommendationSettingsRepository $recommendationSettings,
        private ClockInterface $clock,
    ) {
    }

    /** @return \Generator<int, string> */
    public function lines(User $user, ?string $sourceUrl): \Generator
    {
        $counts = ['tag' => 0, 'feed' => 0, 'subscription' => 0, 'entry' => 0, 'entryState' => 0];
        yield $this->headerLine($user, $sourceUrl);
        yield $this->accountLine($user);
        // tag lines … feed lines … subscription lines, counting as they go;
        // collect $feedIds (list<int>) and $userId BEFORE the entry loop —
        // the per-batch clear() detaches every managed entity.
        yield from $this->entryLines($feedIds, $counts);
        yield from $this->entryStateLines($userId, $counts);
        yield $this->encode(['kind' => BackupSchema::KIND_FOOTER, 'counts' => $counts]);
    }
}
```

`entryLines`: for each feed id, loop `forFeedAfterId($feedId, $lastId, self::ENTRY_BATCH)` until it returns fewer than the batch size; yield one encoded line per entry; `$this->em->clear()` after each batch. `entryStateLines`: same with `forUserAfterEntryId`. The entry serialiser writes all 14 `EntryLine` fields, `guidHash` from `$entry->getGuidHash()`. Subscription tag refs come from `getSubscriptionTags()` (already position-ordered).

**Passing `$counts` by reference is fine inside one class** (private generator helpers `entryLines(array $feedIds, array &$counts)`); phptramp only counts cross-class tunnels, but if it still warns, promote counts to a tiny mutable `LineCounts` value holder instead.

- [ ] **Step 5: Run the test, expect green; run the whole suite**

Run: `php bin/phpunit tests/Service/Backup/ && php bin/phpunit`

- [ ] **Step 6: Static gates**

Run: `composer check && composer md`

- [ ] **Step 7: Commit**

```bash
git add src/Service/Backup src/Repository tests/Service/Backup
git commit -m "feat(#412): stream an account's backup as NDJSON lines"
```

---

### Task 4: The download endpoint

**Files:**
- Create: `backend/src/Service/Backup/BackupDownloadResponseFactory.php`
- Create: `backend/src/Controller/Api/AccountBackupController.php` (export action only)
- Test: `backend/tests/Controller/Api/AccountBackupControllerTest.php`

**Interfaces:**
- Produces: `BackupDownloadResponseFactory::stream(\Generator $lines): StreamedResponse` — gzips incrementally, `Content-Type: application/gzip`, `Content-Disposition: attachment; filename="account-backup-<Y-m-d>.json.gz"` (date from `ClockInterface`). **No `Content-Encoding` header** — the browser must save the gzip bytes untouched, not transparently inflate them.
- Produces: routes `api_account_backup` = `GET /api/account/backup`.
- Consumes: `AccountBackupExporter::lines(User, ?string)`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountBackupControllerTest extends WebTestCase
{
    /** @return array{0: array<string, string>, 1: User} */
    private function auth(string $email): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        $user = (new UserFactory($em, $hasher))->create($email);
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [['HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user)], $user];
    }

    public function testBackupRequiresAuth(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/account/backup');
        self::assertResponseStatusCodeSame(401);
    }

    public function testBackupStreamsAGzipNdjsonAttachment(): void
    {
        $client = self::createClient();
        [$headers, $user] = $this->auth('backup-download@example.com');
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $feed = new Feed('https://dl.example/feed.xml');
        $em->persist($feed);
        $em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $em->flush();

        ob_start();
        $client->request('GET', '/api/account/backup', server: $headers);
        $streamed = (string) ob_get_clean();

        self::assertResponseIsSuccessful();
        self::assertSame('application/gzip', $client->getResponse()->headers->get('Content-Type'));
        self::assertNull($client->getResponse()->headers->get('Content-Encoding'));
        self::assertStringContainsString(
            'attachment; filename="account-backup-',
            (string) $client->getResponse()->headers->get('Content-Disposition'),
        );
        $ndjson = gzdecode($streamed);
        self::assertIsString($ndjson);
        $lines = explode("\n", trim($ndjson));
        $first = json_decode($lines[0], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($first);
        self::assertSame('header', $first['kind']);
        $last = json_decode($lines[array_key_last($lines)], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($last);
        self::assertSame('footer', $last['kind']);
        self::assertSame(1, $last['counts']['subscription']);
    }
}
```

(`ob_start()` around the request: `StreamedResponse::sendContent()` echoes; the kernel browser does not buffer it into `getContent()`.)

- [ ] **Step 2: Run it, expect 404**

Run: `php bin/phpunit tests/Controller/Api/AccountBackupControllerTest.php`

- [ ] **Step 3: Implement**

`BackupDownloadResponseFactory`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Backup;

use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Wraps the exporter's line stream in a gzip download. Compression is
 * incremental (deflate_add per line): the uncompressed document is never
 * materialised, which is what keeps the export at the appendix's 7 MiB peak
 * instead of the corpus size. Content-Encoding stays unset on purpose — the
 * browser must save the .json.gz bytes as they are, not inflate them in
 * flight and hand the user a misnamed plain-text file.
 */
final readonly class BackupDownloadResponseFactory
{
    public function __construct(private ClockInterface $clock)
    {
    }

    /** @param \Generator<int, string> $lines */
    public function stream(\Generator $lines): StreamedResponse
    {
        $filename = sprintf('account-backup-%s.json.gz', $this->clock->now()->format('Y-m-d'));

        return new StreamedResponse(
            static function () use ($lines): void {
                $gzip = deflate_init(\ZLIB_ENCODING_GZIP);
                if (false === $gzip) {
                    throw new \RuntimeException('Cannot initialise gzip compression.');
                }
                foreach ($lines as $line) {
                    echo deflate_add($gzip, $line . "\n", \ZLIB_NO_FLUSH);
                }
                echo deflate_add($gzip, '', \ZLIB_FINISH);
            },
            headers: [
                'Content-Type' => 'application/gzip',
                'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            ],
        );
    }
}
```

`AccountBackupController` (restore actions arrive in Task 8; keep the class ready for them):

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\User;
use App\Service\Backup\AccountBackupExporter;
use App\Service\Backup\BackupDownloadResponseFactory;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/account')]
final readonly class AccountBackupController
{
    public function __construct(
        private AccountBackupExporter $exporter,
        private BackupDownloadResponseFactory $downloads,
    ) {
    }

    #[Route('/backup', name: 'api_account_backup', methods: ['GET'])]
    public function backup(#[CurrentUser] User $user, Request $request): StreamedResponse
    {
        return $this->downloads->stream($this->exporter->lines($user, $request->getSchemeAndHttpHost()));
    }
}
```

- [ ] **Step 4: Run the test, expect green**

Run: `php bin/phpunit tests/Controller/Api/AccountBackupControllerTest.php`

- [ ] **Step 5: Static gates, commit**

Run: `composer check && composer md`

```bash
git add src/Service/Backup src/Controller tests/Controller
git commit -m "feat(#412): account backup download endpoint"
```

---

### Task 5: AccountReset

The wipe, as its own named service beside `AccountDeleter`. Bulk DQL for the big tables; the DB's `ON DELETE CASCADE` clears `subscription_tag` (both FKs carry it). Unlike `AccountDeleter`, it does **not** reclaim orphaned feeds: the restore re-subscribes the same feeds moments later, and reclaiming in between would delete thousands of entries only to re-insert them from the file.

**Files:**
- Create: `backend/src/Service/Account/AccountReset.php`
- Test: `backend/tests/Service/Account/AccountResetTest.php`

**Interfaces:**
- Produces: `AccountReset::reset(User $user): void` — deletes the user's recommendation items/logs/runs, entry states, subscriptions (+ join rows via cascade), tags, and recommendation-settings row; resets `Preferences` to defaults; flushes and `clear()`s the entity manager. Does NOT touch: the `User` row itself (email, password, roles, status, locale, limits), AI settings, provider usage, identities, action tokens, feeds, entries.
- Consumes: `EntityManagerInterface`, entity class constants only.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Account;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Preferences;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationSettings;
use App\Entity\Subscription;
use App\Entity\SubscriptionTag;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Account\AccountReset;
use App\Service\Recommendation\RecommendationSettingsValues;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountResetTest extends DbTestCase
{
    private function makeUser(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function reset(): AccountReset
    {
        $service = self::getContainer()->get(AccountReset::class);
        self::assertInstanceOf(AccountReset::class, $service);

        return $service;
    }

    /** Seeds one full account and returns [user, feed, entry]. */
    private function seedAccount(string $email): array
    {
        $user = $this->makeUser($email);
        $feed = new Feed('https://reset.example/' . $email);
        $this->em->persist($feed);
        $tag = new Tag($user, 'Mine');
        $this->em->persist($tag);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $subscription->addTag($tag);
        $this->em->persist($subscription);
        $entry = new Entry(
            $feed,
            'g-' . $email,
            null,
            'A',
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
        );
        $this->em->persist($entry);
        $this->em->persist(new EntryState($user, $entry));
        $user->getPreferences()->setScrapeFallbackEnabled(true);
        $settings = new RecommendationSettings($user);
        $settings->update(new RecommendationSettingsValues(
            guidancePrompt: 'be nice',
            favoritesCap: 1,
            keptCap: 1,
            viewedCap: 1,
            candidatePoolSize: 10,
            lookbackDays: 7,
            picksLimit: 3,
            contextWindow: null,
            batchCount: null,
            debugEnabled: false,
        ));
        $this->em->persist($settings);
        $this->em->persist(new RecommendationRun($user, new \DateTimeImmutable('2026-08-05T00:00:00Z')));
        $this->em->flush();

        return [$user, $feed, $entry];
    }

    public function testWipesEverythingTheUserOwns(): void
    {
        [$user] = $this->seedAccount('reset-wipes@example.com');
        $userId = (int) $user->getId();

        $this->reset()->reset($user);

        // Bulk DQL bypasses the identity map — clear before every "is gone"
        // assertion, or find() serves the stale in-memory row (#412 spec).
        $this->em->clear();
        self::assertSame([], $this->em->getRepository(Subscription::class)->findBy(['user' => $userId]));
        self::assertSame([], $this->em->getRepository(Tag::class)->findBy(['user' => $userId]));
        self::assertSame([], $this->em->getRepository(EntryState::class)->findBy(['user' => $userId]));
        self::assertSame([], $this->em->getRepository(RecommendationRun::class)->findBy(['user' => $userId]));
        self::assertSame([], $this->em->getRepository(RecommendationSettings::class)->findBy(['user' => $userId]));
        $subscriptionTags = $this->em->getRepository(SubscriptionTag::class)->findAll();
        self::assertSame([], $subscriptionTags);
        $preferences = $this->em->getRepository(Preferences::class)->findOneBy(['user' => $userId]);
        self::assertInstanceOf(Preferences::class, $preferences);
        self::assertFalse($preferences->isScrapeFallbackEnabled());
    }

    public function testLeavesTheAccountRowAndSharedRowsAlone(): void
    {
        [$user, $feed, $entry] = $this->seedAccount('reset-keeps@example.com');
        $userId = (int) $user->getId();
        $feedId = (int) $feed->getId();
        $entryId = (int) $entry->getId();

        $this->reset()->reset($user);

        $this->em->clear();
        $kept = $this->em->find(User::class, $userId);
        self::assertInstanceOf(User::class, $kept);
        self::assertSame('reset-keeps@example.com', $kept->getEmail());
        self::assertInstanceOf(Feed::class, $this->em->find(Feed::class, $feedId));
        self::assertInstanceOf(Entry::class, $this->em->find(Entry::class, $entryId));
    }

    public function testDoesNotTouchAnotherUsersRows(): void
    {
        [$victim] = $this->seedAccount('reset-target@example.com');
        [$bystander] = $this->seedAccount('reset-bystander@example.com');
        $bystanderId = (int) $bystander->getId();

        $this->reset()->reset($victim);

        $this->em->clear();
        self::assertCount(1, $this->em->getRepository(Subscription::class)->findBy(['user' => $bystanderId]));
        self::assertCount(1, $this->em->getRepository(Tag::class)->findBy(['user' => $bystanderId]));
        self::assertCount(1, $this->em->getRepository(EntryState::class)->findBy(['user' => $bystanderId]));
    }

    public function testASecondResetIsANoOp(): void
    {
        [$user] = $this->seedAccount('reset-idempotent@example.com');

        $this->reset()->reset($user);
        $freshUser = $this->em->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $freshUser);
        $this->reset()->reset($freshUser);

        $this->em->clear();
        self::assertInstanceOf(User::class, $this->em->find(User::class, $user->getId()));
    }
}
```

`RecommendationRun`'s constructor signature: check `src/Entity/RecommendationRun.php` when writing the test — if it takes more than `(User, DateTimeImmutable)`, adjust the seeding line to the real signature.

- [ ] **Step 2: Run it, expect failure** — `php bin/phpunit tests/Service/Account/AccountResetTest.php`

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Entity\EntryState;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\RecommendationSettings;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Empties an account without deleting it: everything the user owns goes,
 * everything that identifies or entitles the account stays (email, password,
 * roles, status, limits, AI connections, OAuth identities). The restore's
 * wipe half, deliberately its own named service — it is the most destructive
 * code in the repository, and a future admin "reset user" action calls the
 * same method rather than growing a second wipe.
 *
 * Bulk DQL, not remove(): entry_state alone can hold tens of thousands of
 * rows. The DELETEs bypass the identity map, so this method ends with a
 * clear() — and so must every test that asserts a row is gone.
 *
 * No orphaned-feed reclaim here, unlike AccountDeleter: the restore
 * re-subscribes the same feeds moments later, and reclaiming in between
 * would delete entries only to re-insert them from the file. A caller that
 * wipes WITHOUT reloading owns that decision itself.
 */
final readonly class AccountReset
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function reset(User $user): void
    {
        $this->deleteRecommendationData($user);
        $this->deleteOwnedRows($user);
        $user->getPreferences()->setScrapeFallbackEnabled(false);
        $this->em->flush();
        $this->em->clear();
    }

    private function deleteRecommendationData(User $user): void
    {
        // Children first; their run FK would otherwise block the run delete.
        foreach ([RecommendationItem::class, RecommendationRunLog::class] as $childClass) {
            $this->em->createQuery(sprintf(
                'DELETE FROM %s c WHERE IDENTITY(c.run) IN (SELECT r.id FROM %s r WHERE r.user = :user)',
                $childClass,
                RecommendationRun::class,
            ))->setParameter('user', $user)->execute();
        }
        $this->deleteByUser(RecommendationRun::class, $user);
        $this->deleteByUser(RecommendationSettings::class, $user);
    }

    private function deleteOwnedRows(User $user): void
    {
        $this->deleteByUser(EntryState::class, $user);
        // subscription_tag rows die with their subscription (and tag) via the
        // DB-level ON DELETE CASCADE both join columns declare.
        $this->deleteByUser(Subscription::class, $user);
        $this->deleteByUser(Tag::class, $user);
    }

    /** @param class-string $entityClass */
    private function deleteByUser(string $entityClass, User $user): void
    {
        $this->em->createQuery(sprintf('DELETE FROM %s x WHERE x.user = :user', $entityClass))
            ->setParameter('user', $user)
            ->execute();
    }
}
```

Check the actual FK field name on `RecommendationItem`/`RecommendationRunLog` (`run` vs another name) against the entities before running; the grep in this repo shows `RecommendationItem::$run` mapping `recommendation_run_id` and `RecommendationRunLog` mapping `run_id` — use the property names as mapped in each class.

- [ ] **Step 4: Run to green** — `php bin/phpunit tests/Service/Account/`

- [ ] **Step 5: Static gates, commit**

```bash
git add src/Service/Account tests/Service/Account
git commit -m "feat(#412): AccountReset wipes what a user owns and nothing else"
```

---

### Task 6: Inventory, fit check, previewer

Pass 1 of the restore: read the whole file through `BackupReader`, count, refuse what does not fit — all before anything is deleted.

**Files:**
- Create: `backend/src/Service/Backup/BackupInventory.php`
- Create: `backend/src/Service/Backup/BackupInspector.php`
- Create: `backend/src/Service/Backup/BackupFitCheck.php`
- Create: `backend/src/Service/Backup/Exception/BackupDoesNotFitException.php`
- Create: `backend/src/Service/Backup/RestorePreview.php`
- Create: `backend/src/Service/Backup/RestorePreviewer.php`
- Modify: `backend/src/Repository/TagRepository.php` (add `countForUser`)
- Modify: `backend/src/Repository/EntryStateRepository.php` (add `countForUser`)
- Modify: `backend/src/Repository/RecommendationRunRepository.php` (add `countForUser`)
- Test: `backend/tests/Service/Backup/BackupInspectorTest.php`, `backend/tests/Service/Backup/RestorePreviewerTest.php`

**Interfaces:**
- Produces: `BackupInventory` — `final readonly`, `public BackupHeader $header`, `public AccountLine $account`, `public int $tags`, `public int $feeds`, `public int $subscriptions`, `public int $entries`, `public int $entryStates`.
- Produces: `BackupInspector::inspect(string $gzipBytes): BackupInventory` — full pass over `BackupReader::read()`; counting only, nothing retained. Any reader exception propagates.
- Produces: `BackupFitCheck::assertFits(BackupInventory $inventory, User $user): void` — throws `BackupDoesNotFitException` when `subscriptions > SubscriptionLimitResolver::resolve($user)` or `entries > 500_000`.
- Produces: `BackupDoesNotFitException extends ApiException` — `('backup_does_not_fit', 409, 'The backup does not fit this account', $detail)`; detail names the count and the limit.
- Produces: `RestorePreview` — `final readonly`: `public BackupHeader $header`, `public BackupInventory $toLoad`, and current-account counts `public int $currentSubscriptions`, `public int $currentTags`, `public int $currentEntryStates`, `public int $currentRecommendationRuns`.
- Produces: `RestorePreviewer::preview(User $user, string $gzipBytes): RestorePreview` — inspect + fit check + count the target account. A preview of a non-fitting file **throws** the same `BackupDoesNotFitException` (the UI shows the refusal instead of a report).
- Produces: the three `countForUser(int $userId): int` repository methods — plain `COUNT()` query builders (`select('COUNT(x)')`, `where user = :userId`, `getSingleScalarResult`, cast int). `EntryStateRepository`'s counts via `COUNT(s.entry)` (no scalar id on that table).
- Consumes: `BackupReader`, `SubscriptionLimitResolver::resolve(User): int`, `SubscriptionRepository::countForUser`.

- [ ] **Step 1: Write the failing tests**

`BackupInspectorTest` (plain `TestCase`, reuse the `gzipOf`/`header`/`account`/`footer` helpers from `BackupReaderTest` — copy them; they are five lines each and the two tests assert different things):

```php
public function testCountsEveryKind(): void
{
    $gzip = self::gzipOf([
        self::header(), self::account(),
        ['kind' => 'tag', 'name' => 'A', 'color' => null, 'icon' => null, 'position' => 0],
        ['kind' => 'tag', 'name' => 'B', 'color' => null, 'icon' => null, 'position' => 1],
        ['kind' => 'feed', 'url' => 'https://f.example/feed.xml', 'siteUrl' => null, 'title' => null,
            'description' => null, 'faviconUrl' => null, 'sourceFormat' => 'xml'],
        ['kind' => 'subscription', 'feedUrl' => 'https://f.example/feed.xml', 'customTitle' => null,
            'position' => 0, 'markedReadUntil' => null, 'createdAt' => '2026-07-01T00:00:00+00:00',
            'tags' => []],
        self::footer(['tag' => 2, 'feed' => 1, 'subscription' => 1]),
    ]);

    $inventory = new BackupInspector()->inspect($gzip);

    self::assertSame(2, $inventory->tags);
    self::assertSame(1, $inventory->feeds);
    self::assertSame(1, $inventory->subscriptions);
    self::assertSame(0, $inventory->entries);
    self::assertSame('source@example.com', $inventory->header->sourceEmail);
    self::assertSame('de', $inventory->account->locale);
}

public function testABrokenFileRefusesInsteadOfCounting(): void
{
    $this->expectException(InvalidBackupException::class);

    new BackupInspector()->inspect((string) gzencode("{\"kind\":\"header\"}\n"));
}
```

`RestorePreviewerTest` (`DbTestCase`): build a user with `maxSubscriptions: 1` via `UserFactory`, a valid two-subscription gzip → expect `BackupDoesNotFitException` whose message names the limit; a fitting gzip against a user who currently owns 1 tag + 1 subscription → assert the preview echoes `toLoad` counts and `currentSubscriptions === 1`, `currentTags === 1`. Also assert `entries > 500_000` refusal by unit-testing `BackupFitCheck` directly with a hand-built `BackupInventory` (do NOT generate 500k lines):

```php
public function testRefusesMoreEntriesThanTheSanityCeiling(): void
{
    $inventory = new BackupInventory(
        header: self::someHeader(),
        account: self::someAccount(),
        tags: 0,
        feeds: 1,
        subscriptions: 1,
        entries: 500_001,
        entryStates: 0,
    );

    $this->expectException(BackupDoesNotFitException::class);

    $this->fitCheck()->assertFits($inventory, $this->makeUser('fit-ceiling@example.com'));
}
```

- [ ] **Step 2: Run, expect failures**

- [ ] **Step 3: Implement**

`BackupInspector` is a `foreach` over `read()` with an `instanceof` match incrementing counters, capturing header/account, and constructing the `BackupInventory`. `BackupFitCheck`:

```php
final readonly class BackupFitCheck
{
    /**
     * Not a tuned limit: the 240 s budget would allow ~2 million entries.
     * A file above this is corrupt or hostile, not a large account.
     */
    private const int MAX_ENTRIES = 500_000;

    public function __construct(private SubscriptionLimitResolver $subscriptionLimits)
    {
    }

    public function assertFits(BackupInventory $inventory, User $user): void
    {
        $limit = $this->subscriptionLimits->resolve($user);
        if ($inventory->subscriptions > $limit) {
            throw new BackupDoesNotFitException(sprintf(
                'The backup holds %d subscriptions; this account allows %d.',
                $inventory->subscriptions,
                $limit,
            ));
        }
        if ($inventory->entries > self::MAX_ENTRIES) {
            throw new BackupDoesNotFitException(sprintf(
                'The backup holds %d entries; the ceiling is %d.',
                $inventory->entries,
                self::MAX_ENTRIES,
            ));
        }
    }
}
```

- [ ] **Step 4: Run to green, whole Backup suite** — `php bin/phpunit tests/Service/Backup/`

- [ ] **Step 5: Static gates, commit**

```bash
git add src/Service/Backup src/Repository tests/Service/Backup
git commit -m "feat(#412): validate, count and fit-check a backup before any deletion"
```

---

### Task 7: EntryBatchInserter

The one raw-SQL piece: multi-row `INSERT` into `entry`, 500 rows per statement — measured 0.085 ms/row against 1.2 ms/row for ORM row-by-row.

**Files:**
- Create: `backend/src/Service/Backup/EntryBatchInserter.php`
- Test: `backend/tests/Service/Backup/EntryBatchInserterTest.php`

**Interfaces:**
- Produces: `EntryBatchInserter::insert(int $feedId, array $lines): void` with `@param list<EntryLine> $lines` — inserts every line for that feed; caller guarantees dedup.
- Consumes: `Doctrine\DBAL\Connection` (injected), `EntryLine`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Service\Backup\Dto\EntryLine;
use App\Service\Backup\EntryBatchInserter;
use App\Tests\DbTestCase;

final class EntryBatchInserterTest extends DbTestCase
{
    private static function line(string $guid, string $title): EntryLine
    {
        return new EntryLine(
            feedUrl: 'https://batch.example/feed.xml',
            guid: $guid,
            guidHash: hash('sha256', $guid),
            url: 'https://batch.example/' . $guid,
            title: $title,
            author: 'Ann Author',
            summary: 'sum',
            contentHtml: '<p>body</p>',
            imageUrl: null,
            imageWidth: 640,
            imageHeight: null,
            publishedAt: new \DateTimeImmutable('2026-08-01T10:00:00+00:00'),
            createdAt: new \DateTimeImmutable('2026-08-02T00:00:00+00:00'),
            effectiveDate: new \DateTimeImmutable('2026-08-01T10:00:00+00:00'),
        );
    }

    public function testInsertsMoreRowsThanOneStatementHolds(): void
    {
        $feed = new Feed('https://batch.example/feed.xml');
        $this->em->persist($feed);
        $this->em->flush();
        $feedId = (int) $feed->getId();
        $lines = [];
        for ($i = 0; $i < 501; ++$i) {
            $lines[] = self::line('guid-' . $i, 'Entry ' . $i);
        }

        $inserter = self::getContainer()->get(EntryBatchInserter::class);
        self::assertInstanceOf(EntryBatchInserter::class, $inserter);
        $inserter->insert($feedId, $lines);

        $this->em->clear();
        $rows = $this->em->getRepository(Entry::class)->findBy(['feed' => $feedId]);
        self::assertCount(501, $rows);
    }

    public function testARowRoundTripsFieldForFieldThroughTheOrm(): void
    {
        $feed = new Feed('https://batch.example/feed.xml');
        $this->em->persist($feed);
        $this->em->flush();

        $inserter = self::getContainer()->get(EntryBatchInserter::class);
        self::assertInstanceOf(EntryBatchInserter::class, $inserter);
        $inserter->insert((int) $feed->getId(), [self::line('one-guid', 'One')]);

        $this->em->clear();
        $entry = $this->em->getRepository(Entry::class)->findOneBy(['guidHash' => hash('sha256', 'one-guid')]);
        self::assertInstanceOf(Entry::class, $entry);
        self::assertSame('one-guid', $entry->getGuid());
        self::assertSame('One', $entry->getTitle());
        self::assertSame('Ann Author', $entry->getAuthor());
        self::assertSame('sum', $entry->getSummary());
        self::assertSame('<p>body</p>', $entry->getContentHtml());
        self::assertNull($entry->getImageUrl());
        self::assertSame(640, $entry->getImageWidth());
        self::assertSame('2026-08-01 10:00:00', $entry->getPublishedAt()?->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-02 00:00:00', $entry->getCreatedAt()->format('Y-m-d H:i:s'));
        self::assertSame('2026-08-01 10:00:00', $entry->getEffectiveDate()->format('Y-m-d H:i:s'));
    }

    public function testAnEmptyListDoesNothing(): void
    {
        $inserter = self::getContainer()->get(EntryBatchInserter::class);
        self::assertInstanceOf(EntryBatchInserter::class, $inserter);

        $inserter->insert(999, []);

        $this->addToAssertionCount(1);
    }
}
```

- [ ] **Step 2: Run, expect failure**

- [ ] **Step 3: Implement**

```php
<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Service\Backup\Dto\EntryLine;
use Doctrine\DBAL\Connection;

/**
 * Multi-row INSERTs into `entry`, 500 rows per statement — the measured
 * 0.085 ms/row path (row-by-row through the ORM is 14× slower at restore
 * scale, see the spec appendix). Raw SQL by necessity, which is exactly why
 * guid_hash travels IN the backup file: no Entry constructor runs here, so
 * nothing could recompute it.
 *
 * The column list is spelled out once; values bind positionally per row.
 * Dates are formatted as the naive-UTC wall-clock strings Doctrine's
 * datetime_immutable type stores — every EntryLine date is already UTC
 * (LineField normalises on parse).
 */
final readonly class EntryBatchInserter
{
    private const int ROWS_PER_STATEMENT = 500;

    private const array COLUMNS = [
        'feed_id', 'guid', 'guid_hash', 'url', 'title', 'author', 'summary',
        'content_html', 'image_url', 'image_width', 'image_height',
        'published_at', 'created_at', 'effective_date',
    ];

    public function __construct(private Connection $connection)
    {
    }

    /** @param list<EntryLine> $lines */
    public function insert(int $feedId, array $lines): void
    {
        foreach (array_chunk($lines, self::ROWS_PER_STATEMENT) as $chunk) {
            $this->insertChunk($feedId, $chunk);
        }
    }

    /** @param non-empty-list<EntryLine> $chunk */
    private function insertChunk(int $feedId, array $chunk): void
    {
        $rowPlaceholders = '(' . implode(', ', array_fill(0, \count(self::COLUMNS), '?')) . ')';
        $sql = sprintf(
            'INSERT INTO entry (%s) VALUES %s',
            implode(', ', self::COLUMNS),
            implode(', ', array_fill(0, \count($chunk), $rowPlaceholders)),
        );

        $values = [];
        foreach ($chunk as $line) {
            array_push($values, ...$this->row($feedId, $line));
        }
        $this->connection->executeStatement($sql, $values);
    }

    /** @return list<int|string|null> */
    private function row(int $feedId, EntryLine $line): array
    {
        return [
            $feedId, $line->guid, $line->guidHash, $line->url, $line->title,
            $line->author, $line->summary, $line->contentHtml, $line->imageUrl,
            $line->imageWidth, $line->imageHeight,
            self::storageDate($line->publishedAt),
            self::storageDate($line->createdAt),
            self::storageDate($line->effectiveDate),
        ];
    }

    private static function storageDate(?\DateTimeImmutable $date): ?string
    {
        return $date?->format('Y-m-d H:i:s');
    }
}
```

- [ ] **Step 4: Run to green** — also run the MySQL leg for this one (`docker compose exec php vendor/bin/phpunit tests/Service/Backup/EntryBatchInserterTest.php`); a multi-row VALUES statement is the kind of SQL that behaves subtly differently across drivers.

- [ ] **Step 5: Static gates, commit**

```bash
git add src/Service/Backup tests/Service/Backup
git commit -m "feat(#412): batched multi-row entry inserts for the restore"
```

---

### Task 8: RestoreLoader and AccountRestorer

Pass 2 plus the orchestration. This is the largest task; its integration test is the round trip the spec demands.

**Files:**
- Create: `backend/src/Service/Backup/RestoreLoader.php`
- Create: `backend/src/Service/Backup/RestoreResult.php`
- Create: `backend/src/Service/Backup/AccountRestorer.php`
- Modify: `backend/src/Repository/EntryRepository.php` (add `guidHashToIdMapForFeed`)
- Modify: `backend/src/Repository/SubscriptionRepository.php` (add `countOtherSubscribers`)
- Test: `backend/tests/Service/Backup/AccountRestorerTest.php`

**Interfaces:**
- Produces: `RestoreResult` — `final readonly`: `public int $tags`, `public int $feeds`, `public int $subscriptions`, `public int $entries`, `public int $entryStates` (loaded counts; `feeds` counts feed rows **created**, not referenced).
- Produces: `RestoreLoader::load(User $user, string $gzipBytes): RestoreResult` — assumes a just-reset account; second pass over `BackupReader::read()`.
- Produces: `AccountRestorer::restore(User $user, string $gzipBytes, ?string $confirmation): RestoreResult` — refuses without `'REPLACE'`, then inspect → fit check → reset → load.
- Produces: `EntryRepository::guidHashToIdMapForFeed(int $feedId): array<string, int>` — one scalar query (`select('e.guidHash', 'e.id')`), hash ⇒ id.
- Produces: `SubscriptionRepository::countOtherSubscribers(int $feedId, int $excludedUserId): int`.
- Consumes: `BackupReader`, `BackupInspector`, `BackupFitCheck`, `AccountReset`, `EntryBatchInserter`, `EntryIndexer` (+ `EntryRepository::entriesAfterId` for the post-load index pass), `RecommendationSettings`/`Preferences` entities, `SourceFormat` enum semantics from `SubscriptionCreator` (only set on rows this restore creates).

**Loader algorithm** (each phase its own private method; the per-run mutable state lives in a private `final class RestoreLoadState` value holder in the same file? **No** — house style forbids hidden grab-bags; instead the loader passes explicit locals between phases and keeps the stream `foreach` in `load()` dispatching on DTO class):

1. `AccountLine` → `$user->setLocale()`, `$user->getPreferences()->setScrapeFallbackEnabled()`, and when `recommendationSettings !== null`: `new RecommendationSettings($user)` + `update($line->recommendationSettings)` + persist.
2. `TagLine` → `new Tag($user, $name)`, set color/icon/position, persist; remember in `array<string, Tag> $tagsByName`.
3. `FeedLine` → `FeedRepository::findOneBy(['url' => …])`; null → create `new Feed($url)` and set siteUrl/title/description/faviconUrl/sourceFormat (the only place sourceFormat is written — a known row is never modified, which is stricter than `SubscriptionCreator`'s one-way heal and intentionally so); known → change nothing. Either way remember `array<string, Feed> $feedsByUrl`. Flush once after the last feed line (detected by the first subscription line) so ids exist.
4. `SubscriptionLine` → `new Subscription($user, $feedsByUrl[$feedUrl], $createdAt)`, set customTitle/position/markedReadUntil, `addTag($tagsByName[$ref->name], $ref->position)` per ref, persist. Missing feed/tag reference → `InvalidBackupException` (the file is inconsistent; nothing partial has been flushed to `entry` yet, and a re-run recovers). Flush once after the last subscription line; then snapshot `array<string, int> $feedIdsByUrl` and `array<string, bool> $insertNewEntries` (`countOtherSubscribers($feedId, $userId) === 0`), plus `array<string, array<string, int>> $existingHashes` for feeds that already existed (`guidHashToIdMapForFeed`). Then `$this->em->clear()` and re-fetch the `User` reference — entries and states never touch those entities again.
5. `EntryLine` → buffer per feed url; on feed-url change or buffer of 500, flush the buffer: lines whose hash is in `$existingHashes[$url]` are dropped (entry known → untouched); if `$insertNewEntries[$url]` is false, everything new is dropped (stranger's unread list rule); the rest goes to `EntryBatchInserter::insert()`. Count what was actually inserted.
6. `EntryStateLine` → resolve `entryId` via a per-feed hash⇒id map: `$existingHashes[$url]` merged with a fresh `guidHashToIdMapForFeed()` fetched lazily the first time a state for that feed arrives after its inserts (cache it). Unknown hash → skip silently (that entry was withheld by rule 5 on a shared feed). Build `new EntryState($userRef, $entryRef)` with `$this->em->getReference()` for both, apply setters + `markViewed($viewedAt)` when `isViewed` (the one-way invariant stays intact — no new setter). Persist; flush + clear every 500 states, re-acquiring `$userRef` after each clear.
7. After the stream: the post-load index pass — walk `EntryRepository::entriesAfterId($lastId, 500)`, `EntryIndexer::index()` every batch's entries whose id is in the created-id set (computed per feed as `guidHashToIdMapForFeed()` minus the pre-existing hashes), `clear()` per batch. Skip the walk entirely when nothing was created.

Wait — simpler for step 7 and correct: collect created ids per feed right after that feed's inserts (`guidHashToIdMapForFeed()` diffed against `$existingHashes`), already needed for the states map. Then the index pass hydrates only `WHERE e.id IN (chunk)` batches. Add nothing new: `entriesAfterId` walks the whole table, so filter its batches against the created-id set — with a restore being most of the table, the walk is the same work. Either shape is acceptable; pick the `entriesAfterId` filter variant because it reuses an existing repository method unchanged.

`AccountRestorer`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Backup;

use App\Entity\User;
use App\Exception\ValidationException;
use App\Service\Account\AccountReset;

/**
 * The whole restore, in the only safe order: validate and count the real
 * bytes, refuse anything that does not fit, and only then wipe and load.
 * The two passes read the same in-memory gzip string, so the file that
 * passed the fit check is byte-for-byte the file that loads.
 *
 * Deliberately NOT transactional (spec §8): a crash mid-load leaves a wiped,
 * partly loaded account, and the remedy is re-running the same file — the
 * wipe is idempotent.
 */
final readonly class AccountRestorer
{
    private const string CONFIRMATION = 'REPLACE';

    public function __construct(
        private BackupInspector $inspector,
        private BackupFitCheck $fitCheck,
        private AccountReset $accountReset,
        private RestoreLoader $loader,
    ) {
    }

    public function restore(User $user, string $gzipBytes, ?string $confirmation): RestoreResult
    {
        if (self::CONFIRMATION !== $confirmation) {
            throw new ValidationException(['confirm' => ['Type REPLACE to confirm the restore.']]);
        }

        $inventory = $this->inspector->inspect($gzipBytes);
        $this->fitCheck->assertFits($inventory, $user);
        $this->accountReset->reset($user);

        return $this->loader->load($this->refreshed($user), $gzipBytes);
    }
}
```

(`refreshed()`: `AccountReset` ends with `clear()`, so re-`find()` the user by id via the `EntityManagerInterface` — a detached `User` cannot anchor new entities.)

- [ ] **Step 1: Write the failing integration test**

`backend/tests/Service/Backup/AccountRestorerTest.php` (`DbTestCase`). Helper: seed a rich source account (2 feeds — one with custom title, watermark, 2 tags with colors/positions and per-tag subscription positions; 3 entries with bodies/images/dates; 2 entry states — one favorite+read, one viewed; preferences on; recommendation settings row; locale `de`), then produce the file with the real exporter:

```php
private function backupOf(User $user): string
{
    $exporter = self::getContainer()->get(AccountBackupExporter::class);
    self::assertInstanceOf(AccountBackupExporter::class, $exporter);
    $ndjson = '';
    foreach ($exporter->lines($user, 'https://source.example') as $line) {
        $ndjson .= $line . "\n";
    }

    return (string) gzencode($ndjson);
}
```

Tests:

1. `testRoundTripReproducesTheAccountFieldForField` — export source user A, restore onto **fresh user B on the same database but with no overlap** is not the migration shape (feeds are shared rows and already exist). Use the honest same-instance variant: restore onto user A themself. Assert after `clear()`: locale, scrapeFallbackEnabled, recommendation settings values equal; tags (name, color, icon, position); subscriptions (feed url, customTitle, position, markedReadUntil, createdAt) — compare as sorted value arrays; per-tag join positions via `getSubscriptionTags()`; entry states field-for-field including `viewedAt`; entry count unchanged (all entries pre-existed as shared rows — `RestoreResult->entries === 0`, `->feeds === 0`).
2. `testRestoreOntoAnEmptyInstanceCreatesFeedsAndEntries` — export from A, then **delete A's feeds/entries wholesale** (`AccountReset` + raw `DELETE FROM entry` + `DELETE FROM feed` via the connection — this simulates the fresh-instance target inside one database), then restore. Assert feeds recreated with title/siteUrl/sourceFormat but virgin `FetchSchedule` (`getLastFetchedAt() === null`, `getEtag() === null`); entries recreated with original `createdAt`/`effectiveDate`/`contentHtml`; `guidHash` column matches `hash('sha256', guid)` for every row; states reattached; `RestoreResult` counts match the seed.
3. `testEntriesAreNotCreatedIntoAFeedAnotherUserReads` — seed A subscribed to feed F, export; wipe **entries** of F only (raw `DELETE FROM entry WHERE feed_id = …`); create user C subscribed to F; restore A. Assert F still has **zero** entries (the stranger's-unread-list rule), A's subscription to F exists, and `RestoreResult->entries === 0`.
4. `testAFeedRowAnotherUserReadsIsNotModified` — as 3, but before restoring change F's title to `'Theirs'`; the backup carries `'Original'`. After restore F's title is still `'Theirs'`.
5. `testRefusalHappensBeforeAnyDeletion` — user with `maxSubscriptions: 1`, backup with 2 subscriptions; expect `BackupDoesNotFitException` AND the user's existing tag/subscription/state rows still present afterwards (no `clear()` deception: clear first, then count).
6. `testWithoutTheConfirmationNothingHappens` — `restore($user, $gzip, null)` throws `ValidationException`; account untouched.
7. `testARestoreCanBeRerunAfterItself` — restore the same file twice in a row; second run succeeds and final state equals the single-run state (wipe idempotence).
8. `testRestoredEntriesReachTheSearchIndex` — spy on the indexer seam: fetch the `SearchIndexWriter` test double the search tests use (look at `tests/Service/Search/` for the established pattern — there is a fake/in-memory writer for exactly this; consume it the same way) and assert the created entry ids were upserted after scenario 2's restore. If the suite's established pattern is "unconfigured engine no-ops silently", assert instead via that fake at the `EntryIndexer` level with a service substitution, mirroring how `tests/Service/Search/EntryIndexerTest.php` builds it.

- [ ] **Step 2: Run, expect failures** — `php bin/phpunit tests/Service/Backup/AccountRestorerTest.php`

- [ ] **Step 3: Implement** `RestoreLoader`, `RestoreResult`, `AccountRestorer`, and the two repository methods per the interfaces and algorithm above. Method budget guidance for PHPMD: `load()` dispatches; each kind gets `loadAccount()`, `loadTag()`, `loadFeed()`, `loadSubscription()`, `bufferEntry()`+`flushEntryBuffer()`, `loadState()`, `indexCreatedEntries()`; the cross-phase context (maps + counters) is one private property set initialised in `load()` — instance state on a **non**-shared basis is wrong for an autowired service, so `RestoreLoader` builds a private inner worker: `final class RestoreLoadPass` (same namespace, own file is fine) holding the maps as fields, constructed per call with the collaborators it needs. That is the spec's "per-pass collaborator that holds it as a field" — phptramp's own recommended fix.

- [ ] **Step 4: Run to green, then both suite legs**

Run: `php bin/phpunit` and `docker compose exec php vendor/bin/phpunit` (from the repo root for the second). Known flake: MySQL rate-limiter order dependence — a limiter failure alone is not this change's regression.

- [ ] **Step 5: Static gates, commit**

```bash
git add src/Service/Backup src/Repository tests/Service/Backup
git commit -m "feat(#412): restore an account from a backup, replace-only"
```

---

### Task 9: Restore endpoints and the nginx body cap

**Files:**
- Modify: `backend/src/Controller/Api/AccountBackupController.php`
- Create: `backend/src/Http/RestorePreviewJson.php`
- Create: `backend/src/Http/RestoreResultJson.php`
- Modify: `docker/nginx/default.conf`
- Test: extend `backend/tests/Controller/Api/AccountBackupControllerTest.php`

**Interfaces:**
- Produces: `POST /api/account/restore/preview` (`api_account_restore_preview`) — raw gzip body → `RestorePreviewJson::from(RestorePreview): array`:

```json
{
  "backup": {"createdAt": "…", "sourceUrl": "…", "sourceEmail": "…"},
  "toLoad": {"tags": 2, "feeds": 5, "subscriptions": 5, "entries": 1200, "entryStates": 40},
  "toDelete": {"tags": 1, "subscriptions": 3, "entryStates": 7, "recommendationRuns": 2}
}
```

- Produces: `POST /api/account/restore?confirm=REPLACE` (`api_account_restore`) — raw gzip body → `{"loaded": {"tags": …, "feeds": …, "subscriptions": …, "entries": …, "entryStates": …}}`.
- Both mappers: `final readonly class` with one static `from()` returning the array — controllers wrap in `JsonResponse`.
- Errors already flow: `InvalidBackupException` → 422 `invalid_backup`, `BackupDoesNotFitException` → 409 `backup_does_not_fit`, missing confirm → 422 `validation_error`, all via `ApiExceptionListener`.

- [ ] **Step 1: Write the failing tests** (append to `AccountBackupControllerTest`)

```php
public function testPreviewReportsLoadAndDeleteCounts(): void
{
    $client = self::createClient();
    [$headers, $user] = $this->auth('restore-preview@example.com');
    $gzip = $this->seededBackupFor($user); // helper: seed 1 tag + 1 feed + 1 sub + 1 entry, export via container exporter, gzencode

    $client->request(
        'POST',
        '/api/account/restore/preview',
        server: $headers + ['CONTENT_TYPE' => 'application/gzip'],
        content: $gzip,
    );

    self::assertResponseIsSuccessful();
    $body = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    self::assertIsArray($body);
    self::assertSame(1, $body['toLoad']['subscriptions']);
    self::assertSame(1, $body['toDelete']['subscriptions']);
    self::assertSame('restore-preview@example.com', $body['backup']['sourceEmail']);
}

public function testRestoreWithoutConfirmIs422AndDeletesNothing(): void { /* POST /api/account/restore
    without ?confirm; assert 422, type validation_error, and the seeded subscription still exists */ }

public function testRestoreRunsEndToEndOverHttp(): void { /* seed, export via GET /api/account/backup
    (ob_start capture), wipe check: POST it back with ?confirm=REPLACE, assert 200, loaded counts,
    then GET /api/subscriptions and assert the subscription is present */ }

public function testGarbageBodyIs422InvalidBackup(): void { /* content: 'not gzip' → 422, type invalid_backup */ }
```

Write the four fully (the elided bodies follow the two patterns already in this file: seed → request → decode → assert).

- [ ] **Step 2: Run, expect 404/failures**

- [ ] **Step 3: Implement** the two actions + mappers:

```php
#[Route('/restore/preview', name: 'api_account_restore_preview', methods: ['POST'])]
public function preview(#[CurrentUser] User $user, Request $request): JsonResponse
{
    return new JsonResponse(RestorePreviewJson::from($this->previewer->preview($user, $request->getContent())));
}

#[Route('/restore', name: 'api_account_restore', methods: ['POST'])]
public function restore(#[CurrentUser] User $user, Request $request): JsonResponse
{
    $confirmation = $request->query->get('confirm');
    $result = $this->restorer->restore($user, $request->getContent(), \is_string($confirmation) ? $confirmation : null);

    return new JsonResponse(RestoreResultJson::from($result));
}
```

Add `RestorePreviewer` and `AccountRestorer` to the constructor. Four collaborators on one controller is fine; it stays thin.

- [ ] **Step 4: nginx** — in `docker/nginx/default.conf`, above `location /`:

```nginx
    # The restore uploads a whole-account backup (~4 MiB gzipped real-world);
    # 25m is 6x headroom while still refusing runaway bodies long before
    # post_max_size would. Only this route carries uploads that big.
    location = /api/account/restore {
        client_max_body_size 25m;
        try_files $uri /index.php$is_args$args;
    }

    location = /api/account/restore/preview {
        client_max_body_size 25m;
        try_files $uri /index.php$is_args$args;
    }
```

Then `docker compose restart web` (or the nginx service name from `docker compose ps`) and verify the stack still answers: `curl -sk https://localhost:8443/api/health`.

- [ ] **Step 5: Run to green** — `php bin/phpunit tests/Controller/Api/AccountBackupControllerTest.php`, then the full native suite.

- [ ] **Step 6: Static gates, commit**

```bash
git add src/Controller src/Http tests/Controller ../docker/nginx/default.conf
git commit -m "feat(#412): restore preview and restore endpoints with a 25m body cap"
```

---

### Task 10: Frontend API surface

**Files:**
- Create: `frontend/src/app/core/save-as.ts`
- Modify: `frontend/src/app/settings/opml-section.component.ts` (use `saveAs`, drop the private `download`)
- Modify: `frontend/src/app/reader/reader-api.ts`
- Modify: `frontend/src/app/reader/models.ts`
- Test: `frontend/src/app/settings/backup-api.spec.ts` (HttpTestingController tests for the three methods)

**Interfaces:**
- Produces `save-as.ts`:

```ts
/** Hands a Blob to the browser's downloader. Revokes the object URL on the
 *  next tick: Firefox and Safari queue the download asynchronously and read
 *  the blob after click() returns, so a synchronous revoke can save an empty
 *  file. */
export function saveAs(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.style.display = 'none';
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 0);
}
```

- Produces in `models.ts`:

```ts
export interface RestoreCounts {
  tags: number;
  feeds: number;
  subscriptions: number;
  entries: number;
  entryStates: number;
}

export interface RestorePreview {
  backup: { createdAt: string; sourceUrl: string | null; sourceEmail: string | null };
  toLoad: RestoreCounts;
  toDelete: { tags: number; subscriptions: number; entryStates: number; recommendationRuns: number };
}

export interface RestoreResult {
  loaded: RestoreCounts;
}
```

- Produces in `ReaderApi` (next to the OPML pair):

```ts
downloadAccountBackup(): Observable<Blob> {
  return this.http.get(`${this.base}/api/account/backup`, { responseType: 'blob' });
}

previewAccountRestore(backup: Blob): Observable<RestorePreview> {
  return this.http.post<RestorePreview>(`${this.base}/api/account/restore/preview`, backup, {
    headers: { 'Content-Type': 'application/gzip' },
  });
}

restoreAccount(backup: Blob): Observable<RestoreResult> {
  return this.http.post<RestoreResult>(
    `${this.base}/api/account/restore?confirm=REPLACE`,
    backup,
    { headers: { 'Content-Type': 'application/gzip' } },
  );
}
```

- Modify `OpmlSectionComponent.download()` away: replace its body's usage with `saveAs(new Blob([xml], { type: 'text/x-opml' }), 'feeds.opml')` and delete the private method (second occurrence of the download dance — extract now, before the backup section makes it a third).

- [ ] **Step 1: Write the failing spec** — `backup-api.spec.ts` with `provideHttpClientTesting`: assert method, URL (incl. `?confirm=REPLACE`), `Content-Type: application/gzip` header, and blob response typing. Follow the arrangement of an existing `reader-api` spec if one exists (`git grep -l HttpTestingController frontend/src/app` — mirror the closest file).

- [ ] **Step 2: Run, expect failure** — from `frontend/`: `npm test -- --testPathPattern=backup-api`

- [ ] **Step 3: Implement** the three methods, the models, `save-as.ts`, and the OPML refactor.

- [ ] **Step 4: Run to green** — `npm test -- --testPathPattern='backup-api|opml'`, then `npm run check`.

- [ ] **Step 5: Commit**

```bash
git add src/app/core/save-as.ts src/app/reader src/app/settings
git commit -m "feat(#412): backup/restore API methods and a shared saveAs helper"
```

---

### Task 11: The backup settings section

A new card below the OPML pair on the same `/settings/import` page. Flow: download button; restore file picker → preview report (counts + old-file warning + failed-restore recovery note) → type `REPLACE` inline → restore → result or error with the "run the same file again" message. The OPML safety-net export sits inside the confirm block.

**Files:**
- Create: `frontend/src/app/settings/backup-section.component.ts`
- Create: `frontend/src/app/settings/backup-section.component.html`
- Create: `frontend/src/app/settings/backup-section.component.scss`
- Test: `frontend/src/app/settings/backup-section.component.spec.ts`
- Modify: `frontend/src/app/settings/opml-section.component.ts` (+`.html`) — import and render `<app-backup-section />` after the OPML card
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `ReaderApi.downloadAccountBackup/previewAccountRestore/restoreAccount`, `saveAs`, `parseProblem`, `SettingsCardComponent`, `ButtonComponent`, `ErrorBannerComponent`, `TranslocoPipe`, `SubscriptionsStore.load()`, `RefreshService.run()`.

Component state (signals): `exporting`, `file: signal<File | null>`, `previewing`, `preview: signal<RestorePreview | null>`, `typed: signal<'' | string>`, `restoring`, `result: signal<RestoreResult | null>`, `error: signal<Problem | null>`, `failedOnce: signal<boolean>` (drives the persistent "run the same file again" banner after any failed restore). `canRestore = computed(() => this.typed() === 'REPLACE' && !!this.file() && !this.restoring())`.

Behaviour details:

- `onFile` stores the `File` and immediately calls `previewAccountRestore(file)`; a preview error (invalid_backup / does-not-fit) lands in `error` and clears `preview`.
- `restore()` posts the SAME `File` again (the double upload is the design), sets `failedOnce` on error, and on success: clear `file`/`typed`/`preview`, set `result`, `subs.load()`, and `refresh.run(() => this.subs.load())` — restored feeds have a virgin schedule and fill in on the next refresh, same reasoning as the OPML import.
- `downloadBackup()` → `saveAs(blob, 'account-backup.json.gz')` (the server's Content-Disposition name wins in real browsers only for navigations; for a programmatic blob the client names it — a static name is fine).
- The safety-net OPML export inside the confirm block calls the same `api.exportOpml()` + `saveAs` pair the OPML section uses.
- Accept attribute on the picker: `.gz,application/gzip`.
- Template shows `preview.toLoad`/`preview.toDelete` counts, the backup's `createdAt` + `sourceEmail`, and two static warnings (translated): the everything-is-replaced warning and the old-file age warning. All copy via Transloco keys — no literals.

i18n keys (add to `settings` in both files, German translations in `de.json`):

```json
"backup": {
  "title": "Account backup",
  "lead": "Download everything this account owns as one file, or replace this account with a backup from another instance.",
  "export": "Download backup",
  "preparing": "Preparing…",
  "restoreLead": "Restoring replaces this account's feeds, tags, articles and settings with the backup. Your login, password and AI connections stay.",
  "chooseFile": "Choose a backup file",
  "previewing": "Checking the file…",
  "reportTitle": "This restore will:",
  "reportDelete": "Delete: {{subscriptions}} subscriptions, {{tags}} tags, {{entryStates}} article states, {{recommendationRuns}} recommendation runs.",
  "reportLoad": "Load: {{subscriptions}} subscriptions, {{tags}} tags, {{feeds}} new feeds, {{entries}} articles, {{entryStates}} article states.",
  "reportSource": "Backup of {{email}}, created {{date}}.",
  "ageWarning": "An old backup file loses articles on the first refresh after restoring: articles beyond the retention window are pruned.",
  "safetyNet": "Download an OPML of the current subscriptions first",
  "typeToConfirm": "Type REPLACE to confirm.",
  "restore": "Replace this account",
  "restoring": "Restoring…",
  "done": "Restore complete: {{subscriptions}} subscriptions, {{entries}} articles.",
  "failed": "The restore failed and the account may be partly loaded. Run the restore again with the same file — the wipe restarts cleanly.",
  "emailMismatchNote": "The backup's account email does not need to match this account."
}
```

- [ ] **Step 1: Write the failing spec** — Jest, `TestBed` with `ReaderApi` mocked (jasmine-style jest mocks matching neighbouring component specs — read `opml-section.component.spec.ts` first and mirror its scaffolding). Cover: (a) choosing a file triggers a preview call and renders the report counts; (b) the restore button stays disabled until `REPLACE` is typed exactly; (c) a successful restore calls `restoreAccount` with the same File and reloads subscriptions; (d) a failed restore shows the re-run message and keeps the file selected; (e) a preview `409` problem shows its detail in the error banner.

- [ ] **Step 2: Run, expect failure** — `npm test -- --testPathPattern=backup-section`

- [ ] **Step 3: Implement** component + template + scss (spacing via the theme's spacing tokens — copy whatever `opml-section.component.scss` uses; no raw px, no hex).

- [ ] **Step 4: Wire in** — append `<app-backup-section />` after `</app-settings-card>` in `opml-section.component.html` and add the import. The section list and routes stay untouched (`/settings/import` already exists).

- [ ] **Step 5: Run to green** — `npm test -- --testPathPattern='backup-section|opml'` then the full `npm run check`.

- [ ] **Step 6: Commit**

```bash
git add src/app/settings frontend/public/i18n ../frontend/public/i18n 2>/dev/null || git add .
git commit -m "feat(#412): backup & restore settings section"
```

(Use plain `git add frontend/src/app/settings frontend/public/i18n` from the repo root if the relative forms fight back.)

---

### Task 12: Full verification, live smoke, PR

- [ ] **Step 1: Backend gates, both legs**

From `backend/`: `composer check && composer md && php bin/phpunit`
From repo root: `docker compose exec php vendor/bin/phpunit`
(Remember the standing MySQL rate-limiter flake: failures in limiter tests that pass in isolation are pre-existing.)

- [ ] **Step 2: Mutation gate** — from `backend/`: `composer infection:diff`. The round-trip and unit tests above are the MSI budget; if a file scores under the gate, add targeted unit tests for the escaped mutants (annotations name the lines) rather than weakening assertions elsewhere.

- [ ] **Step 3: PhpStorm inspections** — `mcp__phpstorm__lint_files` on every changed PHP file; fix ERROR and WARNING findings.

- [ ] **Step 4: Frontend gate** — from `frontend/`: `npm run check`.

- [ ] **Step 5: Live smoke against the Docker stack** — bring the stack up; **restart the containers first** (`docker compose restart php worker frontend`) so no stale container serves old code, and run the new migrationless code path end to end: log into the dev account in the browser (`https://localhost:8443`), download a backup, restore it onto the same account, confirm the reader still shows the feeds and `backend/var/log/dev.log` shows no new errors or deprecations (`grep -iE 'error|deprecat' backend/var/log/dev.log | tail`). **Never wipe or restore over the dev account with a file from a different account.**

- [ ] **Step 6: Architecture §6 checklist** — copy the table from the spec into the PR body (bearer ✓, stateless ✓, JSON partly — gzip payloads, consciously accepted, no browser-only inputs ✓, no redirect handoff ✓).

- [ ] **Step 7: PR**

```bash
git push -u origin feature/412-account-backup-restore
gh pr create --base develop --title "Back up and restore a whole account between instances (#412)" --body "Closes #412. <summary + §6 table + measurement notes>"
```

After CI: check the real run conclusion by run id (`gh run view <id>`), not `gh run watch`'s exit status. After merge: verify #412 auto-closed.

---

## Self-Review Notes

- Spec §1–§9 all map to tasks: file contents (T2/T3), streaming export (T3/T4), replace-only + survivors (T5), fit-before-wipe (T6/T8), shared-row rules (T8 tests 3/4), timestamps kept (T7 round-trip assertions), confirmation + double upload (T8/T9/T11), no transaction/re-run recovery (T8 test 7, T11 copy), limits (T9 nginx), UI (T11), tests incl. streaming pin (T3) and Infection (T12).
- Additions over the spec, both named in task text: the `footer` truncation guard (T2) and search-index maintenance for restored entries (T8) — the spec predates #432's index and never mentions it, but a restored account with invisible search results would be a defect.
- Type consistency checked: `EntryLine` field order matches `EntryBatchInserter::row()` and the exporter's serialiser; `RestoreCounts` mirrors `RestoreResult`; `guidHash` never computed outside `Entry`.
