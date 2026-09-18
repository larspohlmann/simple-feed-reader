# Split Backup Restore Implementation Plan (#1073)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Export an account as a zip of independent gzipped NDJSON parts and restore it one part per request, so no restore request grows with the account.

**Architecture:** The exporter walks entries in keyset batches, fills a byte-budgeted part buffer (entries + the states of exactly those entries) and hands finished `BackupPart`s to a ZipStream response; the foundation part is written last so it can carry `parts` and `totals`. The restore is three stateless endpoints: `preview` and `start` take part 0, `entries` takes one entry part and is additive and idempotent. The Angular client opens the zip with zip.js, verifies it before the wipe, and posts members sequentially with retry, progress and a Continue button.

**Tech Stack:** PHP 8.4 / Symfony 7.4, `maennchen/zipstream-php` ^3, Doctrine ORM; Angular 20 signals, `@zip.js/zip.js`, `DecompressionStream`, Jest.

**Spec:** `docs/superpowers/specs/2026-09-18-1073-split-backup-restore-design.md` — read it first. Issue: https://github.com/larspohlmann/simple-feed-reader/issues/1073. Branch: `feature/1073-split-backup-restore` (exists).

## Global Constraints

- `BackupSchema::VERSION = 3`. Line fields of `account`, `tag`, `savedSearch`, `feed`, `subscription`, `entry`, `entryState` do not change.
- Member names: `000-foundation.ndjson.gz`, `NNN-entries.ndjson.gz` (3-digit, 1-based, contiguous). Zip method **store**.
- Part budget (exporter): close at ≥ `8 * 1024 * 1024` inflated line bytes or `2000` entries.
- Part ceilings (reader, every part): `5000` entry lines, `64 * 1024 * 1024` inflated bytes → `InvalidBackupException` while streaming.
- Account entry ceiling on the entries endpoint: `500_000` → `BackupDoesNotFitException`.
- Client retry: 3 retries per part, delays `1000, 2000, 4000` ms, requests strictly sequential.
- The 64M request body limit is **not** changed. `RequestBodyLimitAgreementTest` must stay green untouched.
- No version 2 compatibility anywhere. Delete, do not deprecate.
- CLAUDE.md is binding: Clean Code rules, `final readonly class` by default, no boolean flag parameters, comments only above the bar, every touched `src` file PHPMD-clean, `ThinControllerRule`, phptramp (per-pass collaborators instead of threaded parameters), PHPStan max.
- Commit format: `type(#1073): subject`. No attribution lines.
- Gates before the PR: `composer check`, `composer md`, `php bin/phpunit`, `docker compose exec php composer test`, `composer infection:diff`, and in the frontend container `docker compose exec -T frontend npm run check`. Never run two Jest runs at once in the container (OOM, exit 137).
- New frontend dependency: install it inside the frontend container too (`docker compose exec frontend npm ci`) and clear the Angular cache, or :4200 serves a stale chunk.
- Do **not** restore into Lars' real account on any stack without asking him. Never clear the dev database.
- Two spec deviations (§10 of the spec) are awaiting Lars' confirmation; they are implemented as the spec states.

## File Structure

Backend — create:
- `src/Service/Backup/Dto/BackupTotals.php` — `{entries, entryStates}` claim on the part 0 header.
- `src/Service/Backup/BackupLines.php` — record → NDJSON line shaping, moved out of the exporter.
- `src/Service/Backup/BackupPart.php` — `{memberName, gzipBytes}`.
- `src/Service/Backup/BackupPartBuffer.php` — per-export working set: entry lines, state lines, budget.
- `src/Service/Backup/EntryPartInspector.php` — pass 1 of the entries endpoint.
- `src/Service/Backup/EntryPartRestorer.php` — entries endpoint orchestration.
- `src/Service/Backup/RestoreFeedTargets.php` — per-pass lazy `feedUrl ⇒ RestoreFeedTarget`.

Backend — modify: `BackupSchema`, `Dto/BackupHeader`, `BackupReader`, `BackupTally`, `BackupInventory`, `AccountBackupExporter`, `BackupDownloadResponseFactory`, `BackupFilename`, `AccountRestorer`, `RestoreLoader`, `RestoreLoadPass`, `RestoreEntryLoader`, `RestorePreviewer`/`RestorePreview`, `Http/RestorePreviewJson`, `Http/RestoreResultJson`, `Controller/Api/AccountBackupController`, `Repository/EntryStateRepository`, `Repository/EntryRepository`, `Repository/SubscriptionRepository`, `composer.json`, `.github/workflows/ci.yml`.

Frontend — create in `frontend/src/app/settings/`: `backup-part-header.ts`, `backup-archive.ts`, `backup-restore-run.ts` (+ specs). Modify: `backup-section.component.{ts,html,scss,spec.ts}`, `reader/reader-api.ts`, `reader/models.ts`, `public/i18n/{en,de}.json`.

Docs: `docs/backup.md`.

---

### Task 1: Schema version 3 — header fields and per-part grammar

**Files:**
- Create: `backend/src/Service/Backup/Dto/BackupTotals.php`
- Modify: `backend/src/Service/Backup/BackupSchema.php`, `Dto/BackupHeader.php`, `BackupReader.php`
- Test: `backend/tests/Service/Backup/BackupReaderTest.php`

**Interfaces — Produces:**
- `BackupTotals(int $entries, int $entryStates)`, `BackupTotals::fromHeaderLine(array $line): ?self` (absent or `null` field ⇒ `null`).
- `BackupHeader(int $schemaVersion, \DateTimeImmutable $createdAt, ?string $sourceUrl, ?string $sourceEmail, string $backupId, int $part, ?int $parts, ?BackupTotals $totals)`, `isFoundation(): bool` (`0 === $this->part`).
- `BackupReader::read(string $gzipBytes): \Generator<int, object>` — unchanged signature, new rules below.
- `BackupReader::MAX_ENTRIES_PER_PART = 5000`, `BackupReader::MAX_INFLATED_BYTES = 67_108_864` (public consts; tests reference them).

Reader rules (all `InvalidBackupException`):
1. Version must be 3 (existing check, new constant).
2. Foundation (`part === 0`) allows `account, tag, savedSearch, feed, subscription`; requires the account line; requires `parts ≥ 1` and `totals`.
3. Entry part (`part ≥ 1`) allows `entry, entryState` only; no account line required; `parts`/`totals` must be `null`.
4. `part < 0` is refused. A kind outside the part's set: `Line %d of kind "%s" does not belong in part %d.`
5. Running sum of `strlen($line) + 1` over `MAX_INFLATED_BYTES`, or more than `MAX_ENTRIES_PER_PART` entry lines: refused at the offending line, before it is yielded.
6. Rank order, singleton and footer-count rules stay as they are.

