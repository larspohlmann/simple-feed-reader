# Backup Drift Guard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make it impossible to add a persisted field to an account-scoped entity without deciding, in code, whether the account backup carries it — and fix the two fields where that decision was already missed.

**Architecture:** A `DbTestCase` walks live Doctrine `ClassMetadata` for the nine entities a backup covers. Every field, embeddable part and association must appear in one of three declarations — `BACKED_UP`, `NOT_BACKED_UP`, `NEVER_BACKED_UP` — or the test fails on an undeclared field. Every `BACKED_UP` field is then proven to appear in real exporter output for a fully populated account. A second test restores two hand-written golden files to guard the read side. `entry.url_hash` is recomputed on restore rather than carried in the file, because it is a pure function of a field the file already holds.

**Tech Stack:** PHP 8.4, Symfony 7.4, Doctrine ORM, PHPUnit. Backend only — no frontend changes.

**Spec:** https://github.com/larspohlmann/simple-feed-reader/issues/556

## Global Constraints

- Every new PHP file starts with `declare(strict_types=1);`.
- PSR-12 via `composer cs` (`composer cs:fix` to autofix).
- PHPStan level max over `src` **and** `tests` — no new baseline entries, no `@phpstan-ignore` without a comment saying why.
- **Every `src` file touched must be PHPMD-clean before commit** (`composer md`), not merely free of new findings.
- `composer tramp` must stay green — a parameter forwarded through 4+ methods that none of them read fails the build.
- Clean Code is mandatory: `final readonly` with constructor promotion, guard clauses, names that reveal intent, comments that explain *why*.
- Tests are production code — same naming and structure standards as `src`.
- Commit message format is `type(#556): summary`. The issue number is the scope; never a word scope, never trailing parens.
- Branch: `feature/556-backup-drift-guard`, cut from `develop`. The PR body says `Closes #556`.
- Run both suite legs before the PR: `php bin/phpunit` (SQLite) and `docker compose exec php vendor/bin/phpunit` (MySQL).
- Scan `backend/var/log/dev.log` after backend work for deprecations and swallowed errors.
- `composer infection:diff` gates the files this branch touches. `minMsi` in `infection.json5` is a ratchet — never lower it.

## Deviation from the design session, stated openly

The session settled on "field-by-field decisions for everything account-scoped, including the wholly dropped entities". Taken literally that is roughly sixty reason strings for six entities that are dropped in their entirety (`AiProviderSettings`, `UserIdentity`, `ActionToken`, `RecommendationRun`, `RecommendationRunLog`, `RecommendationItem`).

This plan declares those six at **entity** granularity instead, and adds a test — `testAWhollyDroppedEntityExportsNothing` — that fails the moment any of their fields starts appearing in exporter output. The guard strength is unchanged for the failure mode being fixed: adding a field to an entity that exports nothing cannot reproduce the `url_hash` bug, because there is no partial projection for the field to fall out of. If the entity ever becomes partly backed up, the whole-entity declaration becomes invalid and the test says so.

Reverting to literal field-by-field is a mechanical change: move the entity from `ACCOUNT_SCOPED_WHOLLY_DROPPED` into `NOT_BACKED_UP` and list its fields.

## File Structure

**Created**

| Path | Responsibility |
|---|---|
| `backend/tests/Support/FullyPopulatedAccount.php` | Seeds one account with every backed-up field non-null. Shared by the drift test and future backup tests. |
| `backend/tests/Service/Backup/BackupSchemaCoverageTest.php` | The drift guard: metadata walk, three declarations, write proof, doc coupling. |
| `backend/tests/Service/Backup/GoldenBackupRestoreTest.php` | Restores the committed corpus into a clean account. |
| `backend/tests/Fixtures/backup/oldest-supported.ndjson` | Hand-written file omitting every additive field added since #412 shipped. |
| `backend/tests/Fixtures/backup/current.ndjson` | Hand-written file carrying every field the format holds today. |
| `docs/backup.md` | What a backup preserves, what it drops, and why. |

**Modified**

| Path | Change |
|---|---|
| `backend/src/Service/Url/UrlNormalizer.php` | Gains `hash()` beside `normalize()`. |
| `backend/src/Service/Ingest/EntryIngestor.php` | Its private `urlHash()` delegates to the new shared method. |
| `backend/src/Service/Backup/EntryBatchInserter.php` | Writes `url_hash`, recomputed from the line's `url`. |
| `backend/src/Service/Backup/AccountBackupExporter.php` | Exports `profileText`. |
| `backend/src/Service/Backup/Dto/AccountLine.php` | Reads `profileText`, tolerating its absence. |

The fixtures are committed as **plain NDJSON**, not `.gz`. The restore API takes gzip bytes, so the tests call `gzencode()` on read. A committed binary would be unreviewable in a diff, and the whole point of a hand-written fixture is that a human can see what it omits.

---

### Task 1: A shared, canonical URL hash

**Files:**
- Modify: `backend/src/Service/Url/UrlNormalizer.php`
- Modify: `backend/src/Service/Ingest/EntryIngestor.php:225-230`
- Test: `backend/tests/Service/Url/UrlNormalizerTest.php`

**Interfaces:**
- Produces: `UrlNormalizer::hash(?string $url): ?string` — the sha256 of `normalize($url)`, or `null` when the URL does not normalise. Task 2 consumes it.

