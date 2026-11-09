# Each Backup Line Kind Builds and Parses Itself (#2110) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every backup line kind one home for its schema: the `Service/Backup/Dto/*` class that parses a line also builds it (`of(…)` + `toBackupLine()`), so `BackupLines` and `Support/EntryMarkLines` can be deleted without changing the backup format by a single byte.

**Architecture:** Each line DTO gains a static named constructor that maps the entity (or export model) onto its existing constructor, and an instance `toBackupLine(): string` that encodes its fields through the unchanged `Support/BackupLineFormat`, with the `kind` key first and every other key in exactly the order `BackupLines`/`EntryMarkLines` write today. The exporter and `Pass/BackupPartWalk` build their lines through the DTOs. Every kind first gets a characterization test whose JSON literal is proven against the **old** writer before the call is switched to the new API, so the literal is the old bytes, not a guess.

**Tech Stack:** PHP 8.4, Symfony 7.4, PHPUnit 12, PHPStan level max with the house rules in `backend/tests/PhpStan`, PHPMD codesize, phptramp, Infection.

**Spec:** GitHub issue #2110 (`gh issue view 2110`), plus `CLAUDE.md` and `docs/architecture.md` §10.

## Decision: the DTOs may build (no `Backup/Factory/`)

`ServiceRoleRule`'s only DTO check (`tests/PhpStan/DataShapes.php::dtoViolations`) asks that a `Dto/` class is `final readonly`, takes no service-typed constructor argument, and is not named `…Model`; a static `of()` and a public `toBackupLine()` touch none of those. A public instance method would make a DTO a "collaborator" (`ServiceRoleMap::isCollaborator`) only for a constructor that takes it *and* only if it is not built per call — every `*Line` is built by `new self(…)` inside a static method, which `ServiceRoleInstantiationCollector` records as per-call; `Pass/BackupPartGuard` already takes `BackupHeader` (which already has public instance methods) and passes today. §10 allows a DTO to name a model "in a mapping method", which `of()` is. Task 1 runs the full `composer stan` to confirm this on the pilot before the pattern spreads.

## Global Constraints

- **The backup format must not change by a single byte.** Key set, key order, `null`s, nested shapes and the `BackupLineFormat::encode` flags (`JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`) stay exactly as today. `BackupLineFormat` and `BackupSchema` are not edited.
- **Do not edit the safety-net tests or fixtures:** `tests/Service/Backup/Support/BackupSchemaCoverageTest.php`, `tests/Service/Backup/AccountBackupExporterTest.php`, `EntryMediaBackupRoundTripTest.php`, `GoldenBackupRestoreTest.php`, `SavedFeedBackupTest.php`, `BackupReaderTest.php`, `AccountRestorerTest.php`, `EntryPartRestorerTest.php`, `tests/Controller/Api/AccountBackupControllerTest.php`, `tests/Support/BackupFieldDeclarations.php`, `tests/Support/FullyPopulatedAccount.php`, `tests/Support/ExportedFoundation.php`, and everything under `tests/Fixtures/backup/`. Existing DTO tests only gain methods and `use` lines; no existing method changes.
- **Characterization protocol** (every new format test): write it first with the *old* call from the task's mapping table; run it; it must PASS. If it fails, correct **the literal** to what the old writer actually produced (read the failure diff) — never touch `src` at this point. Only then switch the call to the new API.
- The `fromLine()` methods and the DTO constructors do not change (tests and restore code call both).
- House style: `final readonly`, no comments beyond the `@param`/`@return` types PHPStan needs, no abbreviations, lines ≤ 120 characters, `declare(strict_types=1)`.
- Every touched `src` file PHPMD-clean before its commit. No new `backend/src` class is created by this plan (only deleted), so the PHPMD commit gate has nothing to deny.
- `NoToArrayInServicesRule`: the encoders are named `toBackupLine()`, `toBackupFields()`, `toHeaderFields()` — never `toArray()`/`jsonSerialize()`.
- Commits: `refactor(#2110): summary`, no Claude attribution, on the checked-out `refactor/2110-backup-lines-build-themselves`.
- All commands run from `backend/`.

## File map

| File | Change |
|---|---|
| `backend/src/Service/Backup/Dto/EntryStateLine.php` | + `of(EntryState, string)`, `toBackupLine()` |
| `backend/src/Service/Backup/Dto/EntryShareLine.php` | + `of(Entry, \DateTimeImmutable, string)`, `toBackupLine()` |
| `backend/src/Service/Backup/Dto/AccountLine.php` | + `of(User, AccountNetworkSwitchesModel)`, `toBackupLine()` |
| `backend/src/Service/Backup/Dto/TagLine.php` | + `of(Tag)`, `toBackupLine()` |
| `backend/src/Service/Backup/Dto/SavedSearchLine.php` | + `of(SavedSearch)`, `toBackupLine()` |
| `backend/src/Service/Backup/Dto/FeedLine.php` | + `of(ExportedSubscriptionModel)`, `toBackupLine()` |
| `backend/src/Service/Backup/Dto/SubscriptionLine.php` | + `of(ExportedSubscriptionModel)`, `toBackupLine()` |
| `backend/src/Service/Backup/Dto/SubscriptionTagReference.php` | + `of(SubscriptionTag)`, `toBackupFields()` |
| `backend/src/Service/Backup/Dto/EntryLine.php` | + `of(Entry, string, EntryCompanionsModel)`, `toBackupLine()`, private `quoteFields()`, `originFields()` |
| `backend/src/Service/Backup/Dto/BackupHeader.php` | + `foundation(…)`, `entryPart(…)`, `toBackupLine()` |
| `backend/src/Service/Backup/Dto/BackupTotals.php` | + `toHeaderFields()` |
| `backend/src/Service/Backup/Dto/FooterLine.php` | + `NO_COUNTS`, `of(array)`, `toBackupLine()` |
| `backend/src/Service/Backup/Pass/BackupPartWalk.php` | builds through the DTOs; loses `BackupLines $lines` |
| `backend/src/Service/Backup/Factory/BackupPartWalkFactory.php` | loses `BackupLines $lines` |
| `backend/src/Service/Backup/AccountBackupExporter.php` | builds through the DTOs; loses `BackupLines $lines` |
| `backend/src/Service/Backup/Support/EntryMarkLines.php` | **deleted** (Task 1) |
| `backend/src/Service/Backup/BackupLines.php` | shrinks per task, **deleted** (Task 4) |
| `backend/tests/Service/Backup/BackupLinesTest.php` | **deleted** (Task 3; its one test moves to `EntryLineTest`) |
| `backend/tests/Support/BackupLineRecords.php` | **new** test support: unsaved user, entry, subscription |
| `backend/tests/Service/Backup/Dto/{EntryStateLine,EntryShareLine,SavedSearchLine,SubscriptionLine,FooterLine}Test.php` | **new** |
| `backend/tests/Service/Backup/Dto/{AccountLine,TagLine,FeedLine,EntryLine,BackupHeader}Test.php` | gain methods |

---

### Task 1: Pilot — entry-state and entry-share lines build themselves; `EntryMarkLines` goes

**Files:**
- Create: `backend/tests/Support/BackupLineRecords.php`
- Create: `backend/tests/Service/Backup/Dto/EntryStateLineTest.php`
- Create: `backend/tests/Service/Backup/Dto/EntryShareLineTest.php`
- Modify: `backend/src/Service/Backup/Dto/EntryStateLine.php`
- Modify: `backend/src/Service/Backup/Dto/EntryShareLine.php`
- Modify: `backend/src/Service/Backup/Pass/BackupPartWalk.php:19,107,114`
- Delete: `backend/src/Service/Backup/Support/EntryMarkLines.php`

**Interfaces:**
- Produces: `EntryStateLine::of(EntryState $state, string $feedUrl): self`, `EntryStateLine::toBackupLine(): string`, `EntryShareLine::of(Entry $entry, \DateTimeImmutable $sharedAt, string $feedUrl): self`, `EntryShareLine::toBackupLine(): string`; test support `App\Tests\Support\BackupLineRecords::user(): User`, `::entry(): Entry`, `::subscription(Feed $feed): Subscription` (used by Tasks 2 and 3).