- [ ] **Step 1: Update the test helpers and write the failing tests.** In `BackupReaderTest`, change `header()` to:

```php
/** @return array<string, mixed> */
private static function header(int $part = 0, int $schemaVersion = 3): array
{
    return [
        'kind' => 'header',
        'schemaVersion' => $schemaVersion,
        'createdAt' => '2026-08-17T09:00:00+00:00',
        'sourceUrl' => 'https://source.example',
        'sourceEmail' => 'source@example.com',
        'backupId' => 'b4c1d2e3f4a5b6c7',
        'part' => $part,
        'parts' => 0 === $part ? 2 : null,
        'totals' => 0 === $part ? ['entries' => 1, 'entryStates' => 1] : null,
    ];
}
```

Every existing test that mixes entries into a document that also has an account line must be split: foundation cases use `header(0)`, entry cases use `header(1)` with no account line. Add these tests (names are the spec; reuse the file's existing `entry()` / `entryState()` / `footer()` helpers, reading them first):

```php
public function testAnEntryPartNeedsNoAccountLine(): void
{
    $lines = iterator_to_array((new BackupReader())->read(self::gzipOf([
        self::header(1),
        self::entry(),
        self::entryState(),
        self::footer(['entry' => 1, 'entryState' => 1]),
    ])), false);

    self::assertInstanceOf(BackupHeader::class, $lines[0]);
    self::assertSame(1, $lines[0]->part);
    self::assertFalse($lines[0]->isFoundation());
    self::assertInstanceOf(EntryLine::class, $lines[1]);
    self::assertInstanceOf(EntryStateLine::class, $lines[2]);
}

public function testTheFoundationRefusesAnEntryLine(): void
{
    $this->expectException(InvalidBackupException::class);
    $this->expectExceptionMessage('Line 3 of kind "entry" does not belong in part 0.');

    iterator_to_array((new BackupReader())->read(self::gzipOf([
        self::header(0), self::account(), self::entry(), self::footer(['entry' => 1]),
    ])), false);
}

public function testAnEntryPartRefusesAFeedLine(): void
{
    $this->expectException(InvalidBackupException::class);
    $this->expectExceptionMessage('Line 2 of kind "feed" does not belong in part 1.');

    iterator_to_array((new BackupReader())->read(self::gzipOf([
        self::header(1), self::feed(), self::footer(['feed' => 1]),
    ])), false);
}

public function testTheFoundationMustDeclareItsPartsAndTotals(): void
{
    $header = self::header(0);
    $header['parts'] = null;
    $this->expectException(InvalidBackupException::class);

    iterator_to_array((new BackupReader())->read(self::gzipOf([$header, self::account(), self::footer()])), false);
}

public function testVersionTwoIsRefused(): void
{
    $this->expectException(InvalidBackupException::class);
    $this->expectExceptionMessage('Unsupported schema version 2; this instance reads version 3.');

    iterator_to_array((new BackupReader())->read(self::gzipOf([
        self::header(0, 2), self::account(), self::footer(),
    ])), false);
}

public function testAPartOverTheEntryCeilingIsRefusedBeforeTheExtraLineIsYielded(): void
{
    $lines = [self::header(1)];
    for ($i = 0; $i <= BackupReader::MAX_ENTRIES_PER_PART; ++$i) {
        $lines[] = ['guid' => "g-$i", 'guidHash' => hash('sha256', "g-$i")] + self::entry();
    }
    $yielded = 0;

    try {
        foreach ((new BackupReader())->read(self::gzipOf($lines)) as $line) {
            $yielded += $line instanceof EntryLine ? 1 : 0;
        }
        self::fail('The ceiling did not trip.');
    } catch (InvalidBackupException $e) {
        self::assertStringContainsString('more than 5000 entries', $e->getMessage());
    }

    self::assertSame(BackupReader::MAX_ENTRIES_PER_PART, $yielded);
}
```

For the byte ceiling, add one test that feeds a part whose single `entry` has a `contentHtml` of `str_repeat('a', BackupReader::MAX_INFLATED_BYTES)` and expects `InvalidBackupException` containing `inflates past`. (gzip makes that body tiny; memory use is the one string — acceptable in a unit test; if the native run OOMs, build the string once in a static.)

- [ ] **Step 2: Run** `php bin/phpunit tests/Service/Backup/BackupReaderTest.php` — expect failures on the new tests and on every old test that still builds a version 2 document.

- [ ] **Step 3: Implement.** `BackupSchema::VERSION = 3`. `BackupTotals`:

```php
final readonly class BackupTotals
{
    public function __construct(public int $entries, public int $entryStates)
    {
    }

    /** @param array<string, mixed> $line */
    public static function fromHeaderLine(array $line): ?self
    {
        $totals = $line['totals'] ?? null;
        if (null === $totals) {
            return null;
        }
        if (!\is_array($totals)) {
            throw new InvalidBackupException('Field "totals" is not an object.');
        }

        /** @var array<string, mixed> $totals */
        return new self(LineField::int($totals, 'entries'), LineField::int($totals, 'entryStates'));
    }
}
```

`BackupHeader::fromLine` adds `backupId: LineField::string(...)`, `part: LineField::int(...)`, `parts: LineField::intOrNull(...)` (check `LineField` — add `intOrNull` beside `stringOrNull` if it does not exist, with a test in the LineField test file), `totals: BackupTotals::fromHeaderLine($line)`.

In `BackupReader::read`, keep a local `?BackupHeader $header`. After `toDto` returns a header, call `assertCoherentHeader($header)` (rules 2–4 on `parts`/`totals`/`part`). For every non-header, non-footer kind call `assertKindBelongs($kind, $header, $lineNumber)` using two constants `FOUNDATION_KINDS` and `ENTRY_PART_KINDS`. Replace the unconditional `assertAccountSeen` with one that returns early when the header is not the foundation. Add the two running counters at the top of the loop. Keep each method single-purpose; `read()` must stay under PHPMD's complexity limits — extract a small per-read collaborator (`BackupPartGuard`, per-call, holds header + counters, methods `seeLine(string $line)`, `seeKind(string $kind, int $lineNumber)`) rather than growing `read()`.

- [ ] **Step 4: Run** the reader test — expect PASS. Then `composer cs && composer stan && composer md`.
- [ ] **Step 5: Commit** `feat(#1073): backup schema v3 — part header and per-part grammar`. The rest of the suite is red until Task 6; that is expected inside this branch, note it in the commit body.

---

### Task 2: Exporter writes parts

**Files:**
- Create: `backend/src/Service/Backup/BackupLines.php`, `BackupPart.php`, `BackupPartBuffer.php`
- Modify: `backend/src/Service/Backup/AccountBackupExporter.php`, `backend/src/Repository/EntryStateRepository.php`
- Test: `backend/tests/Service/Backup/AccountBackupExporterTest.php`, `backend/tests/Service/Backup/BackupPartBufferTest.php` (new), repository test next to the existing `EntryStateRepository` tests

**Interfaces:**
- Consumes: Task 1 header shape.
- Produces:
  - `BackupPart(string $memberName, string $gzipBytes)`; `BackupPart::foundation(string $gzipBytes): self` ⇒ `000-foundation.ndjson.gz`; `BackupPart::entries(int $part, string $gzipBytes): self` ⇒ `sprintf('%03d-entries.ndjson.gz', $part)`.
  - `BackupPartBuffer`: `add(string $entryLine, ?string $entryStateLine): void`, `isFull(): bool`, `isEmpty(): bool`, `entryCount(): int`, `entryStateCount(): int`, `drain(string $headerLine): string` (returns gzip bytes of header + entries + states + footer, then resets). Consts `MAX_BYTES = 8_388_608`, `MAX_ENTRIES = 2000`.
  - `AccountBackupExporter::parts(User $user, ?string $sourceUrl): \Generator<int, BackupPart>` — entry parts first, foundation **last**. `lines()` is deleted.
  - `EntryStateRepository::forUserByEntryIds(int $userId, array $entryIds): array<int, EntryState>` keyed by entry id; `[]` in ⇒ `[]` out, no query.

- [ ] **Step 1: Failing `BackupPartBufferTest`:**

```php
final class BackupPartBufferTest extends TestCase
{
    public function testDrainWritesHeaderEntriesThenStatesThenAFooterWithThisPartsCounts(): void
    {
        $buffer = new BackupPartBuffer();
        $buffer->add('{"kind":"entry","n":1}', '{"kind":"entryState","n":1}');
        $buffer->add('{"kind":"entry","n":2}', null);

        $lines = explode("\n", rtrim((string) gzdecode($buffer->drain('{"kind":"header"}')), "\n"));

        self::assertSame([
            '{"kind":"header"}',
            '{"kind":"entry","n":1}',
            '{"kind":"entry","n":2}',
            '{"kind":"entryState","n":1}',
            '{"kind":"footer","counts":{"tag":0,"savedSearch":0,"feed":0,"subscription":0,"entry":2,"entryState":1}}',
        ], $lines);
        self::assertTrue($buffer->isEmpty());
    }

    public function testItIsFullAtTheEntryBudget(): void
    {
        $buffer = new BackupPartBuffer();
        for ($i = 1; $i < BackupPartBuffer::MAX_ENTRIES; ++$i) {
            $buffer->add('{}', null);
        }
        self::assertFalse($buffer->isFull());
        $buffer->add('{}', null);
        self::assertTrue($buffer->isFull());
    }

    public function testItIsFullAtTheByteBudget(): void
    {
        $buffer = new BackupPartBuffer();
        $buffer->add(str_repeat('a', BackupPartBuffer::MAX_BYTES - 1), null);
        self::assertFalse($buffer->isFull());
        $buffer->add('a', null);
        self::assertTrue($buffer->isFull());
    }
}
```

- [ ] **Step 2: Run it — FAIL (class missing). Step 3: Implement** `BackupPart` and `BackupPartBuffer` (a non-readonly `final class`; bytes counted as `strlen` of each added line; `drain` uses `gzencode(implode("\n", $lines) . "\n")`). **Step 4: PASS.**

- [ ] **Step 5: Failing repository test** for `forUserByEntryIds`: two users with a state on the same entry, a third entry with no state; assert the result is keyed by entry id, holds only the asked user's states, and that `[]` returns `[]`. Implement with `WHERE s.user = :userId AND s.entry IN (:entryIds)`, `addSelect('e')`, join entry. PASS.

- [ ] **Step 6: Move line shaping.** Create `BackupLines` (`final readonly`, constructor `ClockInterface $clock`) and move `headerLine`, `accountLine`, `tagLine`, `savedSearchLine`, `feedLine`, `subscriptionLine`, `subscriptionTagRefs`, `entryLine`, `entryStateLine`, `formatDate`, `formatDateOrNull`, `encode` into it, each now `public` and returning the **encoded string**. `entryStateLine(EntryState $state, string $feedUrl): string` takes the feed url from its caller (the batch already knows it) instead of walking `entry → feed`. `headerLine` becomes two methods — no flag, no nullable pair:

```php
public function foundationHeader(BackupProvenance $provenance, int $parts, BackupTotals $totals): string
public function entryPartHeader(BackupProvenance $provenance, int $part): string
```

`BackupProvenance` (new tiny `final readonly`, same directory): `string $backupId, \DateTimeImmutable $createdAt, ?string $sourceUrl, string $sourceEmail`. `createdAt` is read from the clock **once** per export so every part carries the same instant. `backupId` = `bin2hex(random_bytes(8))`.

- [ ] **Step 7: Rewrite `AccountBackupExporterTest`** around `parts()`. Read the existing test first and keep every field-level assertion it makes; change only how lines are obtained. Add a helper in the test:

```php
/** @return list<list<array<string, mixed>>> decoded lines per part, in yield order */
private function decodedParts(User $user): array
```

Required new cases:
1. *Small account* (the `FullyPopulatedAccount` fixture): yields exactly 2 parts, names `['001-entries.ndjson.gz', '000-foundation.ndjson.gz']` in that order; part 0 header has `part 0`, `parts 2`, `totals` equal to the real entry/state counts; part 1 header has `part 1`, `parts null`, `totals null`; both share `backupId` and `createdAt`.
2. *States travel with their entries*: for every `entryState` line in a part, an `entry` line with the same `(feedUrl, guidHash)` is in the **same** part.
3. *Budget splits*: persist 2,001 entries in one feed (use `EntryBatchInserter` or a loop with `flush` every 500) → 3 parts, entry counts `[2000, 1]` for parts 1 and 2, foundation `parts === 3`, `totals.entries === 2001`.
4. *No entries*: an account with subscriptions and no entries yields the foundation only, `parts 1`, `totals {0, 0}`.
5. *Every part is a valid document*: feed each part's bytes through `(new BackupReader())->read(...)` without an exception.

- [ ] **Step 8: Implement `parts()`.** Shape (keep methods short; the buffer and provenance are per-export state, so hold them on a per-export collaborator `BackupPartWalk` if phptramp flags the threading — do not thread `$buffer`, `$provenance`, `$counts` through four private methods):

```php
public function parts(User $user, ?string $sourceUrl): \Generator
{
    $userId = $user->getId() ?? throw new \LogicException('Cannot export an unsaved account.');
    $provenance = $this->provenanceOf($user, $sourceUrl);   // read email BEFORE any clear()
    $walk = new BackupPartWalk($this->em, $this->entries, $this->entryStates, $this->lines, $provenance, $userId);

    yield from $walk->entryParts($this->feedUrlsByFeedId($userId));
    yield $this->foundation($userId, $provenance, $walk);
}
```

`BackupPartWalk::entryParts` per feed, per keyset batch of 500: fetch the batch, fetch `forUserByEntryIds($userId, $ids)`, for each entry `buffer->add(entryLine, stateLine|null)`, and **after each add** `if ($buffer->isFull()) { yield $this->closePart(); }`; `em->clear()` after each batch; after the last feed, yield the remainder if `!$buffer->isEmpty()`. It exposes `partsWritten(): int`, `totals(): BackupTotals`.
`foundation()` runs **after** the walk, so it must re-query tags, saved searches and subscriptions by `$userId` (the walk's `clear()` detached everything — this is the trap the old docblock names). It builds the document in memory (430 lines on the real account), counts per kind for its footer, and returns `BackupPart::foundation(...)` with `parts = $walk->partsWritten() + 1`.

Note the old export emitted states by a separate walk over `forUserAfterEntryId`, which is subscription-gated. The new walk only visits subscribed feeds, so the gate is preserved. If `forUserAfterEntryId` has no other caller after this task (`grep -rn forUserAfterEntryId src`), delete it and its test.

- [ ] **Step 9: Run** `php bin/phpunit tests/Service/Backup/AccountBackupExporterTest.php tests/Service/Backup/BackupPartBufferTest.php` — PASS. `composer cs && composer stan && composer md && composer tramp`.
- [ ] **Step 10: Commit** `feat(#1073): exporter yields byte-budgeted parts, foundation last`.

---

### Task 3: Zip download response

**Files:**
- Modify: `backend/composer.json` (+ `composer.lock`, which this repo commits), `backend/src/Service/Backup/BackupDownloadResponseFactory.php`, `BackupFilename.php`, `.github/workflows/ci.yml`
- Test: `backend/tests/Service/Backup/BackupDownloadResponseFactoryTest.php` (create if absent), `BackupFilenameTest.php`

**Interfaces:**
- Consumes: `\Generator<int, BackupPart>` from Task 2.
- Produces: `BackupDownloadResponseFactory::stream(string $accountEmail, \Generator $parts): StreamedResponse` — `Content-Type: application/zip`, filename suffix `.zip`.

- [ ] **Step 1:** `composer require maennchen/zipstream-php:^3.1`. Add `"ext-zip": "*"` to `require-dev` (tests read the archive with `ZipArchive`; runtime does not need it). In `.github/workflows/ci.yml` add `zip` to both `extensions:` lists (lines ~42 and ~165). Confirm the ZipStream 3 constructor with context7 (`resolve-library-id` "maennchen/zipstream-php") before writing code — the named arguments below are from 3.1.
- [ ] **Step 2: Failing test:**

```php
public function testItStreamsEveryPartAsAStoredZipMember(): void
{
    $parts = (static function (): \Generator {
        yield BackupPart::entries(1, (string) gzencode("entries\n"));
        yield BackupPart::foundation((string) gzencode("foundation\n"));
    })();

    $response = $this->factory()->stream('reader@example.com', $parts);
    ob_start();
    $response->sendContent();
    $path = tempnam(sys_get_temp_dir(), 'backup-zip');
    file_put_contents($path, (string) ob_get_clean());

    $zip = new \ZipArchive();
    self::assertTrue($zip->open($path));
    self::assertSame(2, $zip->numFiles);
    self::assertSame("foundation\n", gzdecode((string) $zip->getFromName('000-foundation.ndjson.gz')));
    self::assertSame(\ZipArchive::CM_STORE, $zip->statName('001-entries.ndjson.gz')['comp_method']);
    self::assertSame('application/zip', $response->headers->get('Content-Type'));
    self::assertStringEndsWith('.zip"', (string) $response->headers->get('Content-Disposition'));
    $zip->close();
    unlink($path);
}
```

Update `BackupFilenameTest` expectations from `.json.gz` to `.zip`.

- [ ] **Step 3: Implement.** `BackupFilename::SUFFIX = '.zip'`. In the factory callback:

```php
$zip = new ZipStream(
    sendHttpHeaders: false,
    defaultCompressionMethod: CompressionMethod::STORE,
    defaultEnableZeroHeader: false,
);
foreach ($parts as $part) {
    $zip->addFile(fileName: $part->memberName, data: $part->gzipBytes);
}
$zip->finish();
```

Rewrite the class docblock in ≤ 3 lines: what is buffered (one gzipped part) and that `Content-Encoding` stays unset. Delete the old one.

- [ ] **Step 4:** PASS; `composer check && composer md`. **Step 5: Commit** `feat(#1073): stream the backup as a stored zip`.

---

### Task 4: `start` and `preview` take part 0

**Files:**
- Modify: `BackupTally.php`, `BackupInventory.php`, `AccountRestorer.php`, `RestoreLoader.php`, `RestoreLoadPass.php`, `RestorePreviewer.php`, `Http/RestorePreviewJson.php`, `Http/RestoreResultJson.php`
- Test: `BackupInspectorTest`, `RestorePreviewerTest`, `RestoreLoadPassTest`, `AccountRestorerTest`

**Interfaces — Produces:**
- `BackupInventory` gains `int $savedSearches`. For a foundation, `entries`/`entryStates` are `header->totals`; for an entry part they are the counted lines.
- `BackupTally`: entry/state lines are counted only — `assertSubscribed` and the `subscribedFeedUrls`-based entry checks are **deleted** (an entry part carries no subscriptions; Task 5 checks against the database). Subscription → feed/tag reference checks stay.
- `AccountRestorer::start(User $user, string $gzipBytes, ?string $confirmation): RestoreResult` replaces `restore()`. Refuses a non-foundation with `InvalidBackupException('The restore starts with part 0, the foundation.')` **before** the wipe.
- `RestorePreviewer::preview()` refuses a non-foundation the same way.
- `RestorePreviewJson` adds `backup.backupId`, `backup.parts`, `toLoad.savedSearches`. `RestoreResultJson` adds `loaded.savedSearches`.
- `RestoreLoadPass` loses `acceptEntry`, `acceptEntryState`, `startEntryPhase`, `feedTargets` and its `RestoreEntryLoader`/`EntryRepository` dependencies; `run()` ends with one guarded `flush()` (keep the `DbalException → BackupLoadFailedException` wrap). `RestoreLoader::load` no longer builds a `RestoreEntryLoader`.

- [ ] **Step 1: Failing tests.** In `AccountRestorerTest` add:

```php
public function testStartRefusesAnEntryPartBeforeDeletingAnything(): void
{
    $user = $this->accountWithOneSubscription();      // use the file's existing fixture builder
    try {
        $this->restorer()->start($user, $this->entryPartGzip(), 'REPLACE');
        self::fail('An entry part started a restore.');
    } catch (InvalidBackupException) {
    }
    self::assertSame(1, $this->subscriptionCount($user));
}

public function testStartLoadsTheFoundationAndReportsNoEntries(): void
{
    $result = $this->restorer()->start($this->emptyAccount(), $this->foundationGzip(), 'REPLACE');

    self::assertSame(1, $result->tags);
    self::assertSame(1, $result->savedSearches);
    self::assertSame(1, $result->subscriptions);
    self::assertSame(0, $result->entries);
}

public function testTheFitCheckJudgesTheFoundationsClaimedTotals(): void
{
    $foundation = $this->foundationGzip(totals: ['entries' => 500_001, 'entryStates' => 0]);
    $this->expectException(BackupDoesNotFitException::class);
    $this->restorer()->start($this->emptyAccount(), $foundation, 'REPLACE');
}
```

Build `foundationGzip()`/`entryPartGzip()` as private helpers that emit the Task 1 header shape. The existing `testEveryBackedUpFieldSurvivesTheRestoreRoundTrip` is a named guard in `docs/backup.md` §8 — keep it alive: it moves to Task 6's round-trip (export `parts()` → `start` → `entries`) because it needs both halves; mark it here with `self::markTestIncomplete('re-homed in Task 6')` **only** inside this commit and remove that marker in Task 6. Convert every other test in the file from `restore()` to `start()`, deleting assertions about entries/states (they are re-asserted in Task 5's tests).
In `RestorePreviewerTest` assert `toLoad->entries` equals the header's `totals.entries` and that an entry part is refused.

- [ ] **Step 2:** run the four test files — FAIL. **Step 3:** implement as listed under Interfaces. **Step 4:** PASS + `composer check && composer md`.
- [ ] **Step 5: Commit** `feat(#1073): restore start and preview read the foundation part`.

---

### Task 5: Entries endpoint service

**Files:**
- Create: `EntryPartInspector.php`, `EntryPartRestorer.php`, `RestoreFeedTargets.php`
- Modify: `RestoreEntryLoader.php`, `Repository/EntryRepository.php`, `Repository/EntryStateRepository.php`, `Repository/SubscriptionRepository.php`
- Test: `tests/Service/Backup/EntryPartRestorerTest.php` (new, `DbTestCase`), `RestoreEntryLoaderTest.php`, repository tests

**Interfaces — Produces:**
- `SubscriptionRepository::feedIdsByUrlForUser(int $userId, array $feedUrls): array<string, int>` (only subscribed feeds appear).
- `EntryRepository::countInFeedsSubscribedBy(int $userId): int`.
- `EntryStateRepository::entryIdsWithStateOf(int $userId, array $entryIds): array<int, true>`.
- `RestoreFeedTargets` (per pass, `final class`): `__construct(int $userId, SubscriptionRepository, FeedRepository, EntryRepository)`, `for(string $feedUrl): RestoreFeedTarget` — builds on first sight (`feedIdsByUrlForUser` for that url, `isReadByAnotherUser`, `guidHashToIdMapForFeed`), memoises; an unsubscribed url throws `BackupLoadFailedException::danglingReference(...)` (backstop; the inspector refuses it first).
- `RestoreEntryLoader::begin(RestoreFeedTargets $targets, User $user)` — replaces the array parameter; `target()` delegates to `$targets->for()`.
- `EntryPartInspector::inspect(User $user, string $gzipBytes): void`.
- `EntryPartRestorer::load(User $user, string $gzipBytes): RestoreResult` (tags/savedSearches/feeds/subscriptions are 0).

State idempotency in `RestoreEntryLoader`: `loadState` no longer persists immediately. It resolves the entry id, appends `[$line, $entryId]` to `$heldStates`, and at `BATCH` (and in `finish()`) calls `writeHeldStates()`: one `entryIdsWithStateOf` query, persist only the ids not in the result, count only those in `entryStatesCreated`, then the existing flush/clear.

- [ ] **Step 1: Failing `EntryPartRestorerTest`.** Helpers: `subscribedUser(string $feedUrl): User`, `entryPart(array $lines): string` (header part 1 + lines + correct footer). Cases — write each as its own test method:

| test | arrange | assert |
|---|---|---|
| `testItCreatesTheEntriesAndTheirStates` | 2 entries, 1 state | result `entries 2, entryStates 1`; rows exist; state flags match the line |
| `testARetriedPartCreatesNothingAndFailsNothing` | load the same bytes twice | second result `entries 0, entryStates 0`; row counts unchanged; no exception |
| `testAnEntryTheSchedulerAlreadyFetchedIsKeptAndStillGetsItsState` | pre-insert the entry with a **different title**, no state | result `entries 0, entryStates 1`; title unchanged; state attached |
| `testAnExistingStateRowIsLeftUntouched` | pre-insert entry + state `isFavorite=false`; file says `isFavorite=true` | state still `false`; result `entryStates 0` |
| `testAFeedAnotherAccountReadsGetsNoNewEntries` | second user subscribes to the feed | result `entries 0`; no entry rows |
| `testAPartNamingAnUnsubscribedFeedIsRefusedBeforeAnyWrite` | entry 1 subscribed feed, entry 2 foreign feed | `InvalidBackupException`; **zero** entry rows (proves pass 1 runs first) |
| `testTheFoundationIsRefused` | part 0 bytes | `InvalidBackupException` |
| `testItRefusesAPartThatWouldBreachTheAccountEntryCeiling` | stub via a subclass-free route: make the ceiling injectable — constructor arg `int $accountEntryCeiling = 500_000` bound in `services.yaml`; test builds the inspector with `2` and pre-inserts 2 entries | `BackupDoesNotFitException` |
| `testCreatedEntriesReachTheSearchIndex` | recording `EntryIndexer` double (check how `RestoreEntryLoaderTest` does it today) | indexed ids === created ids |

The retry test and the untouched-state test are the guards for spec §6. Verify each guard by breaking what it guards once (remove the `entryIdsWithStateOf` filter → both must go red), then restore the code with an editor undo, **not** `git checkout --`.

- [ ] **Step 2:** FAIL. **Step 3: Implement.**

```php
final readonly class EntryPartRestorer
{
    public function __construct(
        private EntityManagerInterface $em,
        private EntryPartInspector $inspector,
        private BackupReader $reader,
        private SubscriptionRepository $subscriptions,
        private FeedRepository $feeds,
        private EntryRepository $entries,
        private EntryBatchInserter $inserter,
        private EntryIndexer $indexer,
        private ClockInterface $clock,
    ) {
    }

    public function load(User $user, string $gzipBytes): RestoreResult
    {
        $this->inspector->inspect($user, $gzipBytes);

        $loader = new RestoreEntryLoader($this->em, $this->entries, $this->inserter, $this->indexer, $this->clock);
        $loader->begin(
            new RestoreFeedTargets((int) $user->getId(), $this->subscriptions, $this->feeds, $this->entries),
            $user,
        );
        foreach ($this->reader->read($gzipBytes) as $line) {
            match (true) {
                $line instanceof EntryLine => $loader->bufferEntry($line),
                $line instanceof EntryStateLine => $loader->loadState($line),
                default => null,
            };
        }
        $loader->finish();

        return RestoreResult::ofEntryPart($loader->entriesCreated(), $loader->entryStatesCreated());
    }
}
```

Nine constructor dependencies trips PHPMD's parameter count: bundle the five the loader needs into an autowired `RestoreEntryLoaderFactory` (`create(User $user): RestoreEntryLoader`, which also builds the targets and calls `begin`). `RestoreResult::ofEntryPart(int, int)` and `RestoreResult::ofFoundation(...)` named constructors replace positional zeros.

`EntryPartInspector::inspect`: read the part; first yielded object must be a header with `part ≥ 1` else `InvalidBackupException('This request takes an entry part, not the foundation.')`; collect the set of `feedUrl`s and the entry count; then `feedIdsByUrlForUser` for the set — any missing url → `InvalidBackupException` with the existing "none of its subscriptions names" wording; then `countInFeedsSubscribedBy + $entryCount > $accountEntryCeiling` → `BackupDoesNotFitException`.

- [ ] **Step 4:** PASS + `composer check && composer md`. **Step 5: Commit** `feat(#1073): additive, idempotent entry-part restore`.

---

### Task 6: Controller, removal of version 2, round-trip guard, fixtures

**Files:**
- Modify: `src/Controller/Api/AccountBackupController.php`, `tests/Controller/Api/AccountBackupControllerTest.php`, `tests/Service/Backup/GoldenBackupRestoreTest.php`, `tests/Service/Backup/AccountRestorerTest.php`, `tests/Service/Backup/EntryMediaBackupRoundTripTest.php`, `tests/Service/Backup/BackupSchemaCoverageTest.php`
- Create: `tests/Fixtures/backup/current/000-foundation.ndjson`, `tests/Fixtures/backup/current/001-entries.ndjson`, `tests/Fixtures/backup/oldest-supported/…` (same two files), `tests/Support/BackupArchiveReader.php`
- Delete: `tests/Fixtures/backup/current.ndjson`, `oldest-supported.ndjson`, `version-2.ndjson`

**Interfaces — Produces** routes:

```php
#[Route('/restore/preview', name: 'api_account_restore_preview', methods: ['POST'])]
#[Route('/restore/start',   name: 'api_account_restore_start',   methods: ['POST'])]   // ?confirm=REPLACE
#[Route('/restore/entries', name: 'api_account_restore_entries', methods: ['POST'])]
```

`backup()` calls `$this->exporter->parts(...)`. The `/restore` route and `AccountRestorer::restore` are gone. Actions stay single-expression delegations (ThinControllerRule).

- [ ] **Step 1: `tests/Support/BackupArchiveReader`** — test-only: `fromResponseContent(string $zipBytes): self`, `foundation(): string` (gzip bytes), `entryParts(): list<string>` sorted by member name. Uses `ZipArchive` on a temp file it unlinks in `__destruct`.
- [ ] **Step 2: Failing functional round-trip** in `AccountBackupControllerTest`:

```php
public function testABackupRestoresIntoAnotherAccountOnePartPerRequest(): void
{
    [$sourceHeaders] = $this->auth('roundtrip-source@example.com');   // seed with the file's existing fixture helper
    $client = static::getClient();

    ob_start();
    $client->request('GET', '/api/account/backup', server: $sourceHeaders);
    $archive = BackupArchiveReader::fromResponseContent((string) ob_get_clean());

    [$targetHeaders] = $this->auth('roundtrip-target@example.com');
    $gzip = $targetHeaders + ['CONTENT_TYPE' => 'application/gzip'];

    $client->request('POST', '/api/account/restore/start?confirm=REPLACE', server: $gzip, content: $archive->foundation());
    self::assertResponseIsSuccessful();
    $loaded = $this->json($client)['loaded'];
    self::assertSame(1, $loaded['subscriptions']);
    self::assertSame(0, $loaded['entries']);

    foreach ($archive->entryParts() as $part) {
        $client->request('POST', '/api/account/restore/entries', server: $gzip, content: $part);
        self::assertResponseIsSuccessful();
    }
    // then: GET the target's entry list and assert the fixture entry + its favourite flag arrived
}
```

(Check how the existing backup-download test captures a `StreamedResponse` in this file and copy that mechanism; `ob_start` is the usual one.) Also add: `/restore/entries` needs no `confirm`; `/restore/start` without `confirm` is 422 and deletes nothing; the old `/restore` is 404/405; unauthenticated calls to all three are 401. Convert the remaining tests (garbage body, corrupt gzip, bad footer, missing header) to the new routes.

- [ ] **Step 3:** implement the controller; FAIL → PASS.
- [ ] **Step 4: Re-home `testEveryBackedUpFieldSurvivesTheRestoreRoundTrip`** in `AccountRestorerTest`: export with `parts()`, `start()` the foundation, `EntryPartRestorer::load()` each entry part, then run the file's existing field-by-field comparison. Remove the `markTestIncomplete` from Task 4. Do the same conversion in `EntryMediaBackupRoundTripTest`.
- [ ] **Step 5: Fixtures and the golden test.** Hand-write the two version 3 fixture documents from the content of today's `current.ndjson` (same records; add the header fields; entry part carries the entries then their states; footers per part; `parts: 2`, real `totals`). `oldest-supported/` starts as a byte-identical copy — from now on it is frozen and `current/` moves. Rewrite `GoldenBackupRestoreTest` to restore a fixture directory (foundation via `start`, then each `NNN-entries` file via `EntryPartRestorer`) and replace its class docblock with ≤ 3 lines stating the standing rule (additive fields add nothing to the corpus; `oldest-supported` is never regenerated). Add one rejection case: a version 2 header is refused with `Unsupported schema version 2`. Delete the three old fixture files.
- [ ] **Step 6:** `BackupSchemaCoverageTest` / `tests/Support/BackupFieldDeclarations.php`: declare the four new header fields; run it and fix what it reports.
- [ ] **Step 7: Full backend gates:** `composer check && composer md && php bin/phpunit` then `docker compose exec php composer test` (check the php container runs this branch's code first — `docker compose exec php git -C /app log -1 --oneline` or the repo's equivalent; a stale DI container needs `bin/console cache:clear`). All green. Scan today's dev log: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name!="DEBUG" and .level_name!="INFO")'`.
- [ ] **Step 8: Commit** `feat(#1073): part-per-request restore endpoints; drop backup schema v2`.

---

### Task 7: Frontend — read and verify the archive

**Files:**
- Create: `frontend/src/app/settings/backup-part-header.ts`, `backup-archive.ts`, `backup-archive.spec.ts`
- Modify: `frontend/package.json` (+ lockfile)

**Interfaces — Produces:**

```ts
// backup-part-header.ts
export interface BackupPartHeader { backupId: string; part: number; parts: number | null }
export type ReadPartHeader = (member: Blob) => Promise<BackupPartHeader>;
export const readPartHeader: ReadPartHeader;   // DecompressionStream('gzip'), first line only, then cancel the reader

// backup-archive.ts
export class InvalidBackupArchiveError extends Error {}
export interface BackupArchive {
  readonly entryPartCount: number;
  foundation(): Promise<Blob>;
  entryPart(index: number): Promise<Blob>;      // 1-based, matches the member name
  close(): Promise<void>;
}
export function openBackupArchive(file: Blob, readHeader?: ReadPartHeader): Promise<BackupArchive>;
export const isOldFormatBackup = (name: string): boolean => /\.json\.gz$/i.test(name);
```

`openBackupArchive` rejects with `InvalidBackupArchiveError` when: the file is not a zip; `000-foundation.ndjson.gz` is missing; any member name is not `000-foundation.ndjson.gz` or `/^\d{3}-entries\.ndjson\.gz$/`; entry indexes are not exactly `1..n`; a member fails zip.js's signature (CRC) check; a header's `backupId` differs from the foundation's; a header's `part` differs from the member name's index; the foundation's `parts !== n + 1`. Verification extracts one member at a time (`entry.getData(new BlobWriter('application/gzip'), { checkSignature: true })`), reads its header, and drops the blob before the next — never all members at once.

- [ ] **Step 1:** `npm i @zip.js/zip.js` in `frontend/`, then `docker compose exec frontend npm ci` and clear the Angular cache in the container (`rm -rf .angular/cache`).
- [ ] **Step 2: Failing spec.** Build archives in the spec with zip.js's own `ZipWriter` (`level: 0`) over `Uint8Array` members, and inject a fake `readHeader` that maps member bytes → header (members can be plain JSON bytes in the spec; gzip is not needed when the reader is injected). jsdom's `Blob` is incomplete — if zip.js cannot read it, put `/** @jest-environment node */` at the top of this spec. Cases: opens a valid 3-member archive (`entryPartCount === 2`, `entryPart(2)` returns the right bytes); each of the eight rejection rules above (one test each — for the CRC case flip one byte in the stored data region of the zip `Uint8Array`); `isOldFormatBackup('x.json.gz') === true`, `('x.zip') === false`.
- [ ] **Step 3:** implement; `readPartHeader`:

```ts
export const readPartHeader: ReadPartHeader = async (member) => {
  const reader = member
    .stream()
    .pipeThrough(new DecompressionStream('gzip'))
    .pipeThrough(new TextDecoderStream())
    .getReader();
  let text = '';
  try {
    while (!text.includes('\n')) {
      const { value, done } = await reader.read();
      if (done) break;
      text += value;
    }
  } finally {
    await reader.cancel();
  }
  const header = JSON.parse(text.split('\n', 1)[0]) as Partial<BackupPartHeader> & { kind?: string };
  if (header.kind !== 'header' || typeof header.backupId !== 'string' || typeof header.part !== 'number') {
    throw new InvalidBackupArchiveError('The part has no readable header.');
  }
  return { backupId: header.backupId, part: header.part, parts: header.parts ?? null };
};
```

`readPartHeader` itself has no Jest spec (no `DecompressionStream` in jsdom); it is covered by the manual check in Task 10. Say so in the PR body.

- [ ] **Step 4:** in the container: `docker compose exec -T frontend npx jest src/app/settings/backup-archive.spec.ts` PASS, then `npm run lint`. **Step 5: Commit** `feat(#1073): open and verify the backup zip in the browser`.

---

### Task 8: Frontend — API and the restore run

**Files:**
- Modify: `frontend/src/app/reader/reader-api.ts`, `reader/models.ts`
- Create: `frontend/src/app/settings/backup-restore-run.ts`, `backup-restore-run.spec.ts`

**Interfaces — Produces:**

```ts
// models.ts
export interface RestoreCounts { tags: number; savedSearches: number; feeds: number; subscriptions: number; entries: number; entryStates: number }
export interface RestoreResult { loaded: RestoreCounts }
// RestorePreview: backup gains backupId: string, parts: number; toLoad gains savedSearches: number

// reader-api.ts  (restoreAccount is deleted)
previewAccountRestore(foundation: Blob): Observable<RestorePreview>
startAccountRestore(foundation: Blob): Observable<RestoreResult>      // POST …/restore/start?confirm=REPLACE
restoreEntryPart(part: Blob): Observable<RestoreResult>               // POST …/restore/entries

// backup-restore-run.ts
export type RestoreRunOutcome =
  | { kind: 'completed'; loaded: RestoreCounts }
  | { kind: 'stopped'; problem: Problem; wiped: boolean };
@Injectable({ providedIn: 'root' })
export class BackupRestoreRun {
  readonly progress: Signal<{ done: number; total: number } | null>;
  readonly canContinue: Signal<boolean>;
  run(archive: BackupArchive): Promise<RestoreRunOutcome>;
  continue(): Promise<RestoreRunOutcome>;
  reset(): void;                                    // called on file change and on logout-safe teardown
}
export const RESTORE_RETRY_DELAYS_MS = [1000, 2000, 4000] as const;
export const RESTORE_WAIT = new InjectionToken<(ms: number) => Promise<void>>(…)  // default: setTimeout promise
```

Behaviour: `total = entryPartCount + 1`. Step 0 is `start` — **not retried** (a refusal there is pre-wipe and must surface as-is; `wiped` is `outcomeIsUnproven(problem) || problem.type === 'backup_load_failed'`). Steps `1..n` post `archive.entryPart(i)`; each failure is retried after each delay in order; after the last retry fails the run stops with `wiped: true`, remembers the failed index and the accumulated counts, and `canContinue` becomes true. `continue()` resumes at that index with a fresh retry budget. A 4xx other than 408/429 is not retried (the same bytes will be refused again). Counts are summed field-by-field across all responses. The service is a root singleton, so `reset()` must clear archive, index, counts and progress — singleton state survives logout otherwise.

- [ ] **Step 1: Failing spec** with a fake `ReaderApi` (jest fns returning `of(...)`/`throwError(...)`), a fake `BackupArchive`, and `RESTORE_WAIT` provided as `jest.fn().mockResolvedValue(undefined)` — **no fake timers**. Cases: happy path (3 parts → `completed`, summed counts, `progress` ends `{done: 3, total: 3}`, requests strictly sequential — assert call order with a shared log array); a 502 on part 2 that succeeds on the 2nd attempt (wait called with `1000` once); three retries exhausted → `stopped`, `wiped: true`, `canContinue() === true`, wait called with `1000, 2000, 4000`; `continue()` resumes at part 2 and **does not** call `startAccountRestore` again; a 422 on a part is not retried; `start` refusal with `invalid_backup` → `stopped`, `wiped: false`, `canContinue() === false`; `reset()` clears everything.
- [ ] **Step 2–4:** FAIL → implement (`firstValueFrom` per request, a plain `for` loop — no `concatMap` cleverness) → PASS.
- [ ] **Step 5: Commit** `feat(#1073): sequential restore run with retry and continue`.

---

### Task 9: Frontend — backup section UI

**Files:**
- Modify: `frontend/src/app/settings/backup-section.component.{ts,html,scss,spec.ts}`, `frontend/public/i18n/en.json`, `de.json`

**Interfaces — Consumes:** Tasks 7 and 8.

Behaviour changes:
- File input `accept=".zip"`. `FALLBACK_BACKUP_FILENAME = 'account-backup.zip'`.
- `onFile(file)`: `run.reset()`; if `isOldFormatBackup(file.name)` → set `error` to a client-side `Problem` whose detail is `settings.backup.oldFormat`, stop. Else `openBackupArchive(file)`; on `InvalidBackupArchiveError` → `settings.backup.invalidArchive`; else `previewAccountRestore(await archive.foundation())`. `previewing` covers verification + preview.
- `restore()`: `await run.run(archive)`; `completed` → today's success path (clear file/typed/preview, set `result`, `subs.load()`, `refresh.run(...)`); `stopped` → set `error`, and `failedOnce.set(true)` only when `outcome.wiped`.
- `continueRestore()` → `run.continue()`, same outcome handling.
- Template: while restoring show `<app-progress-hairline [active]="true" [value]="done/total" />` and the text `settings.backup.progress` with `{done, total}`; when `run.canContinue()` show a Continue `app-button` beside the error banner; the result list gains a saved-searches row; the preview's `toLoad` list gains saved searches.
- i18n keys (add to **both** files; German text in plain STE-free normal German):
  - `settings.backup.oldFormat`: "This backup uses an old format. Make a new export on the source instance."
  - `settings.backup.invalidArchive`: "This file is not a complete backup archive."
  - `settings.backup.progress`: "Part {{done}} of {{total}}"
  - `settings.backup.continue`: "Continue"
  - `settings.backup.loaded.savedSearches` / `settings.backup.toLoad.savedSearches` — match the naming of the sibling keys already in the file.
- Read `docs/design-language.md` before touching the template; no hex colours, no ad-hoc px, no media-query literals; styles stay in the `.scss`.

- [ ] **Step 1: Update the component spec first**: old-format file → message, no API call; invalid archive → message, no API call; valid archive → preview called with the foundation blob; restore completed → result shows summed counts and refresh runs; stopped with `wiped: true` → `failedOnce` true and Continue visible; stopped with `wiped: false` → `failedOnce` stays false; Continue calls `run.continue()`. Mock `openBackupArchive` with `jest.mock('./backup-archive', …)` keeping `isOldFormatBackup` and the error class real (`jest.requireActual`).
- [ ] **Step 2–4:** FAIL → implement → `docker compose exec -T frontend npm run check` green (one Jest run at a time).
- [ ] **Step 5: Commit** `feat(#1073): backup section restores part by part with progress`.

---

### Task 10: Docs, real-render verification, PR

**Files:** `docs/backup.md`, memory note.

- [ ] **Step 1: `docs/backup.md`** (it is written in Simplified Technical English — keep it so): §1 "one file" → one zip archive of parts; §2 endpoint and filename; §3 the restore sequence (verify in the browser, preview, start, one request per part, retry/Continue); §4 failure: what a stop after the start means, Continue vs. full re-run; §5 add the header fields table; §8 the container, the member names, the budget, the reader ceilings, the entries-endpoint rules, and the updated guard-test list (`GoldenBackupRestoreTest` fixture directories, the re-homed round-trip test, `EntryPartRestorerTest` retry guard). State once that version 2 files are not readable.
- [ ] **Step 2: Real run on the dev stack** (`docker compose up -d`, containers current, migrations applied — none are added by this plan). Register a **throwaway** account, import a few feeds by OPML, refresh, favourite two entries. Download the backup; `unzip -l` it: members stored, names correct, foundation last. Register a second throwaway account, restore the zip there in the browser pane; watch the network tab: one `start`, then sequential `entries` requests; favourites arrive. Then break it on purpose: stop the php container mid-run (`docker compose stop php`), see three retries and the Continue button, `docker compose start php`, press Continue, run completes. Select a `.json.gz` → old-format message. Purge the throwaway users with `app:e2e:purge-users` if they match its pattern, else leave them and tell Lars.
- [ ] **Step 3: Ask Lars** to export his real account from the source instance and restore it on the prod-like stack (ganesh.local:3333) — this is the acceptance test of #1073 and it is his call, not the executor's. Record per-request duration and peak memory from the php logs if he runs it.
- [ ] **Step 4:** `composer infection:diff` (needs pcov/xdebug; new files must be `git add`ed or it ignores them). Kill or justify escaped mutants on touched lines; do not lower `minMsi`.
- [ ] **Step 5:** Run superpowers:requesting-code-review (an adversarial subagent review has caught real bugs on this repo — do not skip it), fix findings, then push and open the PR into `develop` with `Closes #1073`, body = what changed, the two confirmed spec deviations, the untested `readPartHeader` note, and the verification evidence from Step 2. Do not enable auto-merge. Do not tag or deploy.
- [ ] **Step 6:** Update memory: mark `large-backup-restore-fails-timeout-and-segv.md` superseded by #1073 and add a `1073-split-backup-restore.md` entry + index line.

---

## Self-review notes (done while writing)

- Spec §3 → Tasks 2, 3. §4 → Tasks 1, 2. §5 → Tasks 4, 6. §6 → Task 5. §7 → Tasks 7–9. §8 → Task 6 (+ `restoreAccount` in Task 8). §9 → Global Constraints. §10 → Global Constraints + PR body.
- Names checked across tasks: `parts()`, `BackupPart::{foundation,entries}`, `AccountRestorer::start`, `EntryPartRestorer::load`, `RestoreFeedTargets::for`, `RestoreResult::{ofFoundation,ofEntryPart}`, `entryIdsWithStateOf`, `forUserByEntryIds`, `feedIdsByUrlForUser`, `countInFeedsSubscribedBy`, `openBackupArchive`, `BackupRestoreRun.{run,continue,reset}`.
- Known soft spots the executor must resolve by reading code, not by guessing: the exact existing helper names inside `AccountRestorerTest`, `AccountBackupControllerTest` and `RestoreEntryLoaderTest`; whether `LineField::intOrNull` exists; the ZipStream 3 constructor argument names; how the controller test captures a streamed body.