**Why this is not premature DRY:** the house rule refactors on the third occurrence, and this is the second. The rule assumes divergence is visible. Two writers of the same dedupe key that drift apart produce no error at all — dedupe simply stops working. That is the exact failure class this whole issue is about.

- [ ] **Step 1: Write the failing test**

Append to `backend/tests/Service/Url/UrlNormalizerTest.php`:

```php
    public function testHashesTheNormalisedFormSoTrackingParametersDoNotChangeIt(): void
    {
        $normalizer = new UrlNormalizer();

        $plain = $normalizer->hash('https://example.com/article');
        $decorated = $normalizer->hash('https://EXAMPLE.com:443/article?utm_source=rss#top');

        self::assertSame($plain, $decorated);
        self::assertSame(hash('sha256', 'https://example.com/article'), $plain);
    }

    public function testHashesNullWhenTheUrlDoesNotNormalise(): void
    {
        $normalizer = new UrlNormalizer();

        self::assertNull($normalizer->hash(null));
        self::assertNull($normalizer->hash(''));
        self::assertNull($normalizer->hash('not a url'));
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd backend && php bin/phpunit tests/Service/Url/UrlNormalizerTest.php
```

Expected: FAIL — `Call to undefined method App\Service\Url\UrlNormalizer::hash()`.

- [ ] **Step 3: Add the method**

In `backend/src/Service/Url/UrlNormalizer.php`, directly below `normalize()`:

```php
    /**
     * The stable identity of an article URL: sha256 over the normalised form.
     *
     * Shared rather than duplicated because two writers of this value must
     * agree byte for byte. A divergence would not raise anything — dedupe
     * would simply stop matching, silently (#556).
     */
    public function hash(?string $url): ?string
    {
        $normalized = $this->normalize($url);

        return $normalized === null ? null : hash('sha256', $normalized);
    }
```

- [ ] **Step 4: Point the ingest path at it**

In `backend/src/Service/Ingest/EntryIngestor.php`, replace the private helper:

```php
    private function urlHash(?string $url): ?string
    {
        return $this->urlNormalizer->hash($url);
    }
```

- [ ] **Step 5: Run the tests to verify they pass**

```bash
cd backend && php bin/phpunit tests/Service/Url tests/Service/Ingest
```

Expected: PASS, with no change in the ingest suite's assertions.

- [ ] **Step 6: Lint the touched files**

```bash
cd backend && composer cs && composer stan && composer md
```

Expected: no findings in `UrlNormalizer.php` or `EntryIngestor.php`.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Url/UrlNormalizer.php backend/src/Service/Ingest/EntryIngestor.php backend/tests/Service/Url/UrlNormalizerTest.php
git commit -m "refactor(#556): one canonical url hash, shared by ingest and restore"
```

---

### Task 2: A restore repopulates `entry.url_hash`

**Files:**
- Modify: `backend/src/Service/Backup/EntryBatchInserter.php`
- Test: `backend/tests/Service/Backup/EntryBatchInserterTest.php`

**Interfaces:**
- Consumes: `UrlNormalizer::hash()` from Task 1.
- Produces: nothing new. `EntryBatchInserter::__construct()` gains a second promoted parameter, `private UrlNormalizer $urlNormalizer`. It is autowired; no service configuration changes.

**Background the implementer needs:** #484 added `entry.url_hash` as the stable dedupe key for feeds with volatile GUIDs. The migration created it `VARCHAR(64) DEFAULT NULL`, and #484 deliberately declined to backfill rows that predate it. A restore writes *new* rows, so populating them is not a backdoor around that decision — it is the same thing ingest does for every row it creates. Say so in the commit message, because it brushes against a recorded call.

The value is recomputed, never carried in the file. It is a pure function of `url`, which the file already holds; carrying derived data is how a format accumulates fields it can never drop.

- [ ] **Step 1: Write the failing test**

Append to `backend/tests/Service/Backup/EntryBatchInserterTest.php`. Follow the existing file's setup for obtaining the inserter and a feed id; the assertion is the new part:

```php
    public function testRecomputesTheStableUrlHashForEveryInsertedRow(): void
    {
        $feedId = $this->createFeed('https://hash.example/feed.xml');
        $this->inserter()->insert($feedId, [
            $this->entryLine(guid: 'a', url: 'https://hash.example/one?utm_source=rss'),
            $this->entryLine(guid: 'b', url: null),
        ]);

        $rows = $this->em->getConnection()->fetchAllAssociative(
            'SELECT guid, url_hash FROM entry WHERE feed_id = ? ORDER BY guid',
            [$feedId],
        );

        self::assertSame(
            hash('sha256', 'https://hash.example/one'),
            $rows[0]['url_hash'],
            'A decorated URL must hash to its normalised form, exactly as ingest hashes it.',
        );
        self::assertNull($rows[1]['url_hash'], 'A url-less entry dedupes on guid alone.');
    }
```

If `entryLine()` and `createFeed()` helpers do not already exist in that test class, add them mirroring the seeding the file's existing methods do — do not invent a new style.

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd backend && php bin/phpunit tests/Service/Backup/EntryBatchInserterTest.php
```

Expected: FAIL — `url_hash` is NULL for the first row, because the column is not in the INSERT.