Characterization mapping (Step 2 uses the left column, Step 4 the right):

| Old call | New call |
|---|---|
| `EntryMarkLines::state($state, 'https://one.example/rss')` | `EntryStateLine::of($state, 'https://one.example/rss')->toBackupLine()` |
| `EntryMarkLines::share($entry, $sharedAt, 'https://one.example/rss')` | `EntryShareLine::of($entry, $sharedAt, 'https://one.example/rss')->toBackupLine()` |

- [ ] **Step 1: Create the test support file**

`backend/tests/Support/BackupLineRecords.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;

final class BackupLineRecords
{
    public static function user(): User
    {
        return new User('reader@example.test', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
    }

    public static function entry(): Entry
    {
        return new Entry(
            new Feed('https://one.example/feed.xml'),
            'guid-1',
            'https://one.example/a',
            'Article',
            new \DateTimeImmutable('2026-08-02T00:00:00Z'),
            new \DateTimeImmutable('2026-08-03T00:00:00Z'),
        );
    }

    public static function subscription(Feed $feed): Subscription
    {
        return new Subscription(self::user(), $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z'));
    }

    private function __construct()
    {
    }
}
```

Every format test passes `https://one.example/rss` as the exported feed URL, distinct from the `Feed`'s own `https://one.example/feed.xml`, so the literal also pins that a line writes the URL it is given.

- [ ] **Step 2: Write the characterization tests against the old writer**

`backend/tests/Service/Backup/Dto/EntryStateLineTest.php` — final form below; for this step write the two `assertSame` second arguments as the **old** calls from the mapping table and add `use App\Service\Backup\Support\EntryMarkLines;`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Entity\EntryState;
use App\Service\Backup\Dto\EntryStateLine;
use App\Tests\Support\BackupLineRecords;
use PHPUnit\Framework\TestCase;

final class EntryStateLineTest extends TestCase
{
    public function testItWritesAMarkedStateWithItsInstantsInUtc(): void
    {
        $state = new EntryState(BackupLineRecords::user(), BackupLineRecords::entry());
        $state->hide(new \DateTimeImmutable('2026-08-04T10:00:00+02:00'));
        $state->markFavorite();
        $state->markViewed(new \DateTimeImmutable('2026-08-04T08:30:00Z'));

        self::assertSame(
            sprintf(
                '{"kind":"entryState","feedUrl":"https://one.example/rss","guidHash":"%s","isHidden":true,'
                . '"isFavorite":true,"isKept":false,"hiddenAt":"2026-08-04T08:00:00+00:00","isViewed":true,'
                . '"viewedAt":"2026-08-04T08:30:00+00:00"}',
                $state->getEntry()->getGuidHash(),
            ),
            EntryStateLine::of($state, 'https://one.example/rss')->toBackupLine(),
        );
    }

    public function testAKeptStateWritesNoInstants(): void
    {
        $state = new EntryState(BackupLineRecords::user(), BackupLineRecords::entry());
        $state->markKept();

        self::assertSame(
            sprintf(
                '{"kind":"entryState","feedUrl":"https://one.example/rss","guidHash":"%s","isHidden":false,'
                . '"isFavorite":false,"isKept":true,"hiddenAt":null,"isViewed":false,"viewedAt":null}',
                $state->getEntry()->getGuidHash(),
            ),
            EntryStateLine::of($state, 'https://one.example/rss')->toBackupLine(),
        );
    }
}
```

`backend/tests/Service/Backup/Dto/EntryShareLineTest.php` — same rule for this step:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Service\Backup\Dto\EntryShareLine;
use App\Tests\Support\BackupLineRecords;
use PHPUnit\Framework\TestCase;

final class EntryShareLineTest extends TestCase
{
    public function testItWritesTheShareInstantInUtc(): void
    {
        $entry = BackupLineRecords::entry();
        $sharedAt = new \DateTimeImmutable('2026-08-05T09:15:00+02:00');

        self::assertSame(
            sprintf(
                '{"kind":"entryShare","feedUrl":"https://one.example/rss","guidHash":"%s",'
                . '"sharedAt":"2026-08-05T07:15:00+00:00"}',
                $entry->getGuidHash(),
            ),
            EntryShareLine::of($entry, $sharedAt, 'https://one.example/rss')->toBackupLine(),
        );
    }
}
```

- [ ] **Step 3: Run them against the old writer**

Run: `php bin/phpunit tests/Service/Backup/Dto/EntryStateLineTest.php tests/Service/Backup/Dto/EntryShareLineTest.php`
Expected: PASS (3 tests). On a FAIL, fix the literal per the characterization protocol and rerun until green.

- [ ] **Step 4: Switch the calls to the new API**

Replace each old call with its right-hand column from the mapping table and remove the temporary `use …\EntryMarkLines;`.

Run: same command. Expected: FAIL — `Call to undefined method App\Service\Backup\Dto\EntryStateLine::of()` (and `EntryShareLine::of()`).

- [ ] **Step 5: Implement `EntryStateLine::of()` and `toBackupLine()`**

In `backend/src/Service/Backup/Dto/EntryStateLine.php`, change the imports to:

```php
use App\Entity\EntryState;
use App\Service\Backup\Support\BackupLineFormat;
use App\Service\Backup\Support\BackupSchema;
use App\Service\Backup\Support\LineField;
```

and add after `fromLine()`:

```php
    public static function of(EntryState $state, string $feedUrl): self
    {
        return new self(
            feedUrl: $feedUrl,
            guidHash: $state->getEntry()->getGuidHash(),
            isHidden: $state->isHidden(),
            isFavorite: $state->isFavorite(),
            isKept: $state->isKept(),
            hiddenAt: $state->getHiddenAt(),
            isViewed: $state->isViewed(),
            viewedAt: $state->getViewedAt(),
        );
    }

    public function toBackupLine(): string
    {
        return BackupLineFormat::encode([
            'kind' => BackupSchema::KIND_ENTRY_STATE,
            'feedUrl' => $this->feedUrl,
            'guidHash' => $this->guidHash,
            'isHidden' => $this->isHidden,
            'isFavorite' => $this->isFavorite,
            'isKept' => $this->isKept,
            'hiddenAt' => BackupLineFormat::dateOrNull($this->hiddenAt),
            'isViewed' => $this->isViewed,
            'viewedAt' => BackupLineFormat::dateOrNull($this->viewedAt),
        ]);
    }
```

- [ ] **Step 6: Implement `EntryShareLine::of()` and `toBackupLine()`**

In `backend/src/Service/Backup/Dto/EntryShareLine.php`, change the imports to:

```php
use App\Entity\Entry;
use App\Service\Backup\Support\BackupLineFormat;
use App\Service\Backup\Support\BackupSchema;
use App\Service\Backup\Support\LineField;
```

and add after `fromLine()`:

```php
    public static function of(Entry $entry, \DateTimeImmutable $sharedAt, string $feedUrl): self
    {
        return new self(feedUrl: $feedUrl, guidHash: $entry->getGuidHash(), sharedAt: $sharedAt);
    }

    public function toBackupLine(): string
    {
        return BackupLineFormat::encode([
            'kind' => BackupSchema::KIND_ENTRY_SHARE,
            'feedUrl' => $this->feedUrl,
            'guidHash' => $this->guidHash,
            'sharedAt' => BackupLineFormat::date($this->sharedAt),
        ]);
    }
```

- [ ] **Step 7: Run the new tests**

Run: `php bin/phpunit tests/Service/Backup/Dto/EntryStateLineTest.php tests/Service/Backup/Dto/EntryShareLineTest.php`
Expected: PASS (3 tests).

- [ ] **Step 8: Build the mark lines in `BackupPartWalk` through the DTOs; delete `EntryMarkLines`**

In `backend/src/Service/Backup/Pass/BackupPartWalk.php`:
- replace `use App\Service\Backup\Support\EntryMarkLines;` with nothing, and add (alphabetically, after `use App\Service\Backup\Dto\BackupTotals;`):

```php
use App\Service\Backup\Dto\EntryShareLine;
use App\Service\Backup\Dto\EntryStateLine;
```

- line 107: `$this->buffer->addShare(EntryMarkLines::share($entry, $sharedAt, $feedUrl));` becomes

