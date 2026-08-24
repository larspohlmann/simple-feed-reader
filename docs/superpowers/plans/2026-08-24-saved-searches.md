# Saved Searches Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user save a search term, reach it again from a "Saved searches" sidebar section, see an unread-match count that refreshes after each feed refresh, and remove it from the results header.

**Architecture:** A new per-user `SavedSearch` entity (mirroring `Tag`) stores only the query — the trimmed `term` plus a `wholeWord` flag; results are never materialised. A `/api/saved-searches` JSON API lists (with a live unread-match count), creates, and deletes. The count reuses `EntryListRepository`'s own `LIKE` term-matching plus the shared `UnreadDql` predicate, so it is engine-independent (read state lives only in the DB, never in Meilisearch). On the client, a `SavedSearchesStore` mirrors `TagsStore`; the sidebar renders a labelled section that navigates via the existing `?q=` `search` selection kind; the results-list header gains a Save/Remove action; the count reloads wherever subscription counts reload. `SavedSearch` is a fully backed-up entity in the #556 account backup.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine ORM (backend), Angular 20 standalone + signals / Transloco (frontend), MySQL (prod/Docker) + SQLite (native tests), PHPUnit + Jest.

**Spec:** GitHub issue [#581](https://github.com/larspohlmann/simple-feed-reader/issues/581) — "Saved searches". The design was settled in a grilling session; the agreed decisions are copied into Global Constraints below.

## Global Constraints

- **Branch:** `feature/581-saved-searches` off `develop`. Commit messages are `type(#581): summary` (issue number is the scope, never a word scope or trailing parens). PR body says `Closes #581`.
- **`declare(strict_types=1);`** in every PHP file. PSR-12. PHPStan level max. Every touched `src` file must be **PHPMD-clean** before commit. Controllers stay thin (`ThinControllerRule`): no private method carrying responsibility — querying, response assembly, validation, entity mutation, and security decisions live in services/repositories/`src/Http/*Json.php`.
- **Clean Code:** intent-revealing names, no boolean flag *parameters* on methods (a `wholeWord` *field/value* on a DTO/entity is data, not a flag parameter), `final readonly` with constructor promotion as the house style, guard clauses over nesting, depend on injected interfaces.
- **Only the query is persisted, never the result set.** Counts are computed live.
- **Identity includes the whole-word flag.** `climate` and `climate ` (whole-word) are distinct. Unique per `(user_id, term, whole_word)`. Name is always the term; no rename.
- **Unread-match count** = a dedicated SQL `COUNT` (same `LIKE` term predicates as search + `UnreadDql` predicate), independent of the search engine.
- **Count freshness:** recompute on every refresh slice and after "mark all read"; brief staleness after a single-entry read is accepted.
- **Removal is header-only** in v1 (no sidebar kebab). **No drag-reorder** in v1 (a `position` column is reserved). Newest-saved-first order.
- **`SavedSearch` is a backed-up entity** in the #556 backup round-trip. **No** backward-compat shim for older backups: a backup written before this feature simply carries no `savedSearch` lines and restores zero saved searches.
- **Datetimes** — not applicable here (no datetime fields), but the house rule stands: naive UTC before persist.
- **Native iOS viability** — the API is Bearer-auth, stateless, JSON in / `application/problem+json` out; no browser-only inputs. Keep it so.
- **Frontend:** standalone components + signals, no NgModules. No hex colours or raw `px`/media literals in `.scss` outside `src/app/theme/`. Component styles in a sibling `.scss` (never inline). Run `npm run check` from `frontend/`.
- **Migrations verify separately** — the suite builds schema from ORM metadata, so a migration is never executed by a test. Verify it on a **named scratch DB**, never the dev database.

---

## File Structure

**Backend — create:**
- `backend/src/Entity/SavedSearch.php` — the entity (mirrors `Tag`).
- `backend/src/Repository/SavedSearchRepository.php` — `findForUser`, `findOneOwnedBy`, `findOneForUserByTerm`.
- `backend/src/Dto/SavedSearch/CreateSavedSearchRequest.php` — POST payload.
- `backend/src/Http/SavedSearchJson.php` — response serializer.
- `backend/src/Service/Search/SavedSearchMatchCounter.php` — builds the count query per saved search.
- `backend/src/Controller/Api/SavedSearchController.php` — list / create / delete.
- `backend/src/Service/Backup/Dto/SavedSearchLine.php` — backup reader DTO.
- `backend/migrations/Version20260824120000.php` — the `saved_search` table.
- Tests: `backend/tests/Repository/SavedSearchRepositoryTest.php`, `backend/tests/Repository/EntryUnreadMatchCountTest.php`, `backend/tests/Controller/Api/SavedSearchControllerTest.php`.

**Backend — modify:**
- `backend/src/Repository/EntryListRepository.php` — add `countUnreadMatchesForUser`.
- `backend/src/Service/Backup/BackupSchema.php`, `AccountBackupExporter.php`, `BackupReader.php`, `RestoreLoadPass.php`, `RestoreResult.php` — backup round-trip.
- `backend/tests/Support/BackupFieldDeclarations.php`, `tests/Support/FullyPopulatedAccount.php`, `tests/Service/Backup/BackupSchemaCoverageTest.php`, `tests/Service/Backup/AccountBackupExporterTest.php`, `tests/Service/Backup/AccountRestorerTest.php` — backup guards/tests.
- `docs/backup.md` — user-facing backup inventory.

**Frontend — create:**
- `frontend/src/app/reader/saved-searches.store.ts` — the store (mirrors `TagsStore`).
- `frontend/src/app/reader/saved-searches.store.spec.ts`, `frontend/src/app/reader/query.saved-search.spec.ts` — tests.

**Frontend — modify:**
- `frontend/src/app/reader/models.ts` — `SavedSearchDto`.
- `frontend/src/app/reader/reader-api.ts` — `savedSearches`, `createSavedSearch`, `deleteSavedSearch`.
- `frontend/src/app/reader/query.ts` — `savedSearchTerm`, `savedSearchParams` helpers.
- `frontend/src/app/reader/sidebar/sidebar.component.ts` / `.html` / `.scss` — the section.
- `frontend/src/app/reader/entry-list/entry-list.component.ts` / `.html` / `.scss` — the Save/Remove action.
- `frontend/src/app/reader/reader-shell.component.ts` / `.html` — wiring, computed, handlers, load hooks.
- `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` — new keys.

---

## Task ordering and dependencies

Backend first (Tasks 1–5), then frontend (Tasks 6–10), then final verification (Task 11). Task 1 (entity + backup) must land whole because mapping the entity reddens `BackupSchemaCoverageTest` until a scope decision exists. Task 3 (count method) is a prerequisite of Task 4 (the counter service used by the controller).

---

### Task 0: Branch

- [ ] **Step 1: Create the feature branch off develop**

First check no other session is mid-edit (concurrent sessions share this checkout).

```bash
cd /Users/lars/Documents/work/eigenes/simple-feed-reader && git checkout develop && git pull && git checkout -b feature/581-saved-searches
```

---

### Task 1: `SavedSearch` entity, repository, and backup round-trip

The entity cannot be added alone: the moment it is mapped, `BackupSchemaCoverageTest::testEveryMappedEntityCarriesAScopeDecision` fails until a backup scope decision exists, and `testEveryBackedUpFieldReachesTheExportersOutput` requires the exporter to emit it and `FullyPopulatedAccount` to populate it. So the entity and its full backup wiring land together, green.

**Files:**
- Create: `backend/src/Entity/SavedSearch.php`
- Create: `backend/src/Repository/SavedSearchRepository.php`
- Create: `backend/src/Service/Backup/Dto/SavedSearchLine.php`
- Modify: `backend/src/Service/Backup/BackupSchema.php`
- Modify: `backend/src/Service/Backup/AccountBackupExporter.php`
- Modify: `backend/src/Service/Backup/BackupReader.php`
- Modify: `backend/src/Service/Backup/RestoreLoadPass.php`
- Modify: `backend/src/Service/Backup/RestoreResult.php`
- Modify: `backend/tests/Support/BackupFieldDeclarations.php`
- Modify: `backend/tests/Support/FullyPopulatedAccount.php`
- Modify: `backend/tests/Service/Backup/BackupSchemaCoverageTest.php`
- Modify: `backend/tests/Service/Backup/AccountBackupExporterTest.php`
- Modify: `backend/tests/Service/Backup/AccountRestorerTest.php`
- Modify: `docs/backup.md`
- Test: `backend/tests/Repository/SavedSearchRepositoryTest.php`

**Interfaces:**
- Produces: `App\Entity\SavedSearch` with `__construct(User $user, string $term, bool $wholeWord)`, getters `getId(): ?int`, `getUser(): User`, `getTerm(): string`, `isWholeWord(): bool`, `getPosition(): int`, and `setPosition(int): void`.
- Produces: `App\Repository\SavedSearchRepository` with `findForUser(int $userId): array` (newest first), `findOneOwnedBy(int $id, int $userId): ?SavedSearch`, `findOneForUserByTerm(int $userId, string $term, bool $wholeWord): ?SavedSearch`.
- Produces: `App\Service\Backup\Dto\SavedSearchLine` with public `string $term`, `bool $wholeWord`, `int $position` and `static fromLine(array $line): self`.
- Produces: `BackupSchema::KIND_SAVED_SEARCH = 'savedSearch'`.

- [ ] **Step 1: Write the failing repository test**

Create `backend/tests/Repository/SavedSearchRepositoryTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\SavedSearchRepository;
use App\Tests\DbTestCase;

final class SavedSearchRepositoryTest extends DbTestCase
{
    private function repo(): SavedSearchRepository
    {
        $repo = $this->em->getRepository(SavedSearch::class);
        self::assertInstanceOf(SavedSearchRepository::class, $repo);

        return $repo;
    }

    public function testFindForUserReturnsNewestFirstAndScopesToUser(): void
    {
        $owner = new User('owner@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $stranger = new User('stranger@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($owner);
        $this->em->persist($stranger);

        $first = new SavedSearch($owner, 'climate', false);
        $second = new SavedSearch($owner, 'rust lang', true);
        $strangers = new SavedSearch($stranger, 'not mine', false);
        $this->em->persist($first);
        $this->em->persist($second);
        $this->em->persist($strangers);
        $this->em->flush();

        $rows = $this->repo()->findForUser((int) $owner->getId());

        self::assertCount(2, $rows);
        self::assertSame('rust lang', $rows[0]->getTerm()); // newest first
        self::assertSame('climate', $rows[1]->getTerm());
    }

    public function testFindOneForUserByTermDistinguishesWholeWord(): void
    {
        $user = new User('u@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($user);
        $this->em->persist(new SavedSearch($user, 'punk', false));
        $this->em->persist(new SavedSearch($user, 'punk', true));
        $this->em->flush();

        $userId = (int) $user->getId();
        self::assertNotNull($this->repo()->findOneForUserByTerm($userId, 'punk', false));
        self::assertNotNull($this->repo()->findOneForUserByTerm($userId, 'punk', true));
        self::assertNull($this->repo()->findOneForUserByTerm($userId, 'punk', false)?->isWholeWord() ? null : $this->repo()->findOneForUserByTerm($userId, 'punk', false));
        self::assertNull($this->repo()->findOneForUserByTerm($userId, 'missing', false));
    }

    public function testFindOneOwnedByRejectsAnotherUser(): void
    {
        $owner = new User('owner2@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $stranger = new User('stranger2@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($owner);
        $this->em->persist($stranger);
        $saved = new SavedSearch($owner, 'mine', false);
        $this->em->persist($saved);
        $this->em->flush();

        self::assertNotNull($this->repo()->findOneOwnedBy((int) $saved->getId(), (int) $owner->getId()));
        self::assertNull($this->repo()->findOneOwnedBy((int) $saved->getId(), (int) $stranger->getId()));
    }
}
```

> Note the awkward line in `testFindOneForUserByTermDistinguishesWholeWord` is deliberately simplified in Step 3's run; if it reads poorly after green, simplify the third assertion to `self::assertSame(true, $this->repo()->findOneForUserByTerm($userId, 'punk', true)?->isWholeWord());`.

- [ ] **Step 2: Run it to confirm it fails**

Run (from `backend/`): `php bin/phpunit tests/Repository/SavedSearchRepositoryTest.php`
Expected: FAIL — `App\Entity\SavedSearch` / `App\Repository\SavedSearchRepository` do not exist.

- [ ] **Step 3: Create the entity**

Create `backend/src/Entity/SavedSearch.php`:

```php
<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\SavedSearchRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: SavedSearchRepository::class)]
#[ORM\Table(name: 'saved_search')]
#[ORM\UniqueConstraint(name: 'uniq_saved_search_user_term_word', columns: ['user_id', 'term', 'whole_word'])]
class SavedSearch
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 100)]
    private string $term;

    /** True when the search matches whole words only (a trailing space in the raw query). */
    #[ORM\Column(name: 'whole_word', options: ['default' => false])]
    private bool $wholeWord;

    /** Reserved for a future sidebar reorder; unused for ordering in v1. */
    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    public function __construct(User $user, string $term, bool $wholeWord)
    {
        $this->user = $user;
        $this->term = $term;
        $this->wholeWord = $wholeWord;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTerm(): string
    {
        return $this->term;
    }

    public function isWholeWord(): bool
    {
        return $this->wholeWord;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }
}
```

- [ ] **Step 4: Create the repository**

Create `backend/src/Repository/SavedSearchRepository.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SavedSearch;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SavedSearch>
 */
class SavedSearchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavedSearch::class);
    }

    /**
     * @return list<SavedSearch> the user's saved searches, newest saved first
     */
    public function findForUser(int $userId): array
    {
        /** @var list<SavedSearch> $rows */
        $rows = $this->createQueryBuilder('savedSearch')
            ->andWhere('savedSearch.user = :userId')->setParameter('userId', $userId)
            ->orderBy('savedSearch.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function findOneOwnedBy(int $id, int $userId): ?SavedSearch
    {
        /** @var SavedSearch|null $row */
        $row = $this->createQueryBuilder('savedSearch')
            ->andWhere('savedSearch.id = :id')->setParameter('id', $id)
            ->andWhere('savedSearch.user = :userId')->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }

    public function findOneForUserByTerm(int $userId, string $term, bool $wholeWord): ?SavedSearch
    {
        /** @var SavedSearch|null $row */
        $row = $this->createQueryBuilder('savedSearch')
            ->andWhere('savedSearch.user = :userId')->setParameter('userId', $userId)
            ->andWhere('savedSearch.term = :term')->setParameter('term', $term)
            ->andWhere('savedSearch.wholeWord = :wholeWord')->setParameter('wholeWord', $wholeWord)
            ->getQuery()
            ->getOneOrNullResult();

        return $row;
    }
}
```

- [ ] **Step 5: Run the repository test to confirm it passes**

Run (from `backend/`): `php bin/phpunit tests/Repository/SavedSearchRepositoryTest.php`
Expected: PASS. (If the third assertion in `testFindOneForUserByTermDistinguishesWholeWord` reads awkwardly, simplify it as noted in Step 1.)

- [ ] **Step 6: Confirm the backup coverage guard now fails**

Run (from `backend/`): `php bin/phpunit tests/Service/Backup/BackupSchemaCoverageTest.php`
Expected: FAIL — `App\Entity\SavedSearch is mapped but no backup scope decision exists for it.` This is the #556 guard doing its job. The remaining steps make it green by backing the entity up fully.

- [ ] **Step 7: Add the backup kind constant**

In `backend/src/Service/Backup/BackupSchema.php`, add the constant after `KIND_TAG`:

```php
    public const string KIND_TAG = 'tag';
    public const string KIND_SAVED_SEARCH = 'savedSearch';
```

- [ ] **Step 8: Create the reader DTO**

Create `backend/src/Service/Backup/Dto/SavedSearchLine.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Backup\Dto;

/**
 * One of the account's saved searches.
 */
final readonly class SavedSearchLine
{
    public function __construct(
        public string $term,
        public bool $wholeWord,
        public int $position,
    ) {
    }

    /**
     * @param array<string, mixed> $line
     */
    public static function fromLine(array $line): self
    {
        return new self(
            term: LineField::string($line, 'term'),
            wholeWord: LineField::bool($line, 'wholeWord'),
            position: LineField::int($line, 'position'),
        );
    }
}
```

- [ ] **Step 9: Emit saved searches from the exporter**

In `backend/src/Service/Backup/AccountBackupExporter.php`:

1. Add the repository to the constructor (after `TagRepository $tags`):

```php
        private TagRepository $tags,
        private SavedSearchRepository $savedSearches,
```

Add the import at the top: `use App\Repository\SavedSearchRepository;` and `use App\Entity\SavedSearch;`.

2. Add the count key to the `$counts` literal in `lines()` (after `'tag' => 0,`):

```php
        $counts = ['tag' => 0, 'savedSearch' => 0, 'feed' => 0, 'subscription' => 0, 'entry' => 0, 'entryState' => 0];
```

3. Emit saved searches immediately after the tags loop, before the subscriptions fetch:

```php
        foreach ($this->tags->findForUser($userId) as $tag) {
            yield $this->encode($this->tagLine($tag));
            ++$counts['tag'];
        }

        foreach ($this->savedSearches->findForUser($userId) as $savedSearch) {
            yield $this->encode($this->savedSearchLine($savedSearch));
            ++$counts['savedSearch'];
        }
```

4. Add the line builder next to `tagLine()`:

```php
    /**
     * @return array<string, mixed>
     */
    private function savedSearchLine(SavedSearch $savedSearch): array
    {
        return [
            'kind' => BackupSchema::KIND_SAVED_SEARCH,
            'term' => $savedSearch->getTerm(),
            'wholeWord' => $savedSearch->isWholeWord(),
            'position' => $savedSearch->getPosition(),
        ];
    }
```

- [ ] **Step 10: Teach the reader the new kind**

In `backend/src/Service/Backup/BackupReader.php`:

1. Add the import: `use App\Service\Backup\Dto\SavedSearchLine;`.

2. Renumber `KIND_RANK` to slot `savedSearch` right after `tag`:

```php
    private const array KIND_RANK = [
        BackupSchema::KIND_HEADER => 0,
        BackupSchema::KIND_ACCOUNT => 1,
        BackupSchema::KIND_TAG => 2,
        BackupSchema::KIND_SAVED_SEARCH => 3,
        BackupSchema::KIND_FEED => 4,
        BackupSchema::KIND_SUBSCRIPTION => 5,
        BackupSchema::KIND_ENTRY => 6,
        BackupSchema::KIND_ENTRY_STATE => 7,
        BackupSchema::KIND_FOOTER => 8,
    ];
```

3. Add `savedSearch` to `COUNTED_KINDS` after `KIND_TAG`:

```php
    private const array COUNTED_KINDS = [
        BackupSchema::KIND_TAG,
        BackupSchema::KIND_SAVED_SEARCH,
        BackupSchema::KIND_FEED,
        BackupSchema::KIND_SUBSCRIPTION,
        BackupSchema::KIND_ENTRY,
        BackupSchema::KIND_ENTRY_STATE,
    ];
```

4. Add the `toDto()` match arm after the `KIND_TAG` arm:

```php
            BackupSchema::KIND_TAG => TagLine::fromLine($decoded),
            BackupSchema::KIND_SAVED_SEARCH => SavedSearchLine::fromLine($decoded),
```

- [ ] **Step 11: Restore saved searches**

In `backend/src/Service/Backup/RestoreLoadPass.php`:

1. Add imports: `use App\Entity\SavedSearch;` and `use App\Service\Backup\Dto\SavedSearchLine;`.

2. Add a counter to the `$counts` field and update its docblock:

```php
    /** @var array{tags: int, savedSearches: int, feeds: int, subscriptions: int} */
    private array $counts = ['tags' => 0, 'savedSearches' => 0, 'feeds' => 0, 'subscriptions' => 0];
```

3. Add the dispatch arm in `accept()` after the `TagLine` arm:

```php
            $line instanceof TagLine => $this->loadTag($line),
            $line instanceof SavedSearchLine => $this->loadSavedSearch($line),
```

4. Add the loader next to `loadTag()`:

```php
    private function loadSavedSearch(SavedSearchLine $line): void
    {
        $savedSearch = new SavedSearch($this->user, $line->term, $line->wholeWord);
        $savedSearch->setPosition($line->position);
        $this->em->persist($savedSearch);
        ++$this->counts['savedSearches'];
    }
```

5. In `run()`, pass the new count into `RestoreResult` (add `savedSearches: $this->counts['savedSearches'],` alongside `tags:`).

- [ ] **Step 12: Extend `RestoreResult`**

In `backend/src/Service/Backup/RestoreResult.php`, add a `savedSearches` constructor-promoted `int` property immediately after `tags` (keep the same visibility/`readonly` as the siblings). If `RestoreResult` is consumed anywhere that constructs it positionally besides `RestoreLoadPass::run()`, update those call sites (grep: `new RestoreResult(`).

- [ ] **Step 13: Declare the backup field mapping**

In `backend/tests/Support/BackupFieldDeclarations.php`:

1. Add the import `use App\Entity\SavedSearch;`.

2. Add to `BACKED_UP` after the `Tag::class` entry:

```php
        SavedSearch::class => [
            'term' => 'term', 'wholeWord' => 'wholeWord', 'position' => 'position',
        ],
```

3. Add to `KIND_OF` after the `Tag::class` entry:

```php
        SavedSearch::class => BackupSchema::KIND_SAVED_SEARCH,
```

- [ ] **Step 14: Declare the owner-pointer drop and footer count in the coverage test**

In `backend/tests/Service/Backup/BackupSchemaCoverageTest.php`:

1. Add the import `use App\Entity\SavedSearch;`.

2. Add to `NOT_BACKED_UP` after the `Tag::class` entry (the owning `user` is the restoring account, not a backed-up field):

```php
        SavedSearch::class => [
            'user' => self::OWNER_IS_THE_RESTORING_ACCOUNT,
        ],
```

3. Add the footer count key to `FILE_SCAFFOLDING`'s `KIND_FOOTER` list:

```php
        BackupSchema::KIND_FOOTER => [
            'counts', 'counts.tag', 'counts.savedSearch', 'counts.feed', 'counts.subscription',
            'counts.entry', 'counts.entryState',
        ],
```

- [ ] **Step 15: Populate a saved search in the fully-populated account**

In `backend/tests/Support/FullyPopulatedAccount.php`:

1. Add the import `use App\Entity\SavedSearch;`.

2. In `create()`, after the tag is persisted, add:

```php
        $this->em->persist($this->savedSearchFor($user));
```

3. Add the factory method (every backed-up field non-null/non-default):

```php
    private function savedSearchFor(User $user): SavedSearch
    {
        $savedSearch = new SavedSearch($user, 'climate policy', true);
        $savedSearch->setPosition(3);

        return $savedSearch;
    }
```

- [ ] **Step 16: Run the backup coverage guard — expect green**

Run (from `backend/`): `php bin/phpunit tests/Service/Backup/BackupSchemaCoverageTest.php`
Expected: PASS.

- [ ] **Step 17: Extend the exporter test**

In `backend/tests/Service/Backup/AccountBackupExporterTest.php`:

1. Update the kind-order assertion to include `savedSearch` after `tag`:

```php
        self::assertSame(
            ['header', 'account', 'tag', 'savedSearch', 'feed', 'subscription', 'entry', 'entryState', 'footer'],
            array_column($lines, 'kind'),
        );
```

2. Update the footer-counts assertion to include the new key (match the actual seeded fixture's count — if the fixture seeds one saved search, use `1`):

```php
        self::assertSame(
            ['tag' => 1, 'savedSearch' => 1, 'feed' => 1, 'subscription' => 1, 'entry' => 1, 'entryState' => 1],
            $lines[7]['counts'],
        );
```

> If this test's fixture does not seed a saved search, add one to whatever helper it uses to build the account (search the test for how it seeds the tag and mirror it), and adjust the `$lines[...]` index arithmetic — inserting a `savedSearch` line after the `tag` line shifts every later index by one. Update the index used to read the footer accordingly (it is the last line; prefer `end($lines)` or `$lines[array_key_last($lines)]` if the test does not already).

3. Add an assertion on the saved-search line content (it is `$lines[3]` when one tag precedes it):

```php
        self::assertSame('savedSearch', $lines[3]['kind']);
        self::assertSame('climate policy', $lines[3]['term']);
        self::assertTrue($lines[3]['wholeWord']);
        self::assertSame(3, $lines[3]['position']);
```

- [ ] **Step 18: Extend the restorer round-trip test**

In `backend/tests/Service/Backup/AccountRestorerTest.php`:

1. Add the import `use App\Entity\SavedSearch;`.

2. In `seedRichAccount()` (the "two of each" seeding helper), persist two saved searches for the account, mirroring how it seeds two tags. Give them distinguishable, non-default values, e.g.:

```php
        $this->em->persist(new SavedSearch($user, 'climate', false));
        $whole = new SavedSearch($user, 'rust lang', true);
        $whole->setPosition(1);
        $this->em->persist($whole);
```

3. Wherever the test asserts `$result->tags === 2`, add `self::assertSame(2, $result->savedSearches);`.

4. In `fixtureRowsOf()` add a `SavedSearch::class` shape so the generic `testEveryBackedUpFieldSurvivesTheRestoreRoundTrip()` covers it (mirror the `Tag` return shape — map each backed-up field to its expected restored value, ordered to match the query the assertion uses). Add a `$this->assertFieldsRoundTripped(SavedSearch::class, ...)` call if the test uses per-entity assertions; otherwise the `BACKED_UP`-driven generic assertion suffices once `fixtureRowsOf()` knows the entity.

> Read the surrounding helper before editing — copy the exact idiom used for `Tag` in `seedRichAccount`, `fixtureRowsOf`, and the round-trip assertions. Do **not** add the new field to any frozen golden fixture under `backend/tests/Fixtures/backup/` — their absence of `savedSearch` is the backward-compat test.

- [ ] **Step 19: Update the user-facing backup doc**

In `docs/backup.md`:

1. Add `savedSearch` to the prose `kind` enumeration (the line listing `header`, `account`, `tag`, `feed`, …).

2. Add a row to the section-5 "What a backup carries" table, after the `tag` row:

```
| `savedSearch` | Each saved search: `term`, `wholeWord` and `position`. |
```

- [ ] **Step 20: Run the full backup suite + lint gates**

Run (from `backend/`):

```bash
php bin/phpunit tests/Service/Backup tests/Repository/SavedSearchRepositoryTest.php
```

Expected: PASS. Then warm the cache and run the quality gates over the touched files:

```bash
bin/console cache:warmup && composer check && composer md
```

Expected: no PHPCS/PHPStan/tramp findings, PHPMD clean.

- [ ] **Step 21: Run PhpStorm inspections on changed PHP**

Run `mcp__phpstorm__lint_files` on the created/modified `.php` files. Block on ERROR and WARNING; fix or justify with a commented `@noinspection`.

- [ ] **Step 22: Commit**

```bash
git add backend/src/Entity/SavedSearch.php backend/src/Repository/SavedSearchRepository.php backend/src/Service/Backup backend/tests docs/backup.md && git commit -m "feat(#581): add SavedSearch entity with full backup round-trip"
```

---

### Task 2: `countUnreadMatchesForUser` on `EntryListRepository`

**Files:**
- Modify: `backend/src/Repository/EntryListRepository.php`
- Test: `backend/tests/Repository/EntryUnreadMatchCountTest.php`

**Interfaces:**
- Consumes: `App\Repository\EntrySearchQuery` (existing), `App\Service\Search\SearchTerms` (existing).
- Produces: `EntryListRepository::countUnreadMatchesForUser(EntrySearchQuery $query): int`.

- [ ] **Step 1: Write the failing test**

Create `backend/tests/Repository/EntryUnreadMatchCountTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Entry;
use App\Entity\EntryState;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\EntryListRepository;
use App\Repository\EntrySearchQuery;
use App\Service\Search\SearchTerms;
use App\Tests\DbTestCase;

final class EntryUnreadMatchCountTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new User('counter@example.com', new \DateTimeImmutable('2026-07-01T00:00:00Z'));
        $this->em->persist($this->user);

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);

        $this->em->persist(
            new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')),
        );
        $this->em->flush();
    }

    private function repo(): EntryListRepository
    {
        $repo = self::getContainer()->get(EntryListRepository::class);
        self::assertInstanceOf(EntryListRepository::class, $repo);

        return $repo;
    }

    private function entry(string $guid, string $title, bool $read): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            $title,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $this->em->persist($entry);
        if ($read) {
            $state = new EntryState($this->user, $entry);
            $state->setIsRead(true);
            $this->em->persist($state);
        }
        $this->em->flush();

        return $entry;
    }

    private function count(string $input): int
    {
        return $this->repo()->countUnreadMatchesForUser(new EntrySearchQuery(
            userId: (int) $this->user->getId(),
            terms: SearchTerms::fromInput($input),
        ));
    }

    public function testCountsOnlyUnreadMatches(): void
    {
        $this->entry('a', 'Climate policy update', false); // unread match
        $this->entry('b', 'Climate summit recap', false);  // unread match
        $this->entry('c', 'Climate deal signed', true);     // read match -> excluded
        $this->entry('d', 'Unrelated cooking post', false); // no match

        self::assertSame(2, $this->count('climate'));
    }

    public function testWholeWordCountIsStricterThanSubstring(): void
    {
        $this->entry('e', 'A punk revival', false);  // whole word "punk"
        $this->entry('f', 'Steampunk gadgets', false); // substring only

        self::assertSame(2, $this->count('punk'));   // substring: both
        self::assertSame(1, $this->count('punk '));  // trailing space = whole word: only "A punk revival"
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run (from `backend/`): `php bin/phpunit tests/Repository/EntryUnreadMatchCountTest.php`
Expected: FAIL — `countUnreadMatchesForUser` does not exist.

- [ ] **Step 3: Add the count method**

In `backend/src/Repository/EntryListRepository.php`, add this method next to `searchForUser`. It reuses `rowQueryBuilder` (aliases `e`/`s`/`es`, `:user` already bound; `s` is the INNER-join IDOR gate), overwrites the projection with a `COUNT`, reuses `applyTerms` for identical matching, and applies the shared unread predicate exactly as `applyView`'s `'unread'` branch does. `Types` and `UnreadDql` are already available in this file (imported / same namespace):

```php
    /**
     * How many unread entries match this saved search. Reuses searchForUser's
     * term matching so the badge tracks the LIKE result set, plus the shared
     * "unread" predicate. Deliberately engine-independent: read state is
     * per-user and lives only in the database, never in the search index.
     */
    public function countUnreadMatchesForUser(EntrySearchQuery $query): int
    {
        $qb = $this->rowQueryBuilder($query->userId)
            ->select('COUNT(DISTINCT e.id)');
        $this->applyTerms($qb, $query->terms);
        $qb->andWhere(UnreadDql::predicate())->setParameter('readFalse', false, Types::BOOLEAN);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
```

- [ ] **Step 4: Run the test to confirm it passes**

Run (from `backend/`): `php bin/phpunit tests/Repository/EntryUnreadMatchCountTest.php`
Expected: PASS.

- [ ] **Step 5: Lint + inspections**

Run (from `backend/`): `composer check && composer md`. Then `mcp__phpstorm__lint_files` on `EntryListRepository.php`. Fix any finding in the touched file (PHPMD-clean standing rule).

- [ ] **Step 6: Commit**

```bash
git add backend/src/Repository/EntryListRepository.php backend/tests/Repository/EntryUnreadMatchCountTest.php && git commit -m "feat(#581): count unread entries matching a saved search"
```

---

### Task 3: `SavedSearchMatchCounter` service

A thin service that turns saved searches into unread counts, keeping the controller thin. Covered by the controller functional test in Task 4; this task adds a focused unit/integration test too so the whole-word reconstruction branch is killed by a test regardless of controller wiring.

**Files:**
- Create: `backend/src/Service/Search/SavedSearchMatchCounter.php`
- Test: covered by `backend/tests/Controller/Api/SavedSearchControllerTest.php` (Task 4) and by an assertion added here.

**Interfaces:**
- Consumes: `EntryListRepository::countUnreadMatchesForUser`, `SearchTerms::fromInput`, `EntrySearchQuery`, `App\Entity\SavedSearch`.
- Produces: `SavedSearchMatchCounter::countsFor(array $savedSearches, int $userId): array` (`array<int,int>` keyed by saved-search id) and `countFor(SavedSearch $savedSearch, int $userId): int`.

- [ ] **Step 1: Create the service**

Create `backend/src/Service/Search/SavedSearchMatchCounter.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\SavedSearch;
use App\Repository\EntryListRepository;
use App\Repository\EntrySearchQuery;

/**
 * The live unread-match count behind each saved search's sidebar badge.
 * Rebuilds the raw query string (a trailing space is the whole-word signal)
 * so the count matches exactly what opening the search would list.
 */
final readonly class SavedSearchMatchCounter
{
    public function __construct(private EntryListRepository $entries)
    {
    }

    /**
     * @param list<SavedSearch> $savedSearches
     *
     * @return array<int, int> saved-search id => unread match count
     */
    public function countsFor(array $savedSearches, int $userId): array
    {
        $counts = [];
        foreach ($savedSearches as $savedSearch) {
            $counts[(int) $savedSearch->getId()] = $this->countFor($savedSearch, $userId);
        }

        return $counts;
    }

    public function countFor(SavedSearch $savedSearch, int $userId): int
    {
        $rawQuery = $savedSearch->isWholeWord()
            ? $savedSearch->getTerm() . ' '
            : $savedSearch->getTerm();

        return $this->entries->countUnreadMatchesForUser(
            new EntrySearchQuery($userId, SearchTerms::fromInput($rawQuery)),
        );
    }
}
```

- [ ] **Step 2: Verify it autowires and lint**

Run (from `backend/`): `bin/console cache:warmup && composer check && composer md`. The service autowires by type-hint (no `services.yaml` change needed). Run `mcp__phpstorm__lint_files` on the new file.

> No standalone commit yet — this service has no behavioural test on its own; it is exercised by Task 4's functional test, which includes a whole-word saved search with matches to kill the `? ' ' : ''` branch. Commit it together with Task 4.

---

### Task 4: `/api/saved-searches` — DTO, JSON, controller

**Files:**
- Create: `backend/src/Dto/SavedSearch/CreateSavedSearchRequest.php`
- Create: `backend/src/Http/SavedSearchJson.php`
- Create: `backend/src/Controller/Api/SavedSearchController.php`
- Test: `backend/tests/Controller/Api/SavedSearchControllerTest.php`

**Interfaces:**
- Consumes: `SavedSearchRepository`, `SavedSearchMatchCounter`, `EntityManagerInterface`, `App\Entity\SavedSearch`, `App\Entity\User`.
- Produces: routes `api_saved_searches_list` (`GET /api/saved-searches`), `api_saved_searches_create` (`POST /api/saved-searches`), `api_saved_searches_delete` (`DELETE /api/saved-searches/{id}`).
- Produces JSON: list → `{ savedSearches: [{ id, term, wholeWord, position, unreadCount }] }`; create → `{ savedSearch: { … } }` (201 new, 200 already-existing); delete → 204.

- [ ] **Step 1: Write the failing functional test**

Create `backend/tests/Controller/Api/SavedSearchControllerTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\SavedSearch;
use App\Entity\Subscription;
use App\Entity\User;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SavedSearchControllerTest extends WebTestCase
{
    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);

        return $em;
    }

    private function userFactory(): UserFactory
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return new UserFactory($this->em(), $hasher);
    }

    /** @return array<string, string> */
    private function authHeaderFor(User $user): array
    {
        $tokens = self::getContainer()->get(JWTTokenManagerInterface::class);
        self::assertInstanceOf(JWTTokenManagerInterface::class, $tokens);

        return [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $tokens->create($user),
            'CONTENT_TYPE' => 'application/json',
        ];
    }

    public function testAnonymousIsRejected(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/saved-searches');
        self::assertResponseStatusCodeSame(401);
    }

    public function testCreateListWithUnreadCountAndDelete(): void
    {
        $client = self::createClient();
        $user = $this->userFactory()->create('saver@example.com');
        $headers = $this->authHeaderFor($user);

        // Seed a subscribed feed with one unread matching entry.
        $em = $this->em();
        $feed = new Feed('https://example.com/f.xml');
        $feed->setTitle('Example');
        $em->persist($feed);
        $em->persist(new Subscription($user, $feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $entry = new Entry(
            $feed,
            'g1',
            'https://example.com/g1',
            'A punk revival',
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
            new \DateTimeImmutable('2026-07-10T00:00:00Z'),
        );
        $em->persist($entry);
        $em->flush();

        // Create a whole-word saved search.
        $client->request(
            'POST',
            '/api/saved-searches',
            server: $headers,
            content: json_encode(['term' => 'punk', 'wholeWord' => true], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(201);
        $created = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($created);
        self::assertSame('punk', $created['savedSearch']['term']);
        self::assertTrue($created['savedSearch']['wholeWord']);
        $savedId = $created['savedSearch']['id'];

        // Duplicate create is idempotent (200, same id).
        $client->request(
            'POST',
            '/api/saved-searches',
            server: $headers,
            content: json_encode(['term' => 'punk', 'wholeWord' => true], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(200);
        $again = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($savedId, $again['savedSearch']['id']);

        // List carries the live unread-match count (whole-word "punk" matches the one unread entry).
        $client->request('GET', '/api/saved-searches', server: $headers);
        self::assertResponseIsSuccessful();
        $list = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(1, $list['savedSearches']);
        self::assertSame('punk', $list['savedSearches'][0]['term']);
        self::assertSame(1, $list['savedSearches'][0]['unreadCount']);

        // Delete.
        $client->request('DELETE', '/api/saved-searches/' . $savedId, server: $headers);
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/saved-searches', server: $headers);
        $empty = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertCount(0, $empty['savedSearches']);
    }

    public function testValidationRejectsShortTerm(): void
    {
        $client = self::createClient();
        $headers = $this->authHeaderFor($this->userFactory()->create('shorty@example.com'));
        $client->request(
            'POST',
            '/api/saved-searches',
            server: $headers,
            content: json_encode(['term' => 'ab', 'wholeWord' => false], \JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(422);
    }

    public function testDeleteAnotherUsersSavedSearchIs404(): void
    {
        $client = self::createClient();
        $em = $this->em();
        $owner = $this->userFactory()->create('owner3@example.com');
        $saved = new SavedSearch($owner, 'private', false);
        $em->persist($saved);
        $em->flush();

        $headers = $this->authHeaderFor($this->userFactory()->create('intruder3@example.com'));
        $client->request('DELETE', '/api/saved-searches/' . $saved->getId(), server: $headers);
        self::assertResponseStatusCodeSame(404); // not 403 — do not reveal existence
    }
}
```

- [ ] **Step 2: Run it to confirm it fails**

Run (from `backend/`): `php bin/phpunit tests/Controller/Api/SavedSearchControllerTest.php`
Expected: FAIL — route/controller/DTO/JSON do not exist.

- [ ] **Step 3: Create the request DTO**

Create `backend/src/Dto/SavedSearch/CreateSavedSearchRequest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Dto\SavedSearch;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class CreateSavedSearchRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(min: 3, max: 100)]
        public string $term = '',
        public bool $wholeWord = false,
    ) {
    }
}
```

- [ ] **Step 4: Create the JSON serializer**

Create `backend/src/Http/SavedSearchJson.php`:

```php
<?php

declare(strict_types=1);

namespace App\Http;

use App\Entity\SavedSearch;

final class SavedSearchJson
{
    /**
     * @return array{id: int|null, term: string, wholeWord: bool, position: int, unreadCount: int}
     */
    public static function one(SavedSearch $savedSearch, int $unreadCount): array
    {
        return [
            'id' => $savedSearch->getId(),
            'term' => $savedSearch->getTerm(),
            'wholeWord' => $savedSearch->isWholeWord(),
            'position' => $savedSearch->getPosition(),
            'unreadCount' => $unreadCount,
        ];
    }
}
```

- [ ] **Step 5: Create the controller**

Create `backend/src/Controller/Api/SavedSearchController.php`. Each action reads the request, delegates, and returns a response — no private methods carrying work (`ThinControllerRule`):

```php
<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Dto\SavedSearch\CreateSavedSearchRequest;
use App\Entity\SavedSearch;
use App\Entity\User;
use App\Http\SavedSearchJson;
use App\Repository\SavedSearchRepository;
use App\Service\Search\SavedSearchMatchCounter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/saved-searches')]
final readonly class SavedSearchController
{
    public function __construct(
        private SavedSearchRepository $savedSearches,
        private SavedSearchMatchCounter $counter,
        private EntityManagerInterface $em,
    ) {
    }

    #[Route('', name: 'api_saved_searches_list', methods: ['GET'])]
    public function list(#[CurrentUser] User $user): JsonResponse
    {
        $userId = (int) $user->getId();
        $rows = $this->savedSearches->findForUser($userId);
        $counts = $this->counter->countsFor($rows, $userId);

        return new JsonResponse([
            'savedSearches' => array_map(
                static fn (SavedSearch $s) => SavedSearchJson::one($s, $counts[(int) $s->getId()] ?? 0),
                $rows,
            ),
        ]);
    }

    #[Route('', name: 'api_saved_searches_create', methods: ['POST'])]
    public function create(
        #[CurrentUser] User $user,
        #[MapRequestPayload] CreateSavedSearchRequest $request,
    ): JsonResponse {
        $userId = (int) $user->getId();
        $existing = $this->savedSearches->findOneForUserByTerm($userId, $request->term, $request->wholeWord);
        if ($existing !== null) {
            return new JsonResponse(
                ['savedSearch' => SavedSearchJson::one($existing, $this->counter->countFor($existing, $userId))],
                Response::HTTP_OK,
            );
        }

        $savedSearch = new SavedSearch($user, $request->term, $request->wholeWord);
        $this->em->persist($savedSearch);
        $this->em->flush();

        return new JsonResponse(
            ['savedSearch' => SavedSearchJson::one($savedSearch, $this->counter->countFor($savedSearch, $userId))],
            Response::HTTP_CREATED,
        );
    }

    #[Route('/{id}', name: 'api_saved_searches_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $savedSearch = $this->savedSearches->findOneOwnedBy($id, (int) $user->getId())
            ?? throw new NotFoundHttpException('No such saved search.');

        $this->em->remove($savedSearch);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
```

- [ ] **Step 6: Run the functional test to confirm it passes**

Run (from `backend/`): `php bin/phpunit tests/Controller/Api/SavedSearchControllerTest.php`
Expected: PASS. If the `422` assertion fails because the payload maps with defaults instead of erroring, confirm `#[MapRequestPayload]` validation is active (it is on `TagController::create`); a `min: 3` violation returns 422 with `type: validation_error`.

- [ ] **Step 7: Verify the API stays iOS-friendly**

Confirm: Bearer-authenticated, stateless, JSON in / JSON out (errors are `application/problem+json` via the existing exception handling), no CSRF token, no cookie dependence. This matches `TagController`. No `text/html` fallback.

- [ ] **Step 8: Lint, inspections, and Infection**

Run (from `backend/`): `bin/console cache:warmup && composer check && composer md`, then `mcp__phpstorm__lint_files` on the new controller/DTO/JSON/service files. Then run the mutation gate over the diff:

```bash
composer infection:diff
```

Expected: MSI at or above the `infection.json5` threshold. The whole-word branch in `SavedSearchMatchCounter` and the idempotent-create branch are covered by the functional test; add a unit test if a mutant escapes on the counter's `? ' ' : ''`.

- [ ] **Step 9: Commit**

```bash
git add backend/src/Dto/SavedSearch backend/src/Http/SavedSearchJson.php backend/src/Service/Search/SavedSearchMatchCounter.php backend/src/Controller/Api/SavedSearchController.php backend/tests/Controller/Api/SavedSearchControllerTest.php && git commit -m "feat(#581): add /api/saved-searches list, create and delete"
```

---

### Task 5: Database migration

**Files:**
- Create: `backend/migrations/Version20260824120000.php`

- [ ] **Step 1: Generate a baseline diff to capture exact quoting**

The `user` table/column quoting and FK naming must match what Doctrine emits, not be guessed. Warm the cache and generate a diff (do NOT run it against the dev DB):

```bash
cd backend && bin/console cache:warmup && bin/console doctrine:migrations:diff
```

Read the generated `Version*.php` to confirm the exact `saved_search` DDL, the FK target (`user` table, `id` column) and index names. Then discard it and hand-author the platform-aware version below (or reshape the generated one into this template).

- [ ] **Step 2: Write the platform-aware, idempotent migration**

Create `backend/migrations/Version20260824120000.php` (mirror the #568 template: branch on platform, `abortIf` others, guard with `hasTable`, `isTransactional(): false`). Adjust the FK/index DDL to match what Step 1 emitted if it differs:

```php
<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Saved searches (#581): a per-user stored search term plus a whole-word flag.
 *
 * Hand-written and platform-aware. The test suite builds its schema from ORM
 * metadata and never runs this file; only CI's migrate-from-empty leg does, on
 * both SQLite and MySQL. A raw diff on a SQLite dev box would emit SQLite-only
 * DDL, so keep both branches.
 */
final class Version20260824120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create saved_search (per-user saved searches, #581).';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $platform = $this->connection->getDatabasePlatform();
        $mysql = $platform instanceof AbstractMySQLPlatform;
        $sqlite = $platform instanceof SQLitePlatform;
        $this->abortIf(!$mysql && !$sqlite, 'Unsupported platform for this migration.');

        if ($schema->hasTable('saved_search')) {
            return;
        }

        if ($mysql) {
            $this->addSql(<<<'SQL'
                CREATE TABLE saved_search (
                    id INT AUTO_INCREMENT NOT NULL,
                    user_id INT NOT NULL,
                    term VARCHAR(100) NOT NULL,
                    whole_word TINYINT(1) DEFAULT 0 NOT NULL,
                    position INT DEFAULT 0 NOT NULL,
                    UNIQUE INDEX uniq_saved_search_user_term_word (user_id, term, whole_word),
                    INDEX IDX_saved_search_user (user_id),
                    PRIMARY KEY(id)
                ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
                SQL);
            $this->addSql(
                'ALTER TABLE saved_search ADD CONSTRAINT FK_saved_search_user '
                . 'FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE',
            );

            return;
        }

        $this->addSql(<<<'SQL'
            CREATE TABLE saved_search (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                user_id INTEGER NOT NULL,
                term VARCHAR(100) NOT NULL,
                whole_word BOOLEAN DEFAULT 0 NOT NULL,
                position INTEGER DEFAULT 0 NOT NULL,
                CONSTRAINT FK_saved_search_user FOREIGN KEY (user_id)
                    REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uniq_saved_search_user_term_word ON saved_search (user_id, term, whole_word)');
        $this->addSql('CREATE INDEX IDX_saved_search_user ON saved_search (user_id)');
    }

    public function down(Schema $schema): void
    {
        if (!$schema->hasTable('saved_search')) {
            return;
        }
        $this->addSql('DROP TABLE saved_search');
    }
}
```

> Confirm the `user` table name against Step 1's output. If Doctrine quotes it differently or names the FK/index differently, match that exactly — the migration's DDL must reproduce what ORM metadata implies.

- [ ] **Step 3: Verify on a named scratch DB (never the dev database)**

Create a throwaway SQLite database, migrate from empty, and validate the mapping. Do not touch the dev DB.

```bash
cd backend && DATABASE_URL="sqlite:///%kernel.project_dir%/var/scratch-581.sqlite" bin/console doctrine:database:create --env=dev \
 && DATABASE_URL="sqlite:///%kernel.project_dir%/var/scratch-581.sqlite" bin/console doctrine:migrations:migrate --no-interaction --env=dev \
 && DATABASE_URL="sqlite:///%kernel.project_dir%/var/scratch-581.sqlite" bin/console doctrine:schema:validate --env=dev
```

Expected: migration runs clean and `schema:validate` reports the mapping in sync. Then delete the scratch file:

```bash
rm -f backend/var/scratch-581.sqlite
```

- [ ] **Step 4: Commit**

```bash
git add backend/migrations/Version20260824120000.php && git commit -m "feat(#581): migration for the saved_search table"
```

---

### Task 6: Frontend model, API, and store

**Files:**
- Modify: `frontend/src/app/reader/models.ts`
- Modify: `frontend/src/app/reader/reader-api.ts`
- Create: `frontend/src/app/reader/saved-searches.store.ts`
- Test: `frontend/src/app/reader/saved-searches.store.spec.ts`

**Interfaces:**
- Produces: `SavedSearchDto { id: number; term: string; wholeWord: boolean; position: number; unreadCount: number }`.
- Produces: `ReaderApi.savedSearches(): Observable<{ savedSearches: SavedSearchDto[] }>`, `createSavedSearch(body: { term: string; wholeWord: boolean }): Observable<{ savedSearch: SavedSearchDto }>`, `deleteSavedSearch(id: number): Observable<void>`.
- Produces: `SavedSearchesStore` (`providedIn: 'root'`) with `savedSearches: Signal<SavedSearchDto[]>`, `load()`, `createSavedSearch(term, wholeWord)`, `removeSavedSearch(id)`.

- [ ] **Step 1: Write the failing store test**

Create `frontend/src/app/reader/saved-searches.store.spec.ts`:

```ts
import { TestBed } from '@angular/core/testing';
import { of } from 'rxjs';
import { ReaderApi } from './reader-api';
import { SavedSearchDto } from './models';
import { SavedSearchesStore } from './saved-searches.store';

describe('SavedSearchesStore', () => {
  const rows: SavedSearchDto[] = [
    { id: 2, term: 'rust lang', wholeWord: true, position: 0, unreadCount: 4 },
    { id: 1, term: 'climate', wholeWord: false, position: 0, unreadCount: 0 },
  ];

  function setup(api: Partial<ReaderApi>): SavedSearchesStore {
    TestBed.configureTestingModule({
      providers: [SavedSearchesStore, { provide: ReaderApi, useValue: api }],
    });
    return TestBed.inject(SavedSearchesStore);
  }

  it('load() fills the signal from the API, server order preserved', () => {
    const store = setup({ savedSearches: () => of({ savedSearches: rows }) });
    store.load();
    expect(store.savedSearches().map((s) => s.id)).toEqual([2, 1]);
  });

  it('createSavedSearch() posts then reloads', () => {
    const createSavedSearch = jest.fn(() => of({ savedSearch: rows[0] }));
    const savedSearches = jest.fn(() => of({ savedSearches: rows }));
    const store = setup({ createSavedSearch, savedSearches });
    store.createSavedSearch('rust lang', true);
    expect(createSavedSearch).toHaveBeenCalledWith({ term: 'rust lang', wholeWord: true });
    expect(savedSearches).toHaveBeenCalled();
  });

  it('removeSavedSearch() deletes then reloads', () => {
    const deleteSavedSearch = jest.fn(() => of(undefined));
    const savedSearches = jest.fn(() => of({ savedSearches: [] }));
    const store = setup({ deleteSavedSearch, savedSearches });
    store.removeSavedSearch(2);
    expect(deleteSavedSearch).toHaveBeenCalledWith(2);
    expect(savedSearches).toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run (from `frontend/`): `npx jest src/app/reader/saved-searches.store.spec.ts`
Expected: FAIL — `SavedSearchDto` / `SavedSearchesStore` / API methods do not exist.

- [ ] **Step 3: Add the DTO**

In `frontend/src/app/reader/models.ts`, add after `TagDto`:

```ts
export interface SavedSearchDto {
  id: number;
  /** The trimmed search term (no trailing whole-word space). */
  term: string;
  /** True when the saved search matches whole words only. */
  wholeWord: boolean;
  /** Reserved for a future sidebar reorder; unused in v1. */
  position: number;
  /** Live count of unread entries matching this search. */
  unreadCount: number;
}
```

- [ ] **Step 4: Add the API methods**

In `frontend/src/app/reader/reader-api.ts`, add `SavedSearchDto` to the `./models` import and add these methods next to the tag methods:

```ts
  savedSearches(): Observable<{ savedSearches: SavedSearchDto[] }> {
    return this.http.get<{ savedSearches: SavedSearchDto[] }>(`${this.base}/api/saved-searches`);
  }

  createSavedSearch(body: { term: string; wholeWord: boolean }): Observable<{ savedSearch: SavedSearchDto }> {
    return this.http.post<{ savedSearch: SavedSearchDto }>(`${this.base}/api/saved-searches`, body);
  }

  deleteSavedSearch(id: number): Observable<void> {
    return this.http.delete<void>(`${this.base}/api/saved-searches/${id}`);
  }
```

- [ ] **Step 5: Create the store**

Create `frontend/src/app/reader/saved-searches.store.ts` (mirrors `TagsStore`; mutations go through `ReaderApi`, then `load()` re-syncs — so the sidebar count comes from the server every time):

```ts
import { HttpErrorResponse } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Problem, parseProblem } from '../core/problem';
import { ReaderApi } from './reader-api';
import { SavedSearchDto } from './models';

/** The user's saved searches, newest first, each with a live unread-match
 *  count. Mutations happen through ReaderApi and re-sync via load(), which is
 *  also the hook the reader shell calls after every refresh slice. */
@Injectable({ providedIn: 'root' })
export class SavedSearchesStore {
  private readonly api = inject(ReaderApi);

  readonly savedSearches = signal<SavedSearchDto[]>([]);
  readonly loading = signal(false);
  readonly error = signal<Problem | null>(null);

  load(): void {
    this.loading.set(true);
    this.error.set(null);
    this.api.savedSearches().subscribe({
      next: (r) => {
        this.savedSearches.set(r.savedSearches);
        this.loading.set(false);
      },
      error: (e: HttpErrorResponse) => {
        this.error.set(parseProblem(e));
        this.loading.set(false);
      },
    });
  }

  createSavedSearch(term: string, wholeWord: boolean): void {
    this.api.createSavedSearch({ term, wholeWord }).subscribe({
      next: () => this.load(),
      error: (e: HttpErrorResponse) => this.error.set(parseProblem(e)),
    });
  }

  removeSavedSearch(id: number): void {
    this.api.deleteSavedSearch(id).subscribe({
      next: () => this.load(),
      error: (e: HttpErrorResponse) => this.error.set(parseProblem(e)),
    });
  }
}
```

- [ ] **Step 6: Run the store test to confirm it passes**

Run (from `frontend/`): `npx jest src/app/reader/saved-searches.store.spec.ts`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/models.ts frontend/src/app/reader/reader-api.ts frontend/src/app/reader/saved-searches.store.ts frontend/src/app/reader/saved-searches.store.spec.ts && git commit -m "feat(#581): saved-searches model, API and store"
```

---

### Task 7: `query.ts` navigation helpers + i18n keys

**Files:**
- Modify: `frontend/src/app/reader/query.ts`
- Modify: `frontend/public/i18n/en.json`
- Modify: `frontend/public/i18n/de.json`
- Test: `frontend/src/app/reader/query.saved-search.spec.ts`

**Interfaces:**
- Produces: `savedSearchTerm(term: string, wholeWord: boolean): string` and `savedSearchParams(term: string, wholeWord: boolean): SelectionParams`.

- [ ] **Step 1: Write the failing helper test**

Create `frontend/src/app/reader/query.saved-search.spec.ts`:

```ts
import { savedSearchParams, savedSearchTerm } from './query';

describe('saved-search query helpers', () => {
  it('savedSearchTerm appends the whole-word trailing space only when whole-word', () => {
    expect(savedSearchTerm('climate', false)).toBe('climate');
    expect(savedSearchTerm('rust lang', true)).toBe('rust lang ');
  });

  it('savedSearchParams puts the reconstructed term on q and clears the rest', () => {
    const params = savedSearchParams('rust lang', true);
    expect(params.q).toBe('rust lang ');
    expect(params.view).toBeNull();
    expect(params.tag).toBeNull();
    expect(params.subscription).toBeNull();
    expect(params.entry).toBeNull();
  });
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run (from `frontend/`): `npx jest src/app/reader/query.saved-search.spec.ts`
Expected: FAIL — helpers not exported.

- [ ] **Step 3: Add the helpers**

In `frontend/src/app/reader/query.ts`, add after `selectionQueryParams`:

```ts
/** The whole-word trailing space is the search signal (#408 follow-up), so a
 *  saved whole-word search reconstructs it when it navigates. */
export function savedSearchTerm(term: string, wholeWord: boolean): string {
  return wholeWord ? `${term} ` : term;
}

/** The query params that open a saved search, reusing the existing `q` search
 *  selection kind. */
export function savedSearchParams(term: string, wholeWord: boolean): SelectionParams {
  return selectionQueryParams({ q: savedSearchTerm(term, wholeWord) });
}
```

- [ ] **Step 4: Run the helper test to confirm it passes**

Run (from `frontend/`): `npx jest src/app/reader/query.saved-search.spec.ts`
Expected: PASS.

- [ ] **Step 5: Add i18n keys**

In `frontend/public/i18n/en.json`, inside the `reader` object near `tags`/`feeds`/`forYou`, add:

```json
    "savedSearches": "Saved searches",
    "saveSearch": "Save search",
    "removeSavedSearch": "Remove saved search",
```

In `frontend/public/i18n/de.json`, in the same place:

```json
    "savedSearches": "Gespeicherte Suchen",
    "saveSearch": "Suche speichern",
    "removeSavedSearch": "Gespeicherte Suche entfernen",
```

(The whole-word badge reuses the existing `reader.searchWholeWord` / `reader.searchWholeWordHint` keys.)

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/query.ts frontend/src/app/reader/query.saved-search.spec.ts frontend/public/i18n/en.json frontend/public/i18n/de.json && git commit -m "feat(#581): saved-search navigation helpers and i18n keys"
```

---

### Task 8: Sidebar "Saved searches" section

**Files:**
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.ts`
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.html`
- Modify: `frontend/src/app/reader/sidebar/sidebar.component.scss`
- Test: `frontend/src/app/reader/sidebar/sidebar.component.spec.ts` (extend if it exists, else create)

**Interfaces:**
- Consumes: `SavedSearchDto[]`, `savedSearchParams`, `savedSearchTerm`.
- Produces: a new `savedSearches = input<SavedSearchDto[]>([])` on `SidebarComponent`; a labelled section between the top-links group and the Tags section.

- [ ] **Step 1: Write/extend the failing component test**

Create or extend `frontend/src/app/reader/sidebar/sidebar.component.spec.ts`. Copy the TestBed/Transloco/router provider setup from an adjacent reader component spec (e.g. the existing sidebar or entry-list spec) so translation and `routerLink` resolve. Assert:

```ts
// Within the existing describe(), after configuring the component with required inputs:
it('renders no saved-searches section when the list is empty', () => {
  fixture.componentRef.setInput('savedSearches', []);
  fixture.detectChanges();
  expect(fixture.nativeElement.textContent).not.toContain('Saved searches');
});

it('renders a row per saved search with its unread count', () => {
  fixture.componentRef.setInput('savedSearches', [
    { id: 1, term: 'climate', wholeWord: false, position: 0, unreadCount: 3 },
  ]);
  fixture.detectChanges();
  const text = fixture.nativeElement.textContent;
  expect(text).toContain('Saved searches');
  expect(text).toContain('climate');
  expect(text).toContain('3');
});
```

> The sidebar has many `input.required(...)` inputs (`tagTree`, `untagged`, `totalUnread`, `selection`). Set them all in the setup (mirror the existing spec's harness). `savedSearches` has a default `[]`, so existing tests keep passing without change.

- [ ] **Step 2: Run it to confirm it fails**

Run (from `frontend/`): `npx jest src/app/reader/sidebar/sidebar.component.spec.ts`
Expected: FAIL on the new assertions (section not rendered).

- [ ] **Step 3: Add the input and helpers to the component**

In `frontend/src/app/reader/sidebar/sidebar.component.ts`:

1. Import the DTO and helpers:

```ts
import { SavedSearchDto } from '../models';
import { savedSearchParams, savedSearchTerm } from '../query';
```

2. Add the input alongside the other inputs:

```ts
  readonly savedSearches = input<SavedSearchDto[]>([]);
```

3. Expose the helpers to the template next to `selectionQueryParams`:

```ts
  protected readonly savedSearchParams = savedSearchParams;
  protected readonly savedSearchTerm = savedSearchTerm;
```

- [ ] **Step 4: Render the section in the template**

In `frontend/src/app/reader/sidebar/sidebar.component.html`, insert this block **after** the top-links group's closing `}` (the one after the For You block, around line 96) and **before** the Tags `@if (tagTree().length) {` (around line 98):

```html
  @if (savedSearches().length) {
    <p class="label">{{ 'reader.savedSearches' | transloco }}</p>
    @for (saved of savedSearches(); track saved.id) {
      <a
        class="nav"
        [class.active]="
          selection().kind === 'search' &&
          selection().term === savedSearchTerm(saved.term, saved.wholeWord)
        "
        [routerLink]="[]"
        [queryParams]="savedSearchParams(saved.term, saved.wholeWord)"
        queryParamsHandling="merge"
      >
        <app-icon name="search" size="sm" />
        <span class="saved-term">{{ saved.term }}</span>
        @if (saved.wholeWord) {
          <span
            class="whole-word-badge"
            aria-hidden="true"
            [attr.title]="'reader.searchWholeWordHint' | transloco"
          >
            {{ 'reader.searchWholeWord' | transloco }}
          </span>
        }
        @if (saved.unreadCount > 0) {
          <span class="count">{{ saved.unreadCount }}</span>
        }
      </a>
    }
  }
```

- [ ] **Step 5: Add minimal styles**

In `frontend/src/app/reader/sidebar/sidebar.component.scss`, add a small rule for the whole-word badge and long-term truncation, using existing tokens (no hex, no raw px — reuse spacing/size tokens already used in this file; copy the token names from the existing `.count` / `.label` rules):

```scss
.nav .saved-term {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.nav .whole-word-badge {
  // Mirror the results-header badge: a quiet, uppercase micro-label.
  // Reuse the same font-size/opacity tokens the `.label` rule uses.
  text-transform: uppercase;
  opacity: 0.65;
}
```

> If the sidebar `.scss` already lacks a `.count` inside `.nav` for these rows, confirm the top-links `.count` styling applies (the rows use the same `.nav` + `.count` classes, so it should). Keep every value token-based; `npm run check` fails on raw `px`/hex.

- [ ] **Step 6: Run the component test to confirm it passes**

Run (from `frontend/`): `npx jest src/app/reader/sidebar/sidebar.component.spec.ts`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/sidebar && git commit -m "feat(#581): sidebar Saved searches section"
```

---

### Task 9: Save / Remove action in the results header

**Files:**
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.ts`
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.html`
- Modify: `frontend/src/app/reader/entry-list/entry-list.component.scss` (only if a new class needs styling; reuse `.mark-all`/`.refresh` button styles if possible)
- Test: `frontend/src/app/reader/entry-list/entry-list.component.spec.ts` (extend if it exists, else create)

**Interfaces:**
- Produces on `EntryListComponent`: `currentSearchSaved = input<boolean>(false)`, `saveSearch = output<void>()`, `removeSavedSearch = output<void>()`.

- [ ] **Step 1: Write/extend the failing component test**

In `frontend/src/app/reader/entry-list/entry-list.component.spec.ts`, add tests (copy the harness/providers from the existing spec; set the many required inputs). Assert the button emits the right output and is absent off a search:

```ts
it('emits saveSearch when the current search is not saved', () => {
  fixture.componentRef.setInput('selection', { kind: 'search', id: null, unread: false, term: 'climate' });
  fixture.componentRef.setInput('currentSearchSaved', false);
  fixture.detectChanges();
  const emitted = jest.fn();
  component.saveSearch.subscribe(emitted);
  const btn: HTMLButtonElement = fixture.nativeElement.querySelector('button.save-search');
  btn.click();
  expect(emitted).toHaveBeenCalled();
});

it('emits removeSavedSearch when the current search is saved', () => {
  fixture.componentRef.setInput('selection', { kind: 'search', id: null, unread: false, term: 'climate' });
  fixture.componentRef.setInput('currentSearchSaved', true);
  fixture.detectChanges();
  const emitted = jest.fn();
  component.removeSavedSearch.subscribe(emitted);
  const btn: HTMLButtonElement = fixture.nativeElement.querySelector('button.save-search');
  btn.click();
  expect(emitted).toHaveBeenCalled();
});

it('shows no save/remove button off a search selection', () => {
  fixture.componentRef.setInput('selection', { kind: 'all', id: null, unread: true });
  fixture.detectChanges();
  expect(fixture.nativeElement.querySelector('button.save-search')).toBeNull();
});
```

- [ ] **Step 2: Run it to confirm it fails**

Run (from `frontend/`): `npx jest src/app/reader/entry-list/entry-list.component.spec.ts`
Expected: FAIL — input/outputs and button do not exist.

- [ ] **Step 3: Add the input and outputs**

In `frontend/src/app/reader/entry-list/entry-list.component.ts`:

1. Add the input near the other inputs:

```ts
  /** True when the current search term is already saved; flips the header
   *  action between Save and Remove. Meaningful only for a search selection. */
  readonly currentSearchSaved = input<boolean>(false);
```

2. Add the outputs near `markAllRead`/`refresh`:

```ts
  readonly saveSearch = output<void>();
  readonly removeSavedSearch = output<void>();
```

- [ ] **Step 4: Add the button to the header tools**

In `frontend/src/app/reader/entry-list/entry-list.component.html`, inside `<div class="tools">`, insert this block **after** the `@if (canRefresh()) { … }` refresh button and **before** the `@if (headerActions(); as actions) { … }` outlet:

```html
    @if (selection().kind === 'search') {
      @if (currentSearchSaved()) {
        <button
          class="save-search"
          type="button"
          (click)="removeSavedSearch.emit()"
          [attr.aria-label]="'reader.removeSavedSearch' | transloco"
          [attr.title]="'reader.removeSavedSearch' | transloco"
        >
          <app-icon name="bookmark_remove" size="sm" />
          <span class="txt">{{ 'reader.removeSavedSearch' | transloco }}</span>
        </button>
      } @else {
        <button
          class="save-search"
          type="button"
          (click)="saveSearch.emit()"
          [attr.aria-label]="'reader.saveSearch' | transloco"
          [attr.title]="'reader.saveSearch' | transloco"
        >
          <app-icon name="bookmark_add" size="sm" />
          <span class="txt">{{ 'reader.saveSearch' | transloco }}</span>
        </button>
      }
    }
```

- [ ] **Step 5: Style the button (reuse existing tool-button styles)**

In `frontend/src/app/reader/entry-list/entry-list.component.scss`, give `.save-search` the same treatment as `.mark-all` / `.refresh`. If those rules are grouped by a shared selector, add `.save-search` to the group rather than duplicating declarations (DRY). No raw px/hex.

- [ ] **Step 6: Run the component test to confirm it passes**

Run (from `frontend/`): `npx jest src/app/reader/entry-list/entry-list.component.spec.ts`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/entry-list && git commit -m "feat(#581): Save/Remove saved-search action in the results header"
```

---

### Task 10: Wire it together in the reader shell

**Files:**
- Modify: `frontend/src/app/reader/reader-shell.component.ts`
- Modify: `frontend/src/app/reader/reader-shell.component.html`

**Interfaces:**
- Consumes: `SavedSearchesStore`, `EntryListComponent` outputs `saveSearch`/`removeSavedSearch` + input `currentSearchSaved`, `SidebarComponent` input `savedSearches`, `visibleSearchTerm`/`isWholeWordTerm` from `query.ts`.

- [ ] **Step 1: Inject the store and import the helpers**

In `frontend/src/app/reader/reader-shell.component.ts`:

1. Add the injection alongside the other stores:

```ts
  readonly savedSearchesStore = inject(SavedSearchesStore);
```

2. Ensure imports include `SavedSearchesStore` and, from `./query`, `isWholeWordTerm` (and `visibleSearchTerm`, already imported for `searchTitle`).

- [ ] **Step 2: Add the "is the current search saved?" computed and handlers**

Add near the other computeds/handlers:

```ts
  /** The saved search matching the current selection, or null. A search's
   *  identity is its visible term plus its whole-word flag (both live in
   *  Selection.term), so both must match. */
  readonly currentSavedSearch = computed(() => {
    const s = this.selection();
    if (s.kind !== 'search') return null;
    const term = visibleSearchTerm(s.term ?? '');
    const wholeWord = isWholeWordTerm(s.term ?? '');
    return (
      this.savedSearchesStore
        .savedSearches()
        .find((saved) => saved.term === term && saved.wholeWord === wholeWord) ?? null
    );
  });

  onSaveSearch(): void {
    const s = this.selection();
    if (s.kind !== 'search') return;
    this.savedSearchesStore.createSavedSearch(visibleSearchTerm(s.term ?? ''), isWholeWordTerm(s.term ?? ''));
  }

  onRemoveSavedSearch(): void {
    const saved = this.currentSavedSearch();
    if (saved) this.savedSearchesStore.removeSavedSearch(saved.id);
  }
```

- [ ] **Step 3: Reload saved searches wherever subscriptions reload**

Add `this.savedSearchesStore.load();` immediately after **every** `this.subs.load();` call in `reader-shell.component.ts`. There are at least two: the initial/startup load and the #502 refresh-slice effect. For the slice effect specifically, it becomes:

```ts
        if (slice === 0) return; // nothing has reported yet
        if (!this.sweeping() && running) return; // manual refresh: wait for finish
        this.subs.load();
        this.savedSearchesStore.load();
        if (!running) this.tags.load();
        this.entries.load(queryFromSelection(this.selection()));
```

Also add `this.savedSearchesStore.load();` at the end of `onMarkAllRead()` (the mark-all-read path updates unread counts, so saved-search badges must recompute there too — Q12). Grep to confirm you covered the startup call: `grep -n "this.subs.load()" src/app/reader/reader-shell.component.ts`.

> If there is no explicit startup `this.subs.load()` (the initial load may run inside an effect or resolver), add one `this.savedSearchesStore.load()` to the same initialization path that first populates the sidebar, so the section appears on first paint when saved searches exist.

- [ ] **Step 4: Bind the sidebar and both entry-list instances**

In `frontend/src/app/reader/reader-shell.component.html`:

1. On `<app-sidebar …>` (around line 66-88), add:

```html
          [savedSearches]="savedSearchesStore.savedSearches()"
```

2. On **both** `<app-entry-list …>` instances (the pane-mode block ~93-122 and the single-pane block ~141-171), add:

```html
          [currentSearchSaved]="!!currentSavedSearch()"
          (saveSearch)="onSaveSearch()"
          (removeSavedSearch)="onRemoveSavedSearch()"
```

- [ ] **Step 5: Verify the full frontend gate**

Run (from `frontend/`): `npm run check`
Expected: ESLint + Prettier + Stylelint + Jest all pass. Fix any Prettier/Stylelint issues (100-col, token-only spacing/colours).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/reader-shell.component.ts frontend/src/app/reader/reader-shell.component.html && git commit -m "feat(#581): wire saved searches into the reader shell"
```

---

### Task 11: End-to-end verification and PR

**Files:** none (verification only).

- [ ] **Step 1: Full backend suite (SQLite, native)**

Run (from `backend/`):

```bash
bin/console cache:warmup && php bin/phpunit
```

Expected: green. Then the quality gates:

```bash
composer check && composer md && composer infection:diff
```

Expected: cs/stan/tramp clean, PHPMD clean, MSI at/above threshold. If tramp reports a chain, check `composer show larspohlmann/phptramp` first (CI runs the tip of its develop) before hunting in application code.

- [ ] **Step 2: MySQL leg of the backend suite (Docker)**

Bring the stack up and run the suite against MySQL (never `down -v`):

```bash
docker compose up -d && docker compose exec php bin/console cache:warmup && docker compose exec php vendor/bin/phpunit
```

Expected: green. Then apply the new migration to the running Docker MySQL DB so the dev stack is current:

```bash
docker compose exec php bin/console doctrine:migrations:migrate --no-interaction
```

- [ ] **Step 3: Restart worker + clear caches so no stale code/DI/schema lingers**

Per the standing "verify containers are current" rule, restart the worker (it holds code at boot) and the php-fpm service after the mid-branch constructor/migration changes:

```bash
docker compose restart php worker
```

- [ ] **Step 4: Frontend gate**

Run (from `frontend/`): `npm run check`
Expected: green.

- [ ] **Step 5: Manual smoke against the Docker stack**

With the stack up and the frontend dev server (`npm start`, from `frontend/`) pointed at `https://localhost:8443`, verify by hand:
1. Run a search (≥3 chars) → the results header shows **Save search**.
2. Click Save → a **Saved searches** section appears in the sidebar below Recently read with the term and (if any) an unread count; the header flips to **Remove saved search**.
3. Save a whole-word search (trailing space) of the same term → a second, distinct row appears with the whole-word badge.
4. Trigger a refresh → the saved-search unread counts update.
5. Open a saved search from the sidebar → it loads the same results; header shows **Remove saved search**.
6. Click Remove → the row disappears; when the last one goes, the section disappears; the results stay on screen and the header flips back to **Save search**.
7. Switch language to German → labels read "Gespeicherte Suchen" / "Suche speichern" / "Gespeicherte Suche entfernen".

Scan `backend/var/log/dev.log` for deprecations/errors after the backend exercises.

- [ ] **Step 6: Open the PR**

Push the branch and open a PR into `develop` with body `Closes #581`. After merge, verify the issue auto-closed rather than closing it by hand. Do not deploy (no `-dev.N` tag) without explicit go-ahead.

---

## Self-Review

**Spec coverage** (against issue #581):
- Save from results header (Save/Remove) → Task 9 (button) + Task 10 (wiring/derive saved state). ✓
- Immediate save/remove, view stays on `?q=`, header flips → Task 10 (`onSaveSearch`/`onRemoveSavedSearch`, no navigation). ✓
- Save a zero-match search → allowed; `create` never checks match count; validation only floors term length. ✓
- Sidebar section below top links / above Tags, only when ≥1 exists, auto-hides → Task 8 (`@if (savedSearches().length)`, placement). ✓
- Tag-styled rows: glyph, term, unread badge (hidden at zero), whole-word indicator → Task 8. ✓
- Navigate via `?q=` reusing `search` kind → Task 7 (`savedSearchParams`) + Task 8. ✓
- Newest-first, `position` reserved, no reorder → Task 1 (repo `id DESC`, `position` default 0). ✓
- Counts = unread matches, recomputed on refresh + mark-all-read → Task 2/3/4 (count), Task 10 (load hooks). ✓
- Only the query persisted, engine-independent count → Task 1 (entity: term+wholeWord+position), Task 2 (LIKE+UnreadDql count). ✓
- Identity includes whole-word; unique per (user, term, wholeWord); name = term → Task 1 (unique constraint), Task 4 (dedupe). ✓
- Backed-up entity, no old-backup shim → Task 1 (full round-trip; golden fixtures untouched). ✓
- Same action zone on desktop + mobile → Task 9 (single `.tools` block) + Task 10 (both entry-list instances). ✓
- German strings → Task 7. ✓

**Placeholder scan:** No "TBD"/"handle errors"/"similar to Task N". The two soft references are deliberate and actionable: (a) the migration's exact `user`-table quoting is captured by generating a diff first (Task 5 Step 1); (b) frontend component-test harness (Transloco/router providers) is copied from an adjacent existing spec (Tasks 8–9) — flagged as a known scaffolding dependency.

**Type consistency:** `SavedSearch` getters (`getTerm`/`isWholeWord`/`getPosition`) are used identically in the exporter, counter, JSON, and restorer. `SavedSearchDto` fields (`term`/`wholeWord`/`position`/`unreadCount`) match `SavedSearchJson::one`'s output keys and the store/sidebar consumers. `countUnreadMatchesForUser(EntrySearchQuery)` is produced in Task 2 and consumed in Task 3. `savedSearchTerm`/`savedSearchParams` produced in Task 7, consumed in Task 8. `currentSearchSaved`/`saveSearch`/`removeSavedSearch` produced in Task 9, bound in Task 10.

## Known risks / watch-items

- **Backup index arithmetic** (Task 1 Step 17): inserting a `savedSearch` line after `tag` shifts `$lines[...]` indices in `AccountBackupExporterTest`; read the whole test before editing and prefer `array_key_last` for the footer.
- **`RestoreResult` positional construction** (Task 1 Step 12): if constructed positionally anywhere, adding a field breaks call sites — grep first.
- **`user` table quoting** in the migration (Task 5): verify against a generated diff; MySQL reserves `user`.
- **Frontend component-test providers** (Tasks 8–9): reuse an existing sibling spec's Transloco/router setup; the new inputs all have defaults so existing specs stay green.
- **Infection on the counter** (Task 4 Step 8): if the `? ' ' : ''` whole-word branch escapes a mutant, add a direct `SavedSearchMatchCounter` unit test.