- [ ] **Step 3: Write the implementation**

In `backend/src/Service/Backup/EntryBatchInserter.php`:

```php
    private const array COLUMNS = [
        'feed_id', 'guid', 'guid_hash', 'url', 'url_hash', 'title', 'author',
        'summary', 'content_html', 'image_url', 'image_width', 'image_height',
        'published_at', 'created_at', 'effective_date',
    ];

    public function __construct(
        private Connection $connection,
        private UrlNormalizer $urlNormalizer,
    ) {
    }
```

and in `row()`:

```php
        return [
            $feedId, $line->guid, $line->guidHash, $line->url,
            $this->urlNormalizer->hash($line->url), $line->title,
            $line->author, $line->summary, $line->contentHtml, $line->imageUrl,
            $line->imageWidth, $line->imageHeight,
            self::storageDate($line->publishedAt),
            self::storageDate($line->createdAt),
            self::storageDate($line->effectiveDate),
        ];
```

Amend the class docblock. It currently says guid_hash travels in the file because no Entry constructor runs here. Add the counterpart:

```
 * url_hash goes the other way: it is a pure function of a field the file
 * already carries, so it is recomputed here rather than stored. Carrying
 * derived data is how a format grows fields it can never drop (#556).
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
cd backend && php bin/phpunit tests/Service/Backup
```

Expected: PASS.

- [ ] **Step 5: Lint**

```bash
cd backend && composer cs && composer stan && composer md && composer tramp
```

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Backup/EntryBatchInserter.php backend/tests/Service/Backup/EntryBatchInserterTest.php
git commit -m "fix(#556): a restore repopulates entry.url_hash instead of leaving it null

#484 declined to backfill pre-existing rows. A restore writes new rows, so
it hashes them the same way ingest does; the declined backfill is untouched."
```

---

### Task 3: The account line carries `profileText`

**Files:**
- Modify: `backend/src/Service/Backup/AccountBackupExporter.php:188-212`
- Modify: `backend/src/Service/Backup/Dto/AccountLine.php:36-58`
- Test: `backend/tests/Service/Backup/AccountBackupExporterTest.php`
- Test: `backend/tests/Service/Backup/Dto/AccountLineTest.php`

**Interfaces:**
- Produces: the account line's `recommendationSettings` object gains a `profileText` key, `string|null`. Additive, so `BackupSchema::VERSION` stays `1`.

**Why export it, when `url_hash` is recomputed:** both are derived, but the derivations are not comparable. `url_hash` is a pure function costing microseconds. `profileText` is distilled by `RecommendationProfileDistiller` through a paid, non-deterministic model call that does not happen on restore. Losing it is real.

`RecommendationSettings::update()` and `::values()` already carry `profileText` through `RecommendationSettingsValues`. Only the two hand-written JSON mappings are missing it.

- [ ] **Step 1: Write the failing export test**

Append to `backend/tests/Service/Backup/AccountBackupExporterTest.php`:

```php
    public function testExportsTheStoredPreferenceProfileOnTheAccountLine(): void
    {
        $user = $this->makeUser('profile-export@example.com');
        $settings = new RecommendationSettings($user);
        $settings->update(new RecommendationSettingsValues(
            guidancePrompt: null,
            favoritesCap: 40,
            keptCap: 40,
            viewedCap: 80,
            candidatePoolSize: 1000,
            lookbackDays: 2,
            picksLimit: 50,
            contextWindow: null,
            batchCount: null,
            debugEnabled: false,
            profileText: 'Reads long-form essays about urban planning.',
        ));
        $this->em->persist($settings);
        $this->em->flush();

        $accountLine = $this->decodedLines($user)[1];

        self::assertIsArray($accountLine['recommendationSettings']);
        self::assertSame(
            'Reads long-form essays about urban planning.',
            $accountLine['recommendationSettings']['profileText'],
        );
    }
```

- [ ] **Step 2: Write the failing import test**

Append to `backend/tests/Service/Backup/Dto/AccountLineTest.php`:

```php
    public function testReadsTheStoredPreferenceProfile(): void
    {
        $line = AccountLine::fromLine([
            'kind' => 'account',
            'locale' => 'en',
            'scrapeFallbackEnabled' => false,
            'recommendationSettings' => self::settingsFields(['profileText' => 'Likes cartography.']),
        ]);

        self::assertSame('Likes cartography.', $line->recommendationSettings?->profileText);
    }

    public function testTreatsAnAbsentPreferenceProfileAsNull(): void
    {
        $line = AccountLine::fromLine([
            'kind' => 'account',
            'locale' => 'en',
            'scrapeFallbackEnabled' => false,
            'recommendationSettings' => self::settingsFields([]),
        ]);

        self::assertNull($line->recommendationSettings?->profileText);
    }
```

`self::settingsFields()` is a helper returning the complete required settings object with the given overrides merged in. If the test class has no such helper, add it — a per-test literal of twelve keys repeated twice is the duplication the house rule forbids.

- [ ] **Step 3: Run both tests to verify they fail**

```bash
cd backend && php bin/phpunit tests/Service/Backup/AccountBackupExporterTest.php tests/Service/Backup/Dto/AccountLineTest.php
```

Expected: the export test fails with an undefined array key `profileText`; the first import test fails asserting `null` is `'Likes cartography.'`.

- [ ] **Step 4: Write the implementation**

In `AccountBackupExporter::recommendationSettingsFields()`, add after `'guidancePrompt'`:

```php
            'profileText' => $values->profileText,