```php
                $this->buffer->addShare(EntryShareLine::of($entry, $sharedAt, $feedUrl)->toBackupLine());
```

- line 114: `null !== $state ? EntryMarkLines::state($state, $feedUrl) : null,` becomes

```php
                null !== $state ? EntryStateLine::of($state, $feedUrl)->toBackupLine() : null,
```

Then: `git rm src/Service/Backup/Support/EntryMarkLines.php`.

- [ ] **Step 9: Run the backup suite**

Run: `php bin/phpunit tests/Service/Backup tests/Controller/Api/AccountBackupControllerTest.php`
Expected: PASS, with `BackupSchemaCoverageTest` and `AccountBackupExporterTest` unchanged.

- [ ] **Step 10: Confirm the role decision and the style gates on the pilot**

Run: `bin/console cache:warmup && composer stan`
Expected: `[OK] No errors`. If `ServiceRoleRule` reports a DTO violation on `EntryStateLine`/`EntryShareLine`, stop and report to the planner — the rest of the plan assumes it does not.

Run: `vendor/bin/phpcs src/Service/Backup tests/Service/Backup tests/Support/BackupLineRecords.php`
Run: `php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/phpmd src/Service/Backup/Dto/EntryStateLine.php,src/Service/Backup/Dto/EntryShareLine.php,src/Service/Backup/Pass/BackupPartWalk.php text phpmd.xml.dist`
Expected: no output from either.

- [ ] **Step 11: Commit**

```bash
git add tests/Support/BackupLineRecords.php tests/Service/Backup/Dto/EntryStateLineTest.php \
  tests/Service/Backup/Dto/EntryShareLineTest.php src/Service/Backup/Dto/EntryStateLine.php \
  src/Service/Backup/Dto/EntryShareLine.php src/Service/Backup/Pass/BackupPartWalk.php
git commit -m "refactor(#2110): entry-state and entry-share lines build themselves"
```

(`git rm` in Step 8 already staged the deletion.)

---

### Task 2: Foundation record lines — account, tag, saved search, feed, subscription

**Files:**
- Create: `backend/tests/Service/Backup/Dto/SavedSearchLineTest.php`, `backend/tests/Service/Backup/Dto/SubscriptionLineTest.php`
- Modify (tests, add only): `backend/tests/Service/Backup/Dto/AccountLineTest.php`, `TagLineTest.php`, `FeedLineTest.php`
- Modify: `backend/src/Service/Backup/Dto/AccountLine.php`, `TagLine.php`, `SavedSearchLine.php`, `FeedLine.php`, `SubscriptionLine.php`, `SubscriptionTagReference.php`
- Modify: `backend/src/Service/Backup/AccountBackupExporter.php:70-91`
- Modify: `backend/src/Service/Backup/BackupLines.php` (remove `accountLine`, `tagLine`, `savedSearchLine`, `feedLine`, `subscriptionLine`, `subscriptionTagRefs` and the imports only they used)

**Interfaces:**
- Consumes: `BackupLineRecords::user()`, `::subscription(Feed)` from Task 1.
- Produces: `AccountLine::of(User $user, AccountNetworkSwitchesModel $networks): self`, `TagLine::of(Tag $tag): self`, `SavedSearchLine::of(SavedSearch $savedSearch): self`, `FeedLine::of(ExportedSubscriptionModel $exported): self`, `SubscriptionLine::of(ExportedSubscriptionModel $exported): self`, `SubscriptionTagReference::of(SubscriptionTag $subscriptionTag): self`, `SubscriptionTagReference::toBackupFields(): array{name: string, position: int}`, and `toBackupLine(): string` on the five line classes.

Characterization mapping (old calls need `use App\Service\Backup\BackupLines;` temporarily):

| Old call | New call |
|---|---|
| `(new BackupLines())->accountLine($user, $networks)` | `AccountLine::of($user, $networks)->toBackupLine()` |
| `(new BackupLines())->tagLine($tag)` | `TagLine::of($tag)->toBackupLine()` |
| `(new BackupLines())->tagLine(new Tag(BackupLineRecords::user(), 'Reading'))` | `TagLine::of(new Tag(BackupLineRecords::user(), 'Reading'))->toBackupLine()` |
| `(new BackupLines())->savedSearchLine($savedSearch)` | `SavedSearchLine::of($savedSearch)->toBackupLine()` |
| `(new BackupLines())->feedLine($exported)` | `FeedLine::of($exported)->toBackupLine()` |
| `(new BackupLines())->subscriptionLine($exported)` | `SubscriptionLine::of($exported)->toBackupLine()` |

- [ ] **Step 1: Write the characterization tests (old calls first)**

Append to `AccountLineTest` (add `use App\Service\Backup\Model\AccountNetworkSwitchesModel;` and `use App\Tests\Support\BackupLineRecords;`):

```php
    public function testItWritesTheAccountsSettingsAndNetworkSwitches(): void
    {
        $user = BackupLineRecords::user();
        $user->setLocale('de');
        $preferences = $user->getPreferences();
        $preferences->setScrapeFallbackEnabled(true);
        $preferences->setMagazineStyle(MagazineStyle::Airy);
        $preferences->setHiddenShareTargets(['mastodon', 'email']);
        $preferences->setRememberingShares(false);
        $preferences->setTrendingEnabled(false);
        $networks = new AccountNetworkSwitchesModel([SocialNetwork::Bluesky], [SocialNetwork::Mastodon]);

        self::assertSame(
            '{"kind":"account","locale":"de","scrapeFallbackEnabled":true,"magazineStyle":"airy",'
            . '"sharingEnabled":true,"hiddenShareTargets":["mastodon","email"],"rememberShares":false,'
            . '"trendingEnabled":false,"savedSearchIncludedNetworks":["bluesky"],'
            . '"forYouExcludedNetworks":["mastodon"]}',
            AccountLine::of($user, $networks)->toBackupLine(),
        );
    }
```

Append to `TagLineTest` (add `use App\Entity\Tag;` and `use App\Tests\Support\BackupLineRecords;`):

```php
    public function testItWritesAChildTagNamingItsParent(): void
    {
        $user = BackupLineRecords::user();
        $tag = new Tag($user, 'Long reads');
        $tag->setColor('#123456');
        $tag->setIcon('flask');
        $tag->setPosition(2);
        $tag->setParent(new Tag($user, 'Reading'));

        self::assertSame(
            '{"kind":"tag","name":"Long reads","color":"#123456","icon":"flask","position":2,"parent":"Reading"}',
            TagLine::of($tag)->toBackupLine(),
        );
    }

    public function testItWritesARootTagWithANullParent(): void
    {
        self::assertSame(
            '{"kind":"tag","name":"Reading","color":null,"icon":null,"position":0,"parent":null}',
            TagLine::of(new Tag(BackupLineRecords::user(), 'Reading'))->toBackupLine(),
        );
    }
```

Create `backend/tests/Service/Backup/Dto/SavedSearchLineTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Entity\SavedSearch;
use App\Service\Backup\Dto\SavedSearchLine;
use App\Tests\Support\BackupLineRecords;
use PHPUnit\Framework\TestCase;

final class SavedSearchLineTest extends TestCase
{
    public function testItWritesItsLineInTheBackupFormat(): void
    {
        $savedSearch = new SavedSearch(BackupLineRecords::user(), 'climate', true, false);
        $savedSearch->setPosition(4);

        self::assertSame(
            '{"kind":"savedSearch","term":"climate","wholeWord":true,"phrase":false,"position":4}',
            SavedSearchLine::of($savedSearch)->toBackupLine(),
        );
    }
}
```

Append to `FeedLineTest` (add `use App\Entity\Feed;`, `use App\Service\Social\Model\ExportedSubscriptionModel;`, `use App\Tests\Support\BackupLineRecords;`; `SourceFormat` is already imported):