```

In `AccountLine::recommendationSettingsFromLine()`, add after `guidancePrompt`:

```php
            profileText: LineField::stringOrNull($settings, 'profileText'),
```

`stringOrNull()` already returns `null` for an absent key, so a file written before this ships imports cleanly. No `withDefault` variant is needed.

- [ ] **Step 5: Run the tests to verify they pass**

```bash
cd backend && php bin/phpunit tests/Service/Backup
```

Expected: PASS.

- [ ] **Step 6: Lint**

```bash
cd backend && composer cs && composer stan && composer md
```

Watch for a PHPMD `ExcessiveMethodLength` finding on `recommendationSettingsFields()` — it is a flat data mapping and now thirteen keys long. If it trips, extract the mapping rather than raising the threshold.

- [ ] **Step 7: Commit**

```bash
git add backend/src/Service/Backup backend/tests/Service/Backup
git commit -m "fix(#556): carry the inferred preference profile through a backup"
```

---

### Task 4: The drift guard

**Files:**
- Create: `backend/tests/Support/FullyPopulatedAccount.php`
- Create: `backend/tests/Service/Backup/BackupSchemaCoverageTest.php`

**Interfaces:**
- Consumes: `AccountBackupExporter::lines()`, `EntityManagerInterface::getClassMetadata()`.
- Produces: `FullyPopulatedAccount::__construct(EntityManagerInterface $em, UserPasswordHasherInterface $hasher)` and `create(string $email): User` — an account with every `BACKED_UP` field set to a non-null, non-default value. Task 5 does not use it; future backup tests should.

**Why the seed must be fully populated:** the write proof looks for a key in exporter output. A field left null would still be exported as `null`, so the key would be present and the proof would pass — but a *dropped mapping* would also produce a missing key only when the value is non-null in some encoders. Seeding non-null values keeps the proof honest and makes the fixture double as a round-trip subject later.

- [ ] **Step 1: Write the seed helper**

Create `backend/tests/Support/FullyPopulatedAccount.php`. Model its constructor and style on `UserFactory`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\RecommendationSettings;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Recommendation\RecommendationSettingsValues;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * One account with every field a backup carries set to a non-null, non-default
 * value.
 *
 * BackupSchemaCoverageTest proves each declared field reaches the exporter's
 * output, and a null field would prove nothing. When that test fails because a
 * new field was added, populating it here is part of the fix (#556).
 */
final readonly class FullyPopulatedAccount
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
    ) {
    }

    public function create(string $email): User
    {
        $user = (new UserFactory($this->em, $this->hasher))->create($email, locale: 'de');
        $user->getPreferences()->setScrapeFallbackEnabled(true);

        $this->em->persist($this->settingsFor($user));
        $this->em->persist($this->tagFor($user));

        $feed = $this->feed();
        $this->em->persist($feed);
        $this->em->persist(new Subscription($user, $feed));

        $entry = $this->entryFor($feed);
        $this->em->persist($entry);
        $this->em->persist($this->stateFor($user, $entry));

        $this->em->flush();

        return $user;
    }
```

Then one small private factory per entity, each setting **every** backed-up field. Keep each under ten lines; extract rather than let one method carry all five. The exact setter names come from the entities themselves — read them, do not guess. `Feed` needs `setTitle`, `setSiteUrl`, `setDescription`, `setFaviconUrl` and a non-default `sourceFormat`; `Entry` needs a title, author, summary, content HTML and the three image fields plus all three dates; `EntryState` needs both `isRead` and `isViewed` set; `Tag` needs a colour, an icon and a position; `RecommendationSettings::update()` takes a `RecommendationSettingsValues` with every parameter non-null, `profileText` and `showReasons` included.

- [ ] **Step 2: Write the drift test's declarations and the first assertion**

Create `backend/tests/Service/Backup/BackupSchemaCoverageTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup;

use App\Entity\ActionToken;
use App\Entity\AiProviderSettings;
use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\InstanceSetting;
use App\Entity\Preferences;
use App\Entity\ProxyServerSettings;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\RecommendationRunLog;
use App\Entity\RecommendationSettings;
use App\Entity\Subscription;
use App\Entity\SubscriptionTag;
use App\Entity\Tag;
use App\Entity\User;
use App\Entity\UserIdentity;
use App\Entity\WorkerHeartbeat;
use App\Service\Backup\AccountBackupExporter;
use App\Tests\DbTestCase;
use App\Tests\Support\FullyPopulatedAccount;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Couples the backup format to the ORM schema, which nothing else does.
 *
 * The backup is a hand-maintained projection: an exporter that writes named
 * JSON keys, and line DTOs that read them. A column added to a backed-up
 * table reaches neither unless a person remembers. Three schema changes
 * landed on backed-up tables in the six days after the format shipped and two
 * of them were missed, silently, because a nullable column accepts an INSERT
 * that never names it (#556).
 *
 * The guard is deliberately narrow in one way: it reads the ORM mapping, so a
 * migration that adds a column with no entity mapping is invisible to it.
 * Everything in this tree is attribute-mapped, and an unmapped column holds no
 * user data by construction.
 */
final class BackupSchemaCoverageTest extends DbTestCase
{
    /**
     * Every entity's surrogate key. A backup is identity-free by design — the
     * restore assigns new ids — so listing `id` nine times would be noise.
     */
    private const array UNIVERSALLY_SKIPPED = ['id'];

    /**
     * Rows that belong to the instance, not to any one account. A field added
     * to one of these cannot produce the failure this test guards against,
     * because no per-account projection of them exists.
     */
    private const array INSTANCE_SCOPED = [
        InstanceSetting::class => 'Instance-wide configuration, identical for every account.',
        ProxyServerSettings::class => 'The instance\'s egress configuration (#490); an operator setting.',
        CatalogCategory::class => 'The shared discovery catalog, seeded per instance.',
        CatalogFeed::class => 'The shared discovery catalog, seeded per instance.',
        WorkerHeartbeat::class => 'Liveness telemetry for the refresh worker.',
    ];

    /**
     * Account-scoped and dropped in full. Declared per entity rather than per
     * field: see the plan\'s stated deviation. testAWhollyDroppedEntityExportsNothing()
     * is what keeps that shorthand honest.
     */
    private const array ACCOUNT_SCOPED_WHOLLY_DROPPED = [
        AiProviderSettings::class => 'Model endpoints and API keys. A backup is a file the '
            . 'user handles and mails around; credentials do not belong in one.',
        UserIdentity::class => 'OAuth identity links — see NEVER_BACKED_UP\'s reasoning.',
        ActionToken::class => 'Short-lived, single-use verification and reset tokens.',
        RecommendationRun::class => 'Run history. Regenerated by running the engine; large, and '
            . 'meaningless once its entries are gone.',
        RecommendationRunLog::class => 'Per-run diagnostics, tied to a run that is not restored.',
        RecommendationItem::class => 'Per-run picks, tied to a run that is not restored.',
    ];
```

- [ ] **Step 3: Add the three per-field declarations**

Still in the same class. `BACKED_UP` maps a Doctrine field or association name to the JSON key it becomes; a dotted key means a nested object on the line.

```php
    /** Doctrine class => [field or association => exported JSON key]. */
    private const array BACKED_UP = [
        User::class => ['locale' => 'locale'],
        Preferences::class => ['scrapeFallbackEnabled' => 'scrapeFallbackEnabled'],
        RecommendationSettings::class => [
            'guidancePrompt' => 'recommendationSettings.guidancePrompt',
            'profileText' => 'recommendationSettings.profileText',
            'favoritesCap' => 'recommendationSettings.favoritesCap',
            'keptCap' => 'recommendationSettings.keptCap',
            'viewedCap' => 'recommendationSettings.viewedCap',
            'candidatePoolSize' => 'recommendationSettings.candidatePoolSize',
            'lookbackDays' => 'recommendationSettings.lookbackDays',
            'picksLimit' => 'recommendationSettings.picksLimit',
            'contextWindow' => 'recommendationSettings.contextWindow',
            'batchCount' => 'recommendationSettings.batchCount',
            'debugEnabled' => 'recommendationSettings.debugEnabled',
            'autoGenerateIntervalHours' => 'recommendationSettings.autoGenerateIntervalHours',
            'showReasons' => 'recommendationSettings.showReasons',
        ],
        Tag::class => [
            'name' => 'name', 'color' => 'color', 'icon' => 'icon', 'position' => 'position',
        ],
        Feed::class => [
            'url' => 'url', 'siteUrl' => 'siteUrl', 'title' => 'title',
            'description' => 'description', 'faviconUrl' => 'faviconUrl',
            'sourceFormat' => 'sourceFormat',
        ],
        Entry::class => [
            'feed' => 'feedUrl',
            'guid' => 'guid', 'guidHash' => 'guidHash', 'url' => 'url',
            'title' => 'title', 'author' => 'author', 'summary' => 'summary',
            'contentHtml' => 'contentHtml',
            'image.url' => 'imageUrl', 'image.width' => 'imageWidth',
            'image.height' => 'imageHeight',
            'publishedAt' => 'publishedAt', 'createdAt' => 'createdAt',
            'effectiveDate' => 'effectiveDate',
        ],
        // Fill Subscription, SubscriptionTag and EntryState the same way, from
        // their own line DTOs. Do not guess a key name — read the exporter.
    ];

    /** Doctrine class => [field => why a backup leaves it behind]. */
    private const array NOT_BACKED_UP = [
        Entry::class => [
            'urlHash' => 'Derived: sha256 of UrlNormalizer::normalize(url), which the file '
                . 'already carries. EntryBatchInserter recomputes it on restore, so it is '
                . 'never stale and never has to be dropped from the format later.',
        ],
        Feed::class => [
            'status' => 'Live fetch state, not the user\'s data. A restored feed starts clean.',
            'fetchSchedule.intervalMinutes' => 'A restored feed gets a virgin schedule and is '
                . 'refreshed immediately by the client — same rule as the OPML import.',
            // …one line per remaining FetchSchedule field and per Feed field
            // that the feed line does not carry.
        ],
        // …User's non-security fields, EntryState's, and so on.
    ];

    /**
     * Security boundaries, not product decisions. Moving a field out of this
     * list is never a fix for a red test.
     *
     * `roles` is the sharp one: a restore writes what the file says, and a
     * backup is a file the user supplies. If roles were restorable, any
     * account holder could hand-edit one line and grant themselves
     * ROLE_ADMIN. Restorable identity links would let a user attach someone
     * else's OAuth identity to their own account, and a restorable email
     * would let them move onto an address they do not control.
     */
    private const array NEVER_BACKED_UP = [
        User::class => [
            'roles' => 'Privilege escalation: a hand-edited backup would grant ROLE_ADMIN.',
            'email' => 'Account identity; a restore must never move an account to another address.',
            'password' => 'Credential material.',
        ],
    ];
```