```php
    public function testItWritesTheExportedAddressAndFormatOverTheFeedsOwn(): void
    {
        $feed = new Feed('https://one.example/feed.xml');
        $feed->setSiteUrl('https://one.example');
        $feed->setTitle('One');
        $feed->setFaviconUrl('https://one.example/favicon.ico');
        $exported = new ExportedSubscriptionModel(
            BackupLineRecords::subscription($feed),
            'https://one.example/rss',
            SourceFormat::XML,
        );

        self::assertSame(
            '{"kind":"feed","url":"https://one.example/rss","siteUrl":"https://one.example","title":"One",'
            . '"description":null,"faviconUrl":"https://one.example/favicon.ico","imageUrl":null,'
            . '"sourceFormat":"xml"}',
            FeedLine::of($exported)->toBackupLine(),
        );
    }
```

Create `backend/tests/Service/Backup/Dto/SubscriptionLineTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Entity\Feed;
use App\Entity\Tag;
use App\Enum\SourceFormat;
use App\Service\Backup\Dto\SubscriptionLine;
use App\Service\Social\Model\ExportedSubscriptionModel;
use App\Tests\Support\BackupLineRecords;
use PHPUnit\Framework\TestCase;

final class SubscriptionLineTest extends TestCase
{
    public function testItWritesItsSettingsAndItsTagsInPositionOrder(): void
    {
        $user = BackupLineRecords::user();
        $subscription = BackupLineRecords::subscription(new Feed('https://one.example/feed.xml'));
        $subscription->setCustomTitle('Mine');
        $subscription->setPosition(3);
        $subscription->setMarkedReadUntil(new \DateTimeImmutable('2026-08-01T02:00:00+02:00'));
        $subscription->setIncludeInForYou(false);
        $subscription->addTag(new Tag($user, 'Reading'), 1);
        $subscription->addTag(new Tag($user, 'Science'), 0);
        $exported = new ExportedSubscriptionModel($subscription, 'https://one.example/rss', SourceFormat::XML);

        self::assertSame(
            '{"kind":"subscription","feedUrl":"https://one.example/rss","customTitle":"Mine","position":3,'
            . '"markedReadUntil":"2026-08-01T00:00:00+00:00","createdAt":"2026-07-01T00:00:00+00:00",'
            . '"tags":[{"name":"Science","position":0},{"name":"Reading","position":1}],'
            . '"includeInAllItems":true,"includeInForYou":false,"includeInSavedSearches":true}',
            SubscriptionLine::of($exported)->toBackupLine(),
        );
    }
}
```

For this step, every second `assertSame` argument above is written as its old call from the mapping table.

- [ ] **Step 2: Run them against the old writer**

Run: `php bin/phpunit tests/Service/Backup/Dto/AccountLineTest.php tests/Service/Backup/Dto/TagLineTest.php tests/Service/Backup/Dto/SavedSearchLineTest.php tests/Service/Backup/Dto/FeedLineTest.php tests/Service/Backup/Dto/SubscriptionLineTest.php`
Expected: PASS. On a FAIL, correct the literal (characterization protocol).

- [ ] **Step 3: Switch to the new calls**

Replace each old call with the right-hand column; remove the temporary `use App\Service\Backup\BackupLines;`.
Run: same command. Expected: FAIL — `Call to undefined method …::of()`.

- [ ] **Step 4: Implement the five line kinds and the tag reference**

Every kind follows the Task 1 pattern: a static `of()` that calls `new self(…)` with named arguments, and `toBackupLine()` that returns `BackupLineFormat::encode([...])` with `'kind' => BackupSchema::KIND_…` first. Each file adds `use App\Service\Backup\Support\BackupLineFormat;` and `use App\Service\Backup\Support\BackupSchema;` next to its existing `LineField` import.

`AccountLine.php` — add `use App\Entity\User;` and `use App\Service\Backup\Model\AccountNetworkSwitchesModel;`; after `fromLine()`:

```php
    public static function of(User $user, AccountNetworkSwitchesModel $networks): self
    {
        $preferences = $user->getPreferences();

        return new self(
            locale: $user->getLocale(),
            scrapeFallbackEnabled: $preferences->isScrapeFallbackEnabled(),
            magazineStyle: $preferences->getMagazineStyle(),
            sharingEnabled: $preferences->isSharingEnabled(),
            hiddenShareTargets: $preferences->getHiddenShareTargets(),
            rememberShares: $preferences->isRememberingShares(),
            trendingEnabled: $preferences->isTrendingEnabled(),
            savedSearchIncludedNetworks: $networks->savedSearchIncludedNetworks,
            forYouExcludedNetworks: $networks->forYouExcludedNetworks,
        );
    }

    public function toBackupLine(): string
    {
        return BackupLineFormat::encode([
            'kind' => BackupSchema::KIND_ACCOUNT,
            'locale' => $this->locale,
            'scrapeFallbackEnabled' => $this->scrapeFallbackEnabled,
            'magazineStyle' => $this->magazineStyle->value,
            'sharingEnabled' => $this->sharingEnabled,
            'hiddenShareTargets' => $this->hiddenShareTargets,
            'rememberShares' => $this->rememberShares,
            'trendingEnabled' => $this->trendingEnabled,
            'savedSearchIncludedNetworks' => SocialNetwork::valuesOf($this->savedSearchIncludedNetworks),
            'forYouExcludedNetworks' => SocialNetwork::valuesOf($this->forYouExcludedNetworks),
        ]);
    }
```

`TagLine.php` — add `use App\Entity\Tag;`; after `fromLine()`:

```php
    public static function of(Tag $tag): self
    {
        return new self(
            name: $tag->getName(),
            color: $tag->getColor(),
            icon: $tag->getIcon(),
            position: $tag->getPosition(),
            parent: $tag->getParent()?->getName(),
        );
    }

    public function toBackupLine(): string
    {
        return BackupLineFormat::encode([
            'kind' => BackupSchema::KIND_TAG,
            'name' => $this->name,
            'color' => $this->color,
            'icon' => $this->icon,
            'position' => $this->position,
            'parent' => $this->parent,
        ]);
    }
```

`SavedSearchLine.php` — add `use App\Entity\SavedSearch;`; after `fromLine()`:

```php
    public static function of(SavedSearch $savedSearch): self
    {
        return new self(
            term: $savedSearch->getTerm(),
            wholeWord: $savedSearch->isWholeWord(),
            phrase: $savedSearch->isPhrase(),
            position: $savedSearch->getPosition(),
        );
    }

    public function toBackupLine(): string
    {
        return BackupLineFormat::encode([
            'kind' => BackupSchema::KIND_SAVED_SEARCH,
            'term' => $this->term,
            'wholeWord' => $this->wholeWord,
            'phrase' => $this->phrase,
            'position' => $this->position,
        ]);
    }
```