- [ ] **Step 4: Write the metadata walk**

```php
    public function testEveryPersistedFieldOfACoveredEntityCarriesADecision(): void
    {
        foreach (array_keys(self::BACKED_UP) as $entityClass) {
            foreach ($this->persistedNames($entityClass) as $name) {
                self::assertTrue(
                    $this->isDeclared($entityClass, $name),
                    sprintf(
                        "%s::$%s is persisted but no backup decision exists for it.\n"
                        . 'Add it to BACKED_UP, NOT_BACKED_UP or NEVER_BACKED_UP in %s, '
                        . 'and add a row to docs/backup.md.',
                        $entityClass,
                        $name,
                        self::class,
                    ),
                );
            }
        }
    }

    /** @return list<string> field names, embeddable parts and associations */
    private function persistedNames(string $entityClass): array
    {
        $metadata = $this->em->getClassMetadata($entityClass);
        $names = array_merge($metadata->getFieldNames(), $metadata->getAssociationNames());

        return array_values(array_diff($names, self::UNIVERSALLY_SKIPPED));
    }

    private function isDeclared(string $entityClass, string $name): bool
    {
        foreach ([self::BACKED_UP, self::NOT_BACKED_UP, self::NEVER_BACKED_UP] as $declarations) {
            if (isset($declarations[$entityClass][$name])) {
                return true;
            }
        }

        return false;
    }
```

Note that `getFieldNames()` returns embeddable parts dotted — `image.url`, `fetchSchedule.intervalMinutes` — which is why the declarations above use that spelling. Do not strip the prefix.

- [ ] **Step 5: Write the write proof**

```php
    public function testEveryBackedUpFieldReachesTheExportersOutput(): void
    {
        $keysByKind = $this->exportedKeysByKind('coverage@example.com');

        foreach (self::BACKED_UP as $entityClass => $fields) {
            $kind = self::KIND_OF[$entityClass];
            foreach ($fields as $field => $exportedKey) {
                self::assertContains(
                    $exportedKey,
                    $keysByKind[$kind] ?? [],
                    sprintf(
                        '%s::$%s is declared BACKED_UP as "%s" on the %s line, but the '
                        . 'exporter never writes that key.',
                        $entityClass,
                        $field,
                        $exportedKey,
                        $kind,
                    ),
                );
            }
        }
    }

    public function testAWhollyDroppedEntityExportsNothing(): void
    {
        $exportedKeys = array_merge(...array_values($this->exportedKeysByKind('dropped@example.com')));

        foreach (array_keys(self::ACCOUNT_SCOPED_WHOLLY_DROPPED) as $entityClass) {
            foreach ($this->persistedNames($entityClass) as $name) {
                self::assertNotContains(
                    $name,
                    $exportedKeys,
                    sprintf(
                        '%s is declared wholly dropped, but "%s" appears in the export. '
                        . 'It is now partly backed up: move it to BACKED_UP and '
                        . 'NOT_BACKED_UP, field by field.',
                        $entityClass,
                        $name,
                    ),
                );
            }
        }
    }
```

`KIND_OF` is a `private const array` mapping each covered entity class to its `BackupSchema::KIND_*` value. `exportedKeysByKind()` seeds a `FullyPopulatedAccount`, runs `AccountBackupExporter::lines()`, decodes each line, and returns `kind => list<string>` of keys — flattening one level so a nested object contributes `recommendationSettings.profileText`.

- [ ] **Step 6: Run the test**

```bash
cd backend && php bin/phpunit tests/Service/Backup/BackupSchemaCoverageTest.php
```

Expected: PASS. It will not pass on the first run — expect several rounds of "field X carries no decision" as the metadata walk surfaces fields this plan did not enumerate. That is the test working. Write a real reason for each; do not batch them under one generic string.

- [ ] **Step 7: Prove the guard actually catches the bug it was written for**

Temporarily delete `'url_hash',` from `EntryBatchInserter::COLUMNS` and the matching value from `row()`, then:

```bash
cd backend && php bin/phpunit tests/Service/Backup
```

Expected: `EntryBatchInserterTest` fails. Now temporarily move `Entry::class => ['urlHash' => …]` from `NOT_BACKED_UP` into `BACKED_UP` as `'urlHash' => 'urlHash'` and re-run — expect `testEveryBackedUpFieldReachesTheExportersOutput` to fail naming the missing key. **Revert both edits.** A guard whose first green run is also its only run has never been tested.

- [ ] **Step 8: Lint and commit**

```bash
cd backend && composer cs && composer stan && composer md
git add backend/tests/Support/FullyPopulatedAccount.php backend/tests/Service/Backup/BackupSchemaCoverageTest.php
git commit -m "test(#556): couple the backup format to the ORM schema"
```

---

### Task 5: The golden corpus

**Files:**
- Create: `backend/tests/Fixtures/backup/oldest-supported.ndjson`
- Create: `backend/tests/Fixtures/backup/current.ndjson`
- Create: `backend/tests/Service/Backup/GoldenBackupRestoreTest.php`

**Interfaces:**
- Consumes: `AccountRestorer` (or whichever service `AccountBackupController` calls for a restore — read the controller and use the same entry point, not a lower layer).

**What the corpus is for:** Task 4 guards the *write* side. This guards the *read* side — a future change that makes a field required would reject files that predate it, and nothing else would notice. `oldest-supported.ndjson` omits every additive field added since #412 shipped: `showReasons` and `profileText`.

**The standing rule, which belongs in both fixture files as a comment and in `docs/backup.md`:** when a PR adds an additive field, it adds **nothing** to this corpus. `oldest-supported` already lacks the field, and that absence is the test. A third fixture appears only when support for something is first *dropped*.

**Fixtures are plain NDJSON, not gzip.** The tests gzip on read. A committed `.gz` is unreviewable, and the entire value of a hand-written fixture is that a reviewer can see what it omits.

- [ ] **Step 1: Write `oldest-supported.ndjson`**

Six lines, no trailing blank line. Every count in the footer must match, or `BackupReader` refuses the file.

```
{"kind":"header","schemaVersion":1,"createdAt":"2026-08-17T11:03:57+00:00","sourceUrl":"https://oldest.example","sourceEmail":"oldest@example.com"}
{"kind":"account","locale":"de","scrapeFallbackEnabled":true,"recommendationSettings":{"guidancePrompt":"Long reads only.","favoritesCap":40,"keptCap":40,"viewedCap":80,"candidatePoolSize":1000,"lookbackDays":2,"picksLimit":50,"contextWindow":null,"batchCount":null,"debugEnabled":true,"autoGenerateIntervalHours":24}}
{"kind":"tag","name":"Cartography","color":"#2b6","icon":"map","position":0}
{"kind":"feed","url":"https://oldest.example/feed.xml","siteUrl":"https://oldest.example","title":"Oldest","description":"A fixture feed.","faviconUrl":null,"sourceFormat":"xml"}
{"kind":"subscription","feedUrl":"https://oldest.example/feed.xml","tags":[{"name":"Cartography","position":0}]}
{"kind":"entry","feedUrl":"https://oldest.example/feed.xml","guid":"oldest-1","guidHash":"…","url":"https://oldest.example/one?utm_source=rss","title":"One","author":"A","summary":"S","contentHtml":"<p>S</p>","imageUrl":null,"imageWidth":null,"imageHeight":null,"publishedAt":"2026-08-16T09:00:00+00:00","createdAt":"2026-08-17T09:00:00+00:00","effectiveDate":"2026-08-16T09:00:00+00:00"}
{"kind":"footer","counts":{"tag":1,"feed":1,"subscription":1,"entry":1,"entryState":0}}
```

Replace `"…"` with the real value of `hash('sha256', 'oldest-1')`. Verify the subscription line's `tags` shape against `SubscriptionLine::fromLine()` before committing — read it, do not trust this sketch.

- [ ] **Step 2: Write `current.ndjson`**

The same account, plus `"profileText"` and `"showReasons"` in `recommendationSettings`, plus one `entryState` line and a matching footer count. Read `EntryStateLine` for its exact keys.

- [ ] **Step 3: Write the failing test**

```php
final class GoldenBackupRestoreTest extends DbTestCase
{
    /**
     * Committed backup files, restored on every run. Task 4 guards what the
     * exporter writes; this guards what the reader still accepts. A field made
     * required tomorrow would reject every file written before today, and only
     * a frozen file can catch that (#556).
     *
     * @return iterable<string, array{string, int, int}>
     */
    public static function corpus(): iterable
    {
        yield 'a file written before showReasons and profileText existed' =>
            ['oldest-supported.ndjson', 1, 0];
        yield 'a file carrying every field the format holds today' =>
            ['current.ndjson', 1, 1];
    }

    #[DataProvider('corpus')]
    public function testRestoresACommittedBackup(string $fixture, int $entries, int $states): void
    {
        $user = $this->makeUser('golden@example.com');
        $gzip = gzencode((string) file_get_contents(__DIR__ . '/../../Fixtures/backup/' . $fixture));

        $result = $this->restorer()->restore($user, (string) $gzip);

        self::assertSame(1, $result->tags);
        self::assertSame(1, $result->subscriptions);
        self::assertSame($entries, $result->entries);
        self::assertSame($states, $result->entryStates);
    }

    public function testAFileWrittenBeforeAFieldExistedRestoresWithThatFieldsDefault(): void
    {
        $user = $this->makeUser('golden-defaults@example.com');
        $gzip = gzencode((string) file_get_contents(__DIR__ . '/../../Fixtures/backup/oldest-supported.ndjson'));

        $this->restorer()->restore($user, (string) $gzip);
        $this->em->clear();

        $settings = $this->settingsFor($user);
        self::assertFalse($settings->values()->showReasons);
        self::assertNull($settings->values()->profileText);
    }
}
```