`FeedLine.php` — add `use App\Service\Social\Model\ExportedSubscriptionModel;`; after `fromLine()` (no refusal checks here: the exporter's `ExportedFeed` already decides what travels, exactly as `BackupLines::feedLine` did):

```php
    public static function of(ExportedSubscriptionModel $exported): self
    {
        $feed = $exported->subscription->getFeed();

        return new self(
            url: $exported->feedUrl,
            siteUrl: $feed->getSiteUrl(),
            title: $feed->getTitle(),
            description: $feed->getDescription(),
            faviconUrl: $feed->getFaviconUrl(),
            imageUrl: $feed->getImageUrl(),
            sourceFormat: $exported->feedSourceFormat,
        );
    }

    public function toBackupLine(): string
    {
        return BackupLineFormat::encode([
            'kind' => BackupSchema::KIND_FEED,
            'url' => $this->url,
            'siteUrl' => $this->siteUrl,
            'title' => $this->title,
            'description' => $this->description,
            'faviconUrl' => $this->faviconUrl,
            'imageUrl' => $this->imageUrl,
            'sourceFormat' => $this->sourceFormat,
        ]);
    }
```

`SubscriptionTagReference.php` — add `use App\Entity\SubscriptionTag;`; after `fromLine()`:

```php
    public static function of(SubscriptionTag $subscriptionTag): self
    {
        return new self(name: $subscriptionTag->getTag()->getName(), position: $subscriptionTag->getPosition());
    }

    /** @return array{name: string, position: int} */
    public function toBackupFields(): array
    {
        return ['name' => $this->name, 'position' => $this->position];
    }
```

`SubscriptionLine.php` — add `use App\Service\Social\Model\ExportedSubscriptionModel;`; after `fromLine()` (before the private `tagsFromLine()`):

```php
    public static function of(ExportedSubscriptionModel $exported): self
    {
        $subscription = $exported->subscription;

        return new self(
            feedUrl: $exported->feedUrl,
            customTitle: $subscription->getCustomTitle(),
            position: $subscription->getPosition(),
            markedReadUntil: $subscription->getMarkedReadUntil(),
            createdAt: $subscription->getCreatedAt(),
            tags: array_map(SubscriptionTagReference::of(...), $subscription->getSubscriptionTags()),
            includeInAllItems: $subscription->isIncludeInAllItems(),
            includeInForYou: $subscription->isIncludeInForYou(),
            includeInSavedSearches: $subscription->isIncludeInSavedSearches(),
        );
    }

    public function toBackupLine(): string
    {
        return BackupLineFormat::encode([
            'kind' => BackupSchema::KIND_SUBSCRIPTION,
            'feedUrl' => $this->feedUrl,
            'customTitle' => $this->customTitle,
            'position' => $this->position,
            'markedReadUntil' => BackupLineFormat::dateOrNull($this->markedReadUntil),
            'createdAt' => BackupLineFormat::date($this->createdAt),
            'tags' => array_map(
                static fn (SubscriptionTagReference $tag): array => $tag->toBackupFields(),
                $this->tags,
            ),
            'includeInAllItems' => $this->includeInAllItems,
            'includeInForYou' => $this->includeInForYou,
            'includeInSavedSearches' => $this->includeInSavedSearches,
        ]);
    }
```

- [ ] **Step 5: Run the DTO tests**

Run: the Step 2 command. Expected: PASS.

- [ ] **Step 6: Build the foundation lines in `AccountBackupExporter` through the DTOs**

In `backend/src/Service/Backup/AccountBackupExporter.php` add imports:

```php
use App\Entity\SavedSearch;
use App\Service\Backup\Dto\AccountLine;
use App\Service\Backup\Dto\FeedLine;
use App\Service\Backup\Dto\SavedSearchLine;
use App\Service\Backup\Dto\SubscriptionLine;
use App\Service\Backup\Dto\TagLine;
```

Replace `foundationSnapshot()` with:

```php
    private function foundationSnapshot(int $userId): FoundationSnapshotModel
    {
        $subscriptions = ExportedFeed::withoutRepeats(
            $this->subscriptions->findForUserWithTags($userId, SubscriptionSet::Backup),
        );
        $feedsById = self::feedsById($subscriptions);

        return new FoundationSnapshotModel(
            AccountLine::of($this->users->getById($userId), $this->networkSwitchesOf($userId))->toBackupLine(),
            array_map(
                static fn (Tag $tag): string => TagLine::of($tag)->toBackupLine(),
                self::parentsFirst($this->tags->findForUser($userId)),
            ),
            array_map(
                static fn (SavedSearch $savedSearch): string => SavedSearchLine::of($savedSearch)->toBackupLine(),
                $this->savedSearches->findForUser($userId),
            ),
            array_map(
                static fn (ExportedSubscriptionModel $exported): string => FeedLine::of($exported)->toBackupLine(),
                array_values($feedsById),
            ),
            array_map(
                static fn (ExportedSubscriptionModel $exported): string => SubscriptionLine::of($exported)
                    ->toBackupLine(),
                $subscriptions,
            ),
            array_map(static fn (ExportedSubscriptionModel $exported): string => $exported->feedUrl, $feedsById),
        );
    }

    private function networkSwitchesOf(int $userId): AccountNetworkSwitchesModel
    {
        return new AccountNetworkSwitchesModel(
            $this->includedNetworks->includedNetworksFor($userId),
            $this->forYouExcludedNetworks->excludedNetworksFor($userId),
        );
    }
```

`$this->lines` stays in the constructor for now (headers and footer still use it until Task 4). `use App\Entity\User;` stays (used by `parts()`/`provenanceOf()`).

- [ ] **Step 7: Shrink `BackupLines`**

In `backend/src/Service/Backup/BackupLines.php` delete `accountLine()`, `tagLine()`, `savedSearchLine()`, `feedLine()`, `subscriptionLine()` and `subscriptionTagRefs()`, and the imports only they used: `App\Entity\SavedSearch`, `App\Entity\Subscription`, `App\Entity\SubscriptionTag`, `App\Entity\Tag`, `App\Entity\User`, `App\Enum\SocialNetwork`, `App\Service\Backup\Model\AccountNetworkSwitchesModel`, `App\Service\Social\Model\ExportedSubscriptionModel`.

- [ ] **Step 8: Run the backup suite and the file gates**

Run: `php bin/phpunit tests/Service/Backup tests/Controller/Api/AccountBackupControllerTest.php`
Expected: PASS, safety-net tests untouched.

Run: `vendor/bin/phpcs src/Service/Backup tests/Service/Backup`
Run: `php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/phpmd src/Service/Backup/Dto,src/Service/Backup/AccountBackupExporter.php,src/Service/Backup/BackupLines.php text phpmd.xml.dist`
Expected: no output.

- [ ] **Step 9: Commit**

```bash
git add src/Service/Backup/Dto src/Service/Backup/AccountBackupExporter.php src/Service/Backup/BackupLines.php \
  tests/Service/Backup/Dto
git commit -m "refactor(#2110): the foundation's record lines build themselves"
```

---

### Task 3: The entry line builds itself

**Files:**
- Modify (tests, add only): `backend/tests/Service/Backup/Dto/EntryLineTest.php`
- Delete: `backend/tests/Service/Backup/BackupLinesTest.php` (its test moves to `EntryLineTest`)
- Modify: `backend/src/Service/Backup/Dto/EntryLine.php`
- Modify: `backend/src/Service/Backup/Pass/BackupPartWalk.php:109-113`
- Modify: `backend/src/Service/Backup/BackupLines.php` (remove `entryLine`, `quoteFields` and their imports)

**Interfaces:**
- Consumes: `BackupLineRecords::entry()` from Task 1.
- Produces: `EntryLine::of(Entry $entry, string $feedUrl, EntryCompanionsModel $companions): self`, `EntryLine::toBackupLine(): string`.

Characterization mapping:

| Old call | New call |
|---|---|
| `(new BackupLines())->entryLine($entry, 'https://one.example/rss', $companions)` | `EntryLine::of($entry, 'https://one.example/rss', $companions)->toBackupLine()` |
| `(new BackupLines())->entryLine($entry, 'https://one.example/rss', new EntryCompanionsModel())` | `EntryLine::of($entry, 'https://one.example/rss', new EntryCompanionsModel())->toBackupLine()` |

- [ ] **Step 1: Write the characterization tests (old calls first)**

In `EntryLineTest` add `use App\Service\Backup\Model\EntryCompanionsModel;` and `use App\Tests\Support\BackupLineRecords;` (`EntryOrigin`, `LinkCardContent`, `QuotedPostContent`, `QuotedPostReference` are already imported), and append:

```php
    public function testItWritesItsLineInTheBackupFormat(): void
    {
        $entry = BackupLineRecords::entry();
        $entry->setPublishedAt(new \DateTimeImmutable('2026-08-01T14:00:00+02:00'));
        $companions = new EntryCompanionsModel(
            [new LinkCardContent('https://card.example/x', 'Card', null, null, 'card.example')],
            new EntryOrigin('One', 'https://one.example/favicon.ico'),
        );

        self::assertSame(
            sprintf(
                '{"kind":"entry","feedUrl":"https://one.example/rss","guid":"guid-1","guidHash":"%s",'
                . '"url":"https://one.example/a","discussionUrl":null,"commentsFeedUrl":null,"commentsLoad":null,'
                . '"title":"Article","titleDerived":false,"shareKind":null,"quote":null,"author":null,'
                . '"authorUrl":null,"authorAvatarUrl":null,"summary":null,"contentHtml":null,"imageUrl":null,'
                . '"imageWidth":null,"imageHeight":null,"imageRenditions":[],"media":[],"attachments":[],'
                . '"chapters":[],"chaptersUrl":null,"linkCards":[{"url":"https://card.example/x","title":"Card",'
                . '"description":null,"imageUrl":null,"siteName":"card.example"}],'
                . '"origin":{"title":"One","faviconUrl":"https://one.example/favicon.ico"},'
                . '"publishedAt":"2026-08-01T12:00:00+00:00","createdAt":"2026-08-02T00:00:00+00:00",'
                . '"effectiveDate":"2026-08-03T00:00:00+00:00"}',
                $entry->getGuidHash(),
            ),
            EntryLine::of($entry, 'https://one.example/rss', $companions)->toBackupLine(),
        );
    }

    public function testItWritesAQuotedPostsFieldsInOrder(): void
    {
        $entry = BackupLineRecords::entry();
        $entry->shareQuotedPost(new QuotedPostContent(
            'https://bsky.app/profile/q/post/3k',
            'Q',
            'q',
            null,
            new \DateTimeImmutable('2026-08-01T00:00:00Z'),
            'hi',
            [],
            new QuotedPostReference('https://bsky.app/profile/n/post/1', 'n'),
        ));

        self::assertStringContainsString(
            '"shareKind":"quote","quote":{"url":"https://bsky.app/profile/q/post/3k","authorName":"Q",'
            . '"authorHandle":"q","authorAvatarUrl":null,"postedAt":"2026-08-01T00:00:00+00:00","text":"hi",'
            . '"media":[],"nestedQuote":{"url":"https://bsky.app/profile/n/post/1","handle":"n"},"linkCard":null}',
            EntryLine::of($entry, 'https://one.example/rss', new EntryCompanionsModel())->toBackupLine(),
        );
    }

    public function testAQuotedPostWithoutALinkCardIsWrittenWithNone(): void
    {
        $entry = BackupLineRecords::entry();
        $entry->shareQuotedPost(
            new QuotedPostContent('https://bsky.app/profile/q/post/3k', 'Q', 'q', null, null, 'hi', [], null),
        );

        $line = json_decode(
            EntryLine::of($entry, 'https://one.example/rss', new EntryCompanionsModel())->toBackupLine(),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($line);
        self::assertIsArray($line['quote']);
        self::assertArrayHasKey('linkCard', $line['quote']);
        self::assertNull($line['quote']['linkCard']);
    }
```

The third test is `BackupLinesTest::testAQuotedPostWithoutALinkCardIsWrittenWithNone` moved verbatim except for the entry fixture and the call; its assertions are unchanged. For this step write all three calls as the old calls (add `use App\Service\Backup\BackupLines;` temporarily).

- [ ] **Step 2: Run them against the old writer**

Run: `php bin/phpunit tests/Service/Backup/Dto/EntryLineTest.php`
Expected: PASS. On a FAIL, correct the literal (characterization protocol).

- [ ] **Step 3: Switch to the new calls; delete `BackupLinesTest`**

Replace the old calls with the right-hand column, remove the temporary import, then `git rm tests/Service/Backup/BackupLinesTest.php`.
Run: `php bin/phpunit tests/Service/Backup/Dto/EntryLineTest.php`. Expected: FAIL — `Call to undefined method App\Service\Backup\Dto\EntryLine::of()`.

- [ ] **Step 4: Implement `EntryLine::of()`, `toBackupLine()`, `quoteFields()`, `originFields()`**

In `backend/src/Service/Backup/Dto/EntryLine.php` add imports:

```php
use App\Entity\Entry;
use App\Entity\EntryMedia;
use App\Entity\ImageRendition;
use App\Service\Backup\Model\EntryCompanionsModel;
use App\Service\Backup\Support\BackupLineFormat;
use App\Service\Backup\Support\BackupSchema;
```

After `fromLine()` add:

```php
    public static function of(Entry $entry, string $feedUrl, EntryCompanionsModel $companions): self
    {
        $discussion = $entry->getDiscussion();

        return new self(
            feedUrl: $feedUrl,
            guid: $entry->getGuid(),
            guidHash: $entry->getGuidHash(),
            url: $entry->getUrl(),
            title: $entry->getTitle(),
            author: $entry->getAuthor(),
            summary: $entry->getSummary(),
            contentHtml: $entry->getContentHtml(),
            imageUrl: $entry->getImageUrl(),
            imageWidth: $entry->getImageWidth(),
            imageHeight: $entry->getImageHeight(),
            publishedAt: $entry->getPublishedAt(),
            createdAt: $entry->getCreatedAt(),
            effectiveDate: $entry->getEffectiveDate(),
            media: EntryMedia::toJsonList($entry->getMedia()),
            attachments: EntryMedia::toJsonList($entry->getAttachments()),
            discussionUrl: $discussion->url,
            commentsFeedUrl: $discussion->commentsFeedUrl,
            commentsLoad: $discussion->commentsLoad?->value,
            imageRenditions: ImageRendition::toJsonList($entry->getImage()->getRenditions()),
            titleDerived: $entry->isTitleDerived(),
            shareKind: $entry->getShareKind()?->value,
            linkCards: $companions->linkCards,
            authorUrl: $entry->getAuthorUrl(),
            authorAvatarUrl: $entry->getAuthorAvatarUrl(),
            quote: $entry->getQuotedPost(),
            origin: $companions->origin,
            chapters: EntryMedia::toJsonList($entry->getChapters()),
            chaptersUrl: $entry->getChaptersUrl(),
        );
    }

    public function toBackupLine(): string
    {
        return BackupLineFormat::encode([
            'kind' => BackupSchema::KIND_ENTRY,
            'feedUrl' => $this->feedUrl,
            'guid' => $this->guid,
            'guidHash' => $this->guidHash,
            'url' => $this->url,
            'discussionUrl' => $this->discussionUrl,
            'commentsFeedUrl' => $this->commentsFeedUrl,
            'commentsLoad' => $this->commentsLoad,
            'title' => $this->title,
            'titleDerived' => $this->titleDerived,
            'shareKind' => $this->shareKind,
            'quote' => self::quoteFields($this->quote),
            'author' => $this->author,
            'authorUrl' => $this->authorUrl,
            'authorAvatarUrl' => $this->authorAvatarUrl,
            'summary' => $this->summary,
            'contentHtml' => $this->contentHtml,
            'imageUrl' => $this->imageUrl,
            'imageWidth' => $this->imageWidth,
            'imageHeight' => $this->imageHeight,
            'imageRenditions' => $this->imageRenditions,
            'media' => $this->media,
            'attachments' => $this->attachments,
            'chapters' => $this->chapters,
            'chaptersUrl' => $this->chaptersUrl,
            'linkCards' => array_map(static fn (LinkCardContent $card): array => $card->toStored(), $this->linkCards),
            'origin' => self::originFields($this->origin),
            'publishedAt' => BackupLineFormat::dateOrNull($this->publishedAt),
            'createdAt' => BackupLineFormat::date($this->createdAt),
            'effectiveDate' => BackupLineFormat::date($this->effectiveDate),
        ]);
    }

    /** @return array<string, mixed>|null */
    private static function quoteFields(?QuotedPostContent $quote): ?array
    {
        if ($quote === null) {
            return null;
        }
        $nested = $quote->nestedQuote;

        return [
            'url' => $quote->url,
            'authorName' => $quote->authorName,
            'authorHandle' => $quote->authorHandle,
            'authorAvatarUrl' => $quote->authorAvatarUrl,
            'postedAt' => BackupLineFormat::dateOrNull($quote->postedAt),
            'text' => $quote->text,
            'media' => EntryMedia::toJsonList($quote->media),
            'nestedQuote' => $nested === null ? null : ['url' => $nested->url, 'handle' => $nested->handle],
            'linkCard' => $quote->linkCard?->toStored(),
        ];
    }

    /** @return array{title: ?string, faviconUrl: ?string}|null */
    private static function originFields(?EntryOrigin $origin): ?array
    {
        return null === $origin ? null : ['title' => $origin->title, 'faviconUrl' => $origin->faviconUrl];
    }
```

Byte parity notes for the reviewer: the key order is `BackupLines::entryLine`'s; `media`, `attachments`, `chapters` and `imageRenditions` are encoded at `of()` time with the same `toJsonList()` calls the old writer used, so the DTO stores and writes the identical arrays; `chaptersUrl`, `quote` and `shareKind` are copied from the entity untouched (the read-side normalisation in `fromLine()` is not applied on the write side, as before).

- [ ] **Step 5: Run the entry tests**

Run: `php bin/phpunit tests/Service/Backup/Dto/EntryLineTest.php`
Expected: PASS (existing read tests plus the three new ones).

- [ ] **Step 6: Build the entry line in `BackupPartWalk`; shrink `BackupLines`**

In `BackupPartWalk.php` add `use App\Service\Backup\Dto\EntryLine;` (after `BackupTotals`), and replace lines 109-113:

```php
            yield from $this->bufferLines(
                EntryLine::of($entry, $feedUrl, new EntryCompanionsModel(
                    $cardsByEntryId[$entry->requireId()] ?? [],
                    $originsByEntryId[$entry->requireId()] ?? null,
                ))->toBackupLine(),
                null !== $state ? EntryStateLine::of($state, $feedUrl)->toBackupLine() : null,
            );
```

In `BackupLines.php` delete `entryLine()` and `quoteFields()` and the imports only they used: `App\Entity\Entry`, `App\Entity\EntryMedia`, `App\Entity\ImageRendition`, `App\Entity\LinkCardContent`, `App\Entity\QuotedPostContent`, `App\Service\Backup\Model\EntryCompanionsModel`.

- [ ] **Step 7: Run the backup suite and the file gates**

Run: `php bin/phpunit tests/Service/Backup tests/Controller/Api/AccountBackupControllerTest.php`
Expected: PASS (notably `EntryMediaBackupRoundTripTest`, `SavedFeedBackupTest`, `BackupSchemaCoverageTest`, unedited).

Run: `vendor/bin/phpcs src/Service/Backup tests/Service/Backup`
Run: `php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/phpmd src/Service/Backup/Dto/EntryLine.php,src/Service/Backup/Pass/BackupPartWalk.php,src/Service/Backup/BackupLines.php text phpmd.xml.dist`
Expected: no output. (`EntryLine` keeps its existing class-level `ExcessiveParameterList` suppression for the constructor; `of()` takes three parameters.)

- [ ] **Step 8: Commit**

```bash
git add src/Service/Backup/Dto/EntryLine.php src/Service/Backup/Pass/BackupPartWalk.php \
  src/Service/Backup/BackupLines.php tests/Service/Backup/Dto/EntryLineTest.php
git commit -m "refactor(#2110): the entry line builds itself"
```

---

### Task 4: Headers and footers build themselves; `BackupLines` goes

**Files:**
- Create: `backend/tests/Service/Backup/Dto/FooterLineTest.php`
- Modify (tests, add only): `backend/tests/Service/Backup/Dto/BackupHeaderTest.php`
- Modify: `backend/src/Service/Backup/Dto/BackupHeader.php`, `BackupTotals.php`, `FooterLine.php`
- Modify: `backend/src/Service/Backup/Pass/BackupPartWalk.php` (constructor, `closePart()`, imports)
- Modify: `backend/src/Service/Backup/Factory/BackupPartWalkFactory.php`
- Modify: `backend/src/Service/Backup/AccountBackupExporter.php` (constructor, `foundation()`)
- Delete: `backend/src/Service/Backup/BackupLines.php`

**Interfaces:**
- Produces: `BackupHeader::foundation(BackupProvenanceModel $provenance, int $parts, BackupTotals $totals): self`, `BackupHeader::entryPart(BackupProvenanceModel $provenance, int $part): self`, `BackupHeader::toBackupLine(): string`, `BackupTotals::toHeaderFields(): array{entries: int, entryStates: int}`, `FooterLine::of(array<string, int> $counts): self`, `FooterLine::toBackupLine(): string`.

Characterization mapping:

| Old call | New call |
|---|---|
| `(new BackupLines())->foundationHeader(self::provenance(), 3, new BackupTotals(12, 5))` | `BackupHeader::foundation(self::provenance(), 3, new BackupTotals(12, 5))->toBackupLine()` |
| `(new BackupLines())->entryPartHeader(self::provenance(), 2)` | `BackupHeader::entryPart(self::provenance(), 2)->toBackupLine()` |
| `(new BackupLines())->footerLine([BackupSchema::KIND_ENTRY_STATE => 2, BackupSchema::KIND_ENTRY => 4])` | `FooterLine::of([BackupSchema::KIND_ENTRY_STATE => 2, BackupSchema::KIND_ENTRY => 4])->toBackupLine()` |

- [ ] **Step 1: Write the characterization tests (old calls first)**

In `BackupHeaderTest` add `use App\Service\Backup\Model\BackupProvenanceModel;` and `use App\Service\Backup\Support\BackupSchema;`, and add (the helper next to the existing private `header()`):

```php
    public function testAFoundationHeaderWritesItsPartsCountAndTotals(): void
    {
        self::assertSame(
            sprintf(
                '{"kind":"header","schemaVersion":%d,"createdAt":"2026-11-09T09:00:00+00:00",'
                . '"sourceUrl":"https://reader.example","sourceEmail":"owner@example.test",'
                . '"backupId":"backup-2110","part":0,"parts":3,"totals":{"entries":12,"entryStates":5}}',
                BackupSchema::VERSION,
            ),
            BackupHeader::foundation(self::provenance(), 3, new BackupTotals(12, 5))->toBackupLine(),
        );
    }

    public function testAnEntryPartHeaderWritesNeitherPartsNorTotals(): void
    {
        self::assertSame(
            sprintf(
                '{"kind":"header","schemaVersion":%d,"createdAt":"2026-11-09T09:00:00+00:00",'
                . '"sourceUrl":"https://reader.example","sourceEmail":"owner@example.test",'
                . '"backupId":"backup-2110","part":2,"parts":null,"totals":null}',
                BackupSchema::VERSION,
            ),
            BackupHeader::entryPart(self::provenance(), 2)->toBackupLine(),
        );
    }

    private static function provenance(): BackupProvenanceModel
    {
        return new BackupProvenanceModel(
            backupId: 'backup-2110',
            createdAt: new \DateTimeImmutable('2026-11-09T10:00:00+01:00'),
            sourceUrl: 'https://reader.example',
            sourceEmail: 'owner@example.test',
        );
    }
```

Create `backend/tests/Service/Backup/Dto/FooterLineTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Backup\Dto;

use App\Service\Backup\Dto\FooterLine;
use App\Service\Backup\Support\BackupSchema;
use PHPUnit\Framework\TestCase;

final class FooterLineTest extends TestCase
{
    public function testAnAbsentKindCountsZeroAndEveryKindKeepsItsPlace(): void
    {
        self::assertSame(
            '{"kind":"footer","counts":{"tag":0,"savedSearch":0,"feed":0,"subscription":0,"entry":4,'
            . '"entryState":2,"entryShare":0}}',
            FooterLine::of([BackupSchema::KIND_ENTRY_STATE => 2, BackupSchema::KIND_ENTRY => 4])->toBackupLine(),
        );
    }
}
```

For this step write the calls as the old ones (temporary `use App\Service\Backup\BackupLines;`).

- [ ] **Step 2: Run them against the old writer**

Run: `php bin/phpunit tests/Service/Backup/Dto/BackupHeaderTest.php tests/Service/Backup/Dto/FooterLineTest.php`
Expected: PASS. On a FAIL, correct the literal.

- [ ] **Step 3: Switch to the new calls**

Replace with the right-hand column; remove the temporary import.
Run: same command. Expected: FAIL — `Call to undefined method …BackupHeader::foundation()` / `FooterLine::of()`.

- [ ] **Step 4: Implement the header, the totals and the footer**

`BackupTotals.php` — after `fromHeaderLine()`:

```php
    /** @return array{entries: int, entryStates: int} */
    public function toHeaderFields(): array
    {
        return ['entries' => $this->entries, 'entryStates' => $this->entryStates];
    }
```

`BackupHeader.php` — add `use App\Service\Backup\Model\BackupProvenanceModel;`, `use App\Service\Backup\Support\BackupLineFormat;`, `use App\Service\Backup\Support\BackupSchema;`; after `fromLine()`:

```php
    public static function foundation(BackupProvenanceModel $provenance, int $parts, BackupTotals $totals): self
    {
        return new self(
            schemaVersion: BackupSchema::VERSION,
            createdAt: $provenance->createdAt,
            sourceUrl: $provenance->sourceUrl,
            sourceEmail: $provenance->sourceEmail,
            backupId: $provenance->backupId,
            part: 0,
            parts: $parts,
            totals: $totals,
        );
    }

    public static function entryPart(BackupProvenanceModel $provenance, int $part): self
    {
        return new self(
            schemaVersion: BackupSchema::VERSION,
            createdAt: $provenance->createdAt,
            sourceUrl: $provenance->sourceUrl,
            sourceEmail: $provenance->sourceEmail,
            backupId: $provenance->backupId,
            part: $part,
            parts: null,
            totals: null,
        );
    }

    public function toBackupLine(): string
    {
        return BackupLineFormat::encode([
            'kind' => BackupSchema::KIND_HEADER,
            'schemaVersion' => $this->schemaVersion,
            'createdAt' => BackupLineFormat::date($this->createdAt),
            'sourceUrl' => $this->sourceUrl,
            'sourceEmail' => $this->sourceEmail,
            'backupId' => $this->backupId,
            'part' => $this->part,
            'parts' => $this->parts,
            'totals' => $this->totals?->toHeaderFields(),
        ]);
    }
```

(Key order matches the old `[...headerFields(), 'parts' => …, 'totals' => …]` spread.)

`FooterLine.php` — add `use App\Service\Backup\Support\BackupLineFormat;` and `use App\Service\Backup\Support\BackupSchema;`; move `BackupLines::NO_COUNTS` here as the first class member:

```php
    private const array NO_COUNTS = [
        BackupSchema::KIND_TAG => 0,
        BackupSchema::KIND_SAVED_SEARCH => 0,
        BackupSchema::KIND_FEED => 0,
        BackupSchema::KIND_SUBSCRIPTION => 0,
        BackupSchema::KIND_ENTRY => 0,
        BackupSchema::KIND_ENTRY_STATE => 0,
        BackupSchema::KIND_ENTRY_SHARE => 0,
    ];
```

and after `fromLine()` (before the private `intCounts()`):

```php
    /**
     * @param array<string, int> $counts keyed by BackupSchema::KIND_*; an absent kind counts zero
     */
    public static function of(array $counts): self
    {
        return new self(counts: array_replace(self::NO_COUNTS, $counts));
    }

    public function toBackupLine(): string
    {
        return BackupLineFormat::encode(['kind' => BackupSchema::KIND_FOOTER, 'counts' => $this->counts]);
    }
```

- [ ] **Step 5: Run the DTO tests**

Run: `php bin/phpunit tests/Service/Backup/Dto`
Expected: PASS.

- [ ] **Step 6: Switch the walk, its factory and the exporter; delete `BackupLines`**

`BackupPartWalk.php`:
- imports: remove `use App\Service\Backup\BackupLines;`; add `use App\Service\Backup\Dto\BackupHeader;` (before `BackupTotals`) and `use App\Service\Backup\Dto\FooterLine;` (after `EntryStateLine`).
- constructor: delete the `private readonly BackupLines $lines,` parameter.
- `closePart()` becomes:

```php
    private function closePart(): BackupPartModel
    {
        ++$this->partsWritten;
        $header = BackupHeader::entryPart($this->provenance, $this->partsWritten)->toBackupLine();
        $footer = FooterLine::of([
            BackupSchema::KIND_ENTRY => $this->buffer->entryCount(),
            BackupSchema::KIND_ENTRY_STATE => $this->buffer->entryStateCount(),
            BackupSchema::KIND_ENTRY_SHARE => $this->buffer->entryShareCount(),
        ])->toBackupLine();

        return BackupPartModel::entries($this->partsWritten, $this->buffer->drain($header, $footer));
    }
```

`BackupPartWalkFactory.php`: remove `use App\Service\Backup\BackupLines;`, the `private BackupLines $lines,` constructor parameter, and the `$this->lines,` argument in `create()`.

`AccountBackupExporter.php`: add `use App\Service\Backup\Dto\BackupHeader;` and `use App\Service\Backup\Dto\FooterLine;`; remove the `private BackupLines $lines,` constructor parameter; `foundation()` becomes:

```php
    private function foundation(
        FoundationSnapshotModel $snapshot,
        BackupProvenanceModel $provenance,
        BackupPartWalk $walk,
    ): BackupPartModel {
        $header = BackupHeader::foundation($provenance, $walk->partsWritten() + 1, $walk->totals())->toBackupLine();

        return $snapshot->part($header, FooterLine::of($snapshot->counts())->toBackupLine());
    }
```

Then `git rm src/Service/Backup/BackupLines.php` and confirm nothing names either deleted class:

Run: `grep -rn "BackupLines\b\|EntryMarkLines" src tests config`
Expected: no output.

- [ ] **Step 7: Run the backup suite and the file gates**

Run: `php bin/phpunit tests/Service/Backup tests/Controller/Api/AccountBackupControllerTest.php`
Expected: PASS, safety-net tests unedited (`git diff --stat develop -- tests/Service/Backup/Support tests/Service/Backup/AccountBackupExporterTest.php tests/Service/Backup/*RoundTripTest.php tests/Service/Backup/GoldenBackupRestoreTest.php tests/Service/Backup/SavedFeedBackupTest.php tests/Fixtures/backup tests/Support/BackupFieldDeclarations.php tests/Support/FullyPopulatedAccount.php` prints nothing).

Run: `vendor/bin/phpcs src/Service/Backup tests/Service/Backup`
Run: `php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/phpmd src/Service/Backup text phpmd.xml.dist`
Expected: no output.

- [ ] **Step 8: Commit**

```bash
git add src/Service/Backup tests/Service/Backup/Dto
git commit -m "refactor(#2110): headers and footers build themselves; BackupLines goes"
```

---

### Task 5: Gates

**Files:** none planned; fix only what a gate reports, in the file it names.

- [ ] **Step 1: Coding standard**

Run: `composer cs`
Expected: no errors or warnings.

- [ ] **Step 2: PHPStan with the house rules**

Run: `bin/console cache:warmup && composer stan`
Expected: `[OK] No errors` — in particular no `ServiceRoleRule`, `NoToArrayInServicesRule`, `AbbreviatedNameRule` or `ServiceModuleCycleRule` finding. No baseline entry, no `@phpstan-ignore`.

- [ ] **Step 3: PHPMD**

Run: `composer md`
Expected: no finding in any touched file.

- [ ] **Step 4: phptramp**

Run: `composer tramp`
Expected: no new failing chain (the `$feedUrl` path `walkFeed → bufferBatch → *Line::of` has the same length `EntryMarkLines` had). If it reports one, first check `composer show larspohlmann/phptramp` per `CLAUDE.md` before blaming this branch.

- [ ] **Step 5: Full suite**

Run: `php bin/phpunit`
Expected: PASS. (The MySQL leg, `docker compose exec php composer test:parallel`, is the PR-time companion per `CLAUDE.md`.)

- [ ] **Step 6: Mutation testing over the changed files**

Run: `composer infection:diff`
Expected: MSI at or above `minMsi`. Escaped mutants in a `toBackupLine()`/`of()` are answered with a sharper format test in that DTO's test class, never by loosening `infection.json5`.

- [ ] **Step 7: PhpStorm inspections (if the PhpStorm MCP is available)**

Run `mcp__phpstorm__lint_files` on every changed PHP file; ERROR and WARNING block.

- [ ] **Step 8: Commit any gate fixes**

```bash
git add -A src/Service/Backup tests
git commit -m "refactor(#2110): satisfy the gates"
```

Only if Steps 1–7 required changes.

---

## Self-review notes

- Spec coverage: every kind in `BackupLines` (account, tag, savedSearch, feed, subscription, entry, foundation header, entry-part header, footer) and `EntryMarkLines` (entryState, entryShare) has a task; `SubscriptionTagReference` and `BackupTotals` cover the two nested shapes; both classes are deleted (Tasks 1, 4); callers switched (Tasks 1–4).
- Key order per kind was copied from `BackupLines.php` / `EntryMarkLines.php` at `4ac2fbdf3` and is enforced by a characterization literal proven against the old writer.