Check `AccountRestorer`'s real method name and signature before writing `restore()`. If the restore needs a confirmation phrase or goes through a `RestoreLoader`, use whatever `AccountBackupController` uses.

- [ ] **Step 4: Run the test**

```bash
cd backend && php bin/phpunit tests/Service/Backup/GoldenBackupRestoreTest.php
```

Expected: PASS. A footer-count mismatch or a wrong `guidHash` fails loudly with the reader's own message — fix the fixture, never the reader.

- [ ] **Step 5: Commit**

```bash
git add backend/tests/Fixtures/backup backend/tests/Service/Backup/GoldenBackupRestoreTest.php
git commit -m "test(#556): restore a frozen corpus so old backups keep importing"
```

---

### Task 6: `docs/backup.md`, coupled to the declarations

**Files:**
- Create: `docs/backup.md`
- Modify: `backend/tests/Service/Backup/BackupSchemaCoverageTest.php`

**Interfaces:**
- Consumes: `NOT_BACKED_UP`, `NEVER_BACKED_UP` and `ACCOUNT_SCOPED_WHOLLY_DROPPED` from Task 4.

The test asserts the doc **mentions** every dropped thing. It does not generate prose. Reason strings stay terse and developer-facing; the doc is written for a person about to press REPLACE.

- [ ] **Step 1: Write the doc**

Create `docs/backup.md` with: what a backup is and how to make one; the replace-only semantics of a restore; a **What a backup carries** table; a **What a backup does not carry** table with one row per dropped entity or field, each naming the thing in backticks and giving a plain-language reason; the security note about `roles` and OAuth identities; and the additive-field rule from Task 5 (a new additive field adds nothing to the corpus). Link it from `docs/architecture.md`'s document index if one exists.

Write it in ASD-STE100 Simplified Technical English, like the rest of `docs/`.

- [ ] **Step 2: Write the failing coupling assertion**

Append to `BackupSchemaCoverageTest`:

```php
    /**
     * The reason strings above are for whoever hits a red test. This asserts
     * the user-facing table cannot silently fall behind them — the coupling is
     * mechanical, the wording stays hand-written.
     */
    public function testEveryDroppedThingAppearsInTheUserFacingDoc(): void
    {
        $doc = (string) file_get_contents(__DIR__ . '/../../../../docs/backup.md');

        foreach (array_keys(self::ACCOUNT_SCOPED_WHOLLY_DROPPED) as $entityClass) {
            $shortName = (new \ReflectionClass($entityClass))->getShortName();
            self::assertStringContainsString(
                $shortName,
                $doc,
                sprintf('docs/backup.md never mentions %s, which a backup drops.', $shortName),
            );
        }

        foreach ([self::NOT_BACKED_UP, self::NEVER_BACKED_UP] as $declarations) {
            foreach ($declarations as $entityClass => $fields) {
                foreach (array_keys($fields) as $field) {
                    self::assertStringContainsString(
                        '`' . $field . '`',
                        $doc,
                        sprintf(
                            'docs/backup.md has no row for %s::$%s, which a backup drops.',
                            $entityClass,
                            $field,
                        ),
                    );
                }
            }
        }
    }
```

Verify the relative path to `docs/backup.md` from the test file resolves; adjust the number of `..` segments rather than hard-coding an absolute path.

- [ ] **Step 3: Run it and fill the gaps**

```bash
cd backend && php bin/phpunit tests/Service/Backup/BackupSchemaCoverageTest.php
```

Expected: FAIL, once per field the doc has no row for. Add each row. This is the pass that turns the developer-facing list into something a user can read.

- [ ] **Step 4: Full verification**

```bash
cd backend && composer check && php bin/phpunit && composer infection:diff
```

```bash
docker compose exec php vendor/bin/phpunit
```

Then read `backend/var/log/dev.log` for deprecations.

- [ ] **Step 5: Commit and open the PR**

```bash
git add docs/backup.md backend/tests/Service/Backup/BackupSchemaCoverageTest.php
git commit -m "docs(#556): what a backup carries, and what it deliberately drops"
git push -u origin feature/556-backup-drift-guard
gh pr create --base develop --title "Guard the backup format against silent schema drift" --body "Closes #556"
```

---

## Self-review notes

- **Spec coverage.** Issue #556 asks for four things. The drift test is Task 4; the golden corpus is Task 5; the two live losses are Tasks 1–3; `docs/backup.md` with its coupling is Task 6. The follow-up — the app version in the header and the direction-aware restore banner — is explicitly out of scope here and is named as such in the issue.
- **Known incompleteness, on purpose.** `NOT_BACKED_UP` in Task 4 shows a few illustrative rows and says "one line per remaining field". This is the one place the plan cannot enumerate ahead of time: the exact set only becomes visible when the metadata walk runs and names them. Step 6 of that task is the loop that closes it. Every other step carries real content.
- **Type consistency.** `UrlNormalizer::hash()` is defined in Task 1 and consumed in Task 2 under the same name. `FullyPopulatedAccount::create()` is defined and consumed within Task 4. `KIND_OF`, `BACKED_UP`, `NOT_BACKED_UP`, `NEVER_BACKED_UP`, `ACCOUNT_SCOPED_WHOLLY_DROPPED`, `INSTANCE_SCOPED` and `UNIVERSALLY_SKIPPED` keep the same names in Tasks 4 and 6.
