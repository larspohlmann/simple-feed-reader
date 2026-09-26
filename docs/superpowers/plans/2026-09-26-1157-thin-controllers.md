# Thin Controllers: Persistence and Entity Mutation Leave Public Actions (#1157) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** No controller persists, flushes, removes, constructs or mutates an entity. That work moves into services under `src/Service/<Module>/`, and PHPStan enforces the rule in public actions too, not only in private helpers.

**Architecture:** Each module gets a service that owns its writes and its flush:
- `Tag/TagEditor` and `Tag/TagOrdering`
- `Search/SavedSearchEditor`
- `Subscription/SubscriptionEditor`
- `Account/AccountPreferencesWriter`
- `Catalog/CatalogCategoryEditor` and `Catalog/CatalogFeedEditor`

The five copies of the "position = index, flush" loop become one `Ordering/PositionReorderer` over a new `App\Entity\Positioned` interface. Controllers keep only three things: request mapping, the owned lookup, and response assembly. Two PHPStan rules enforce the boundary:
- `ThinControllerRule` is widened. It now also rejects an `ObjectManager` or `ManagerRegistry` parameter on any controller method, constructor or action.
- A new sibling, `ControllerMutatesNoEntityRule`, rejects `new App\Entity\*` in a controller, and any call on an `App\Entity\*` object other than `requireId()` or a `get*`, `is*` or `has*` method.

**Tech Stack:** PHP 8.4, Symfony 7.4, Doctrine ORM, PHPUnit 12 with DAMA DoctrineTestBundle (each test rolls back), PHPStan 2.2 at level max with the custom rules in `backend/tests/PhpStan/`, PHPMD codesize, phptramp, Infection 0.34.

**Spec:** GitHub issue #1157 (`gh issue view 1157`). Related issues:
- #1160 (merged) created the problem mappers.
- #1165 (merged, fcc1e6b8) added `requireId()` through the `App\Entity\PersistedId` trait, and `EntityIdCoercionRule`, which also runs over `tests/`.
- #1170 (merged, a14c66c8, PR #1179) added `QueriesLiveInRepositoriesRule` and `docs/architecture.md` §7.
- #1158 handles presentation leaks into services.

## Status

| Task | State |
|---|---|
| Task 0: Preflight | ⬜ not started |
| Task 1: `Positioned` + `PositionReorderer` | ⬜ not started |
| Task 2: `TagEditor`, `TagNameTakenException` gets its `Service/Tag` home | ⬜ not started |
| Task 3: `TagOrdering` | ⬜ not started |
| Task 4: `SavedSearchEditor` | ⬜ not started |
| Task 5: `SubscriptionEditor` | ⬜ not started |
| Task 6: `AccountPreferencesWriter` (MeController, PasskeyOfferController) | ⬜ not started |
| Task 7: `CatalogCategoryEditor` | ⬜ not started |
| Task 8: `CatalogFeedEditor` (absorbs `CatalogFeedWriter`) | ⬜ not started |
| Task 9: `ThinControllerRule` rejects persistence parameters | ⬜ not started |
| Task 10: `ControllerMutatesNoEntityRule`; CLAUDE.md | ⬜ not started |

## Scope and PR split

The issue bundles two kinds of work:

1. **Mechanically enforceable:** persistence and entity mutation in public actions, plus the rule that forbids them. This plan covers all of it, in one PR (PR A, about 30 files). The rule tasks come last because the rules can only go green once every controller is clean, and the allow-list may not grow.
2. **Judgement calls:** inline security decisions, inline response assembly, the shared-boilerplate helpers, and the two commands. No PHPStan rule can gate these. They go to **PR B, with its own plan**. Appendix B lists every site with file:line and a target home, so PR B's planner starts from this inventory.

PR A's body says `Refs #1157`. PR B closes the issue (ruling R1).

## Inventory (develop @ `a14c66c8`, 2026-09-26)

`git grep -nE '\->(persist|flush|remove)\(' origin/develop -- backend/src/Controller` finds 33 `persist`/`flush`/`remove` calls in 7 controllers. The issue's count of 37 came from an older snapshot. It also counted `PasskeyController:151`, which calls `$this->removal->remove()` on a service, and that is fine. Every row below is an action this plan changes, together with its target.

| Action (develop lines) | Service work inline | Target |
|---|---|---|
| `Controller/Api/TagController::create` 50-64 | name-taken check, `new Tag`, 3 setters, persist, flush | `Service/Tag/TagEditor::create` |
| `TagController::reorder` 71-92 | id map, permutation guard, `setPosition` loop, flush | `Service/Tag/TagOrdering::reorder` |
| `TagController::feedOrder` 100-121 | permutation guard, join `setPosition` loop, flush | `Service/Tag/TagOrdering::orderFeeds` |
| `TagController::update` 124-142 | name-taken check, 3 setters, flush | `Service/Tag/TagEditor::update` |
| `TagController::delete` 145-160 | `removeTag` loop, remove, flush | `Service/Tag/TagEditor::delete` |
| `Controller/Api/SavedSearchController::create` 55-83 | find-or-create, `new SavedSearch`, persist, two flushes, slug, sweep | `Service/Search/SavedSearchEditor::save` |
| `SavedSearchController::update` 86-101 | setter, flush | `SavedSearchEditor::changeDigestInclusion` |
| `SavedSearchController::delete` 104-113 | remove, flush | `SavedSearchEditor::delete` |
| `Controller/Api/SubscriptionController::update` 116-140 | custom-title normalising, tag sync, PATCH flags, flush | `Service/Subscription/SubscriptionEditor::update` |
| `SubscriptionController::moveToTag` 148-161 | flush after `FeedTagMove` | `SubscriptionEditor::moveToTag` |
| `SubscriptionController::reorder` 169-181 | `setPosition` loop, flush | `SubscriptionEditor::reorder` |
| `Controller/Api/MeController::updateLocale` 63-71 | `setLocale`, flush | `Service/Account/AccountPreferencesWriter::changeLocale` |
| `MeController::updatePreferences` 80-88 | preferences setter, flush | `AccountPreferencesWriter::changeScrapeFallback` |
| `MeController::updateMagazineStyle` 92-100 | preferences setter, flush | `AccountPreferencesWriter::changeMagazineStyle` |
| `MeController::updateDigest` 109-117 | flush after `DigestEnablement` | `AccountPreferencesWriter::changeDigest` |
| `Controller/Api/PasskeyOfferController::answer` 33-39 | flush after `PasskeyOffer` | `AccountPreferencesWriter::answerPasskeyOffer` |
| `Controller/Admin/AdminCatalogCategoryController::create` 37-50 | `new CatalogCategory`, 3 setters, persist, flush | `Service/Catalog/CatalogCategoryEditor::create` |
| `AdminCatalogCategoryController::reorder` 53-61 | `setPosition` loop, flush | `CatalogCategoryEditor::reorder` |
| `AdminCatalogCategoryController::update` 64-75 | 5 setters, flush | `CatalogCategoryEditor::update` |
| `AdminCatalogCategoryController::delete` 78-87 | remove, flush | `CatalogCategoryEditor::delete` |
| `Controller/Admin/AdminCatalogFeedController::create` 44-55 | `new CatalogFeed`, writer, setter, persist, flush | `Service/Catalog/CatalogFeedEditor::create` |
| `AdminCatalogFeedController::reorder` 58-66 | `setPosition` loop, flush | `CatalogFeedEditor::reorder` |
| `AdminCatalogFeedController::update` 69-81 | 3 setters, writer, flush | `CatalogFeedEditor::update` |
| `AdminCatalogFeedController::delete` 84-91 | remove, flush | `CatalogFeedEditor::delete` |

The "position = index, flush" loop appears five times: `TagController:84-87`, `TagController:115-118`, `SubscriptionController:175-178`, `AdminCatalogCategoryController:55-58` and `AdminCatalogFeedController:60-63`. All five become `Service/Ordering/PositionReorderer`.

The grep sweeps behind the new rules found **no other** entity construction or non-query entity call in `src/Controller`:
- `new [A-Z]` finds entity construction only in the rows above. `EntryQuery`, `ForYouFeedQuery` and `SavedSearchListQuery` are request value objects in `App\Repository`, not entities.
- The variable-call sweep (Task 0, Step 6, sweep E) finds, besides `get*`/`is*`/`has*`, 47 `requireId()` calls, which R3 allows. It also finds the setters and `removeTag` above. The remaining calls are on non-entities: `setEtag`, `setPublic` and `setMaxAge` on a `Response`, and `feedCount`, `guard`, `ping`, `report`, `test`, `toArray`, `toUpdate`, `values`, `view` and `withRows` on DTOs, services and result objects.
- The chained-call sweep (`)->x(` and `]->x(`) finds nothing else either.

## Rulings

Lars ruled on the draft's decisions on 2026-09-26. They are settled; do not reopen them during execution.

- **R1, PR split and closing.** PR A (this plan) says `Refs #1157`. PR B has its own plan and closes #1157. PR A opens no follow-up issue.
- **R2, rule shape: two rules instead of one `Rule<Node>`.** Approved.
  - Persistence parameters are a property of the method signature, so they go into `ThinControllerRule` (an `InClassMethodNode` rule).
  - Entity construction and mutation need a type per expression, which an `InClassMethodNode` scope cannot give. They go into `ControllerMutatesNoEntityRule` (a `Rule<CallLike>`).
  - Both rules use identifiers under `simpleFeedReader.thinController.*`.
- **R3, query methods.** Approved: on an entity, `get*`, `is*`, `has*` and `requireId()` count as queries, and every other method counts as a mutation. `requireId()` has to be allowed because develop's controllers call it 47 times (#1165).
- **R4, owned lookups stay until PR B.** Approved. The `findOneOwnedBy(...) ?? throw new NotFoundHttpException(...)` lookups stay in controllers in PR A. They are PR B's owned-lookup work (Appendix B §3), so PR A changes no 404 path. Controllers and new services read ids with `requireId()`.
- **R5, `CatalogFeedWriter` is folded into `CatalogFeedEditor` and deleted.** Approved. It existed only because the controller could not hold a private method, and it has no other caller and no test.
- **R6, the passkey-offer answer goes through `AccountPreferencesWriter`.** Approved. `PasskeyOffer::markAnswered()` stays flush-free, because `AttestationVerifier:164` calls it, and line 167 flushes.
- **R7, the CLAUDE.md edit (Task 10, Step 7).** Approved, with `requireId()` added to the allowed calls.

## Merged dependencies

- **#1170** (a14c66c8, PR #1179):
  - `ThinControllerRule` deliberately does not check `createQueryBuilder`, `createQuery` or DBAL. `QueriesLiveInRepositoriesRule` owns query building, and it guards every `App\` class except `App\Repository\`, `App\Doctrine\` and `App\Tests\`, so controllers are covered. Task 0, Step 3 confirms this.
  - The new services call repository methods and own the unit of work (`persist`, `remove`, `flush`), as `docs/architecture.md` §7 prescribes. So they satisfy #1170's rule.
  - §7's last bullet says the controllers' remaining `persist`/`flush` calls are #1157's to move. Task 10, Step 7 rewrites that bullet.
- **#1165** (fcc1e6b8):
  - `requireId(): int` comes from the `App\Entity\PersistedId` trait. `User`, `Tag`, `Subscription`, `SavedSearch`, `CatalogCategory` and `CatalogFeed` use the trait; `SubscriptionTag` does not, and this plan never calls `requireId()` on it.
  - #1165 also rewrote every controller's `(int) $x->getId()` to `->requireId()`; the before-blocks below reflect that.
  - `EntityIdCoercionRule` runs over `src` and `tests`, so no new code may write `(int) $entity->getId()` or `$entity->getId() ?? …`.
- **#1160:** Task 2 moves `TagNameTakenException` from `src/Exception/` to `src/Service/Tag/Exception/` and repoints `TagProblems` and `ProblemContractTest`. The wire body (`tag_name_taken`, 409) is unchanged.
- **#1158 (later):** no JSON shaping moves into a service here. Every `*Json::` call stays in its controller.

## Global Constraints

- **Paths and commands are relative to `backend/`** unless they start with `docs/` or `CLAUDE.md`.
- **No behaviour or wire-contract change.** Every status code, body, header and 404/409/422 path stays byte-identical. The existing controller tests (`TagControllerTest`, `SavedSearchControllerTest`, `SubscriptionControllerTest`, `MoveFeedToTagTest`, `MeTest`, `MeControllerTest`, `MeDigestControllerTest`, `PasskeyOfferControllerTest`, `AdminCatalogControllerTest`) are the contract net. They must pass unchanged, and none of their files may be edited.
- **Clean Code (CLAUDE.md) is mandatory.**
  - Use `final readonly class` with constructor promotion.
  - Guard clauses, no boolean flag parameters, at most three parameters.
  - Ids are read with `requireId()` in `src` and `tests` alike (`EntityIdCoercionRule`).
- **Comments:** one line at most, and only where a future reader would otherwise get the code wrong.
- **Every touched `src` file must be PHPMD-clean** under `composer md`, not merely free of new findings.
- **The `ThinControllerRule` allow-list only ever shrinks.** It is empty and stays empty. Never allow-list a finding from the widened rules; fix the controller.
- **PHPStan at level max:** no new baseline entries, and no `@phpstan-ignore`.
- **Gates for every task:** `composer check`, `composer md` and the task's own tests. Before `composer stan` in a task that adds a service, run `bin/console cache:clear` so the dev container XML knows the service.
- **Branch-wide gates (Finishing):** `composer check`, `composer md`, `php bin/phpunit`, `docker compose exec php composer test` and `composer infection:diff`.
- **Commit format:** `refactor(#1157): …`. Commit once per task. Never commit to `develop`.
- **Branch:** `refactor/1157-thin-controllers`, cut from `origin/develop` (which contains `a14c66c8`).

---

### Task 0: Preflight

**Files:** none changed.

Steps 1–6 and 8 run from the repository root, because their `git` pathspecs start with `backend/`. Step 7 runs from `backend/`.

- [ ] **Step 1: Check that the checkout is free.** Another session may be mid-edit, so check before you create the branch.

Run: `git status --short && git branch --show-current`
Expected: a clean tree. If it is not clean, or another session's branch is checked out, stop and ask Lars. Do not stash, reset or check out over it.

- [ ] **Step 2: Confirm that develop contains #1165 and #1170.**

Run: `git fetch origin develop && git merge-base --is-ancestor a14c66c8 origin/develop && echo CONTAINS`
Expected: `CONTAINS`. `a14c66c8` is the #1179 merge (#1170), and #1165 (fcc1e6b8) is its ancestor. If nothing is printed, stop and ask Lars.

- [ ] **Step 3: Confirm that `QueriesLiveInRepositoriesRule` still covers controllers.**

Run: `git show origin/develop:backend/tests/PhpStan/QueriesLiveInRepositoriesRule.php | grep -nE 'EXEMPT_NAMESPACES =|ALLOW_LIST ='`
Expected:
```
27:    private const array EXEMPT_NAMESPACES = ['App\\Repository\\', 'App\\Doctrine\\', 'App\\Tests\\'];
40:    private const array ALLOW_LIST = [];
```
If `App\\Controller\\` appears in either list, stop and ask Lars. The query check would then belong in `ThinControllerRule` after all.

- [ ] **Step 4: Confirm that `requireId()` still comes from the `PersistedId` trait on every entity this plan uses.**

Run: `git grep -lE '^    use PersistedId;' origin/develop -- backend/src/Entity/{User,Tag,Subscription,SavedSearch,CatalogCategory,CatalogFeed}.php | wc -l && git show origin/develop:backend/src/Entity/PersistedId.php | grep -n 'function requireId'`
Expected: `6`, then `13:    public function requireId(): int`. If either differs, stop and ask Lars.

- [ ] **Step 5: Check that the before-blocks still hold.** The before-blocks were verified against `a14c66c8`.

Run:
```bash
git diff --stat a14c66c8 origin/develop -- \
  backend/src/Controller/Api/{TagController,SavedSearchController,SubscriptionController,MeController,PasskeyOfferController}.php \
  backend/src/Controller/Admin/{AdminCatalogCategoryController,AdminCatalogFeedController}.php \
  backend/src/Entity/{Tag,Subscription,SubscriptionTag,CatalogCategory,CatalogFeed}.php \
  backend/src/Service/Catalog/CatalogFeedWriter.php backend/src/Http/Problem/TagProblems.php \
  backend/tests/Http/Problem/ProblemContractTest.php \
  backend/tests/PhpStan/{ThinControllerRule,ThinControllerRuleTest}.php backend/tests/PhpStan/data/thin-controller-fixtures.php \
  backend/phpstan.dist.neon CLAUDE.md docs/architecture.md
```
Expected: no output. If a file shows up, open its diff. Then correct the before-blocks of the task that edits that file before starting the task.

- [ ] **Step 6: Re-run the inventory sweeps.**

Run:
```bash
# A: persistence calls
git grep -nE '\->(persist|flush|remove)\(' origin/develop -- backend/src/Controller
# B: construction
git grep -nE 'new [A-Z]' origin/develop -- backend/src/Controller | grep -vE 'new (JsonResponse|Response|[A-Za-z]*Exception|RedirectResponse|StreamedResponse|BinaryFileResponse)\('
# C: chained calls
git grep -nE '(\]|\))->[a-zA-Z]+\(' origin/develop -- backend/src/Controller | grep -vE '\)->(get|is|has)[A-Z]'
# D: persistence dependencies
git grep -nE 'EntityManagerInterface|ObjectManager|ManagerRegistry' origin/develop -- backend/src/Controller
# E: non-query calls on variables
git grep -ohE '\$[a-zA-Z]+\??->[a-zA-Z]+\(' origin/develop -- backend/src/Controller | grep -v '^\$this' | sed -E 's/.*->//' | grep -vE '^(get|is|has)[A-Z]|^requireId\($' | sort -u | tr '\n' ' '
```
Expected:
- A–D show the same sites as the Inventory table, and nothing else.
- E prints exactly this: `feedCount( guard( ping( removeTag( report( setCategory( setColor( setCustomTitle( setEnabled( setEtag( setIcon( setIncludeInAllItems( setIncludeInDigest( setIncludeInForYou( setLocale( setLocked( setMaxAge( setName( setPosition( setPublic( setTitle( setUrl( test( toArray( toUpdate( values( view( withRows( `

If a new site has appeared since `a14c66c8`, stop and extend the matching task before implementing it.

- [ ] **Step 7: Record a PHPMD baseline for the touched files.**

Run: `composer md 2>&1 | grep -E 'Entity/(Tag|Subscription|SubscriptionTag|CatalogCategory|CatalogFeed)\.php|Controller/(Api/(Tag|SavedSearch|Subscription|Me|PasskeyOffer)|Admin/AdminCatalog(Category|Feed))Controller\.php|Mail/Digest/DigestEnablement|Passkey/PasskeyOffer' || echo CLEAN`
Expected: `CLEAN`. If any touched file already has a finding, stop and ask Lars. The standing rule makes the task that touches the file fix it, and that is a design change this plan does not cover.

- [ ] **Step 8: Create the branch.**

Run: `git switch -c refactor/1157-thin-controllers origin/develop`

---

### Task 1: `Positioned` + `PositionReorderer`

**Files:**
- Create: `src/Entity/Positioned.php`
- Create: `src/Service/Ordering/PositionReorderer.php`
- Create: `tests/Support/RecordingPositioned.php`
- Create: `tests/Service/Ordering/PositionReordererTest.php`
- Modify: the class lines of `src/Entity/Tag.php:13`, `src/Entity/Subscription.php:16`, `src/Entity/SubscriptionTag.php:17`, `src/Entity/CatalogCategory.php:20` and `src/Entity/CatalogFeed.php:23`

**Interfaces:**
- Produces:
  - `interface App\Entity\Positioned { public function setPosition(int $position): void; }`
  - `App\Service\Ordering\PositionReorderer::reorder(list<int> $orderedIds, array<int, Positioned> $byId): void`. It gives each `$byId[$id]` its index in `$orderedIds`, then flushes once.

- [ ] **Step 1: Write the failing test.**

`tests/Support/RecordingPositioned.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\Positioned;

final class RecordingPositioned implements Positioned
{
    public ?int $position = null;

    public function setPosition(int $position): void
    {
        $this->position = $position;
    }
}
```

`tests/Service/Ordering/PositionReordererTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Ordering;

use App\Service\Ordering\PositionReorderer;
use App\Tests\Support\RecordingPositioned;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PositionReordererTest extends TestCase
{
    public function testGivesEachItemItsIndexInTheRequestedOrderAndFlushesOnce(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('flush');
        $tenth = new RecordingPositioned();
        $twentieth = new RecordingPositioned();
        $thirtieth = new RecordingPositioned();

        (new PositionReorderer($entityManager))->reorder(
            [30, 10, 20],
            [10 => $tenth, 20 => $twentieth, 30 => $thirtieth],
        );

        self::assertSame([1, 2, 0], [$tenth->position, $twentieth->position, $thirtieth->position]);
    }
}
```

- [ ] **Step 2: Run the test and check that it fails.**

Run: `php bin/phpunit tests/Service/Ordering/PositionReordererTest.php`
Expected: an error: `Interface "App\Entity\Positioned" not found`, or `Class "App\Service\Ordering\PositionReorderer" not found`.

- [ ] **Step 3: Write the interface and the service.**

`src/Entity/Positioned.php`:
```php
<?php

declare(strict_types=1);

namespace App\Entity;

interface Positioned
{
    public function setPosition(int $position): void;
}
```

`src/Service/Ordering/PositionReorderer.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Ordering;

use App\Entity\Positioned;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PositionReorderer
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param list<int>              $orderedIds
     * @param array<int, Positioned> $byId
     */
    public function reorder(array $orderedIds, array $byId): void
    {
        foreach ($orderedIds as $index => $id) {
            $byId[$id]->setPosition($index);
        }
        $this->entityManager->flush();
    }
}
```

- [ ] **Step 4: Make the five reordered entities `Positioned`.** Each class is already in `App\Entity`, so no import is needed.

`src/Entity/Tag.php`, before:
```php
class Tag
{
```
after:
```php
class Tag implements Positioned
{
```

`src/Entity/Subscription.php`, before:
```php
class Subscription
{
```
after:
```php
class Subscription implements Positioned
{
```

`src/Entity/SubscriptionTag.php`, before:
```php
class SubscriptionTag
{
```
after:
```php
class SubscriptionTag implements Positioned
{
```

`src/Entity/CatalogCategory.php`, before:
```php
class CatalogCategory
{
```
after:
```php
class CatalogCategory implements Positioned
{
```

`src/Entity/CatalogFeed.php`, before:
```php
class CatalogFeed
{
```
after:
```php
class CatalogFeed implements Positioned
{
```

#1165 added `use PersistedId;` inside four of these classes but left every class line as shown.

- [ ] **Step 5: Run the tests and check that they pass.**

Run: `php bin/phpunit tests/Service/Ordering/PositionReordererTest.php`
Expected: `OK (1 test, 2 assertions)`.

- [ ] **Step 6: Run the gates.**

Run: `bin/console cache:clear && composer check && composer md`
Expected: all green.

- [ ] **Step 7: Commit.**

```bash
git add src/Entity/Positioned.php src/Service/Ordering/PositionReorderer.php tests/Support/RecordingPositioned.php tests/Service/Ordering/PositionReordererTest.php src/Entity/Tag.php src/Entity/Subscription.php src/Entity/SubscriptionTag.php src/Entity/CatalogCategory.php src/Entity/CatalogFeed.php
git commit -m "refactor(#1157): PositionReorderer and Positioned, the home for the five reorder loops"
```

---

### Task 2: `TagEditor`, and `TagNameTakenException` moves to `Service/Tag`

**Files:**
- Move: `src/Exception/TagNameTakenException.php` → `src/Service/Tag/Exception/TagNameTakenException.php`
- Create: `src/Service/Tag/TagEditor.php`
- Create: `tests/Service/Tag/TagEditorTest.php`
- Modify: `src/Http/Problem/TagProblems.php:7`
- Modify: `tests/Http/Problem/ProblemContractTest.php:22`
- Modify: `src/Controller/Api/TagController.php` (imports, constructor, `create`, `update`, `delete`)

**Interfaces:**
- Produces:
  - `App\Service\Tag\Exception\TagNameTakenException` (`final`, extends `\RuntimeException`)
  - `TagEditor::create(User $user, CreateTagRequest $request): Tag`
  - `TagEditor::update(Tag $tag, UpdateTagRequest $request): void`
  - `TagEditor::delete(Tag $tag): void`
  - All three flush. `create` and `update` throw `TagNameTakenException`.
- Consumes: `requireId()` (#1165).

- [ ] **Step 1: Write the failing test.**

`tests/Service/Tag/TagEditorTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Tag;

use App\Dto\Tag\CreateTagRequest;
use App\Dto\Tag\UpdateTagRequest;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Tag\Exception\TagNameTakenException;
use App\Service\Tag\TagEditor;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TagEditorTest extends DbTestCase
{
    public function testCreateAppendsTheTagAfterTheUsersExistingOnes(): void
    {
        $user = $this->user('tag-creator@example.com');
        $this->editor()->create($user, new CreateTagRequest('First'));

        $tag = $this->editor()->create($user, new CreateTagRequest('Second', '#ff8800', 'star'));

        $reloaded = $this->reload($tag);
        self::assertSame('Second', $reloaded->getName());
        self::assertSame('#ff8800', $reloaded->getColor());
        self::assertSame('star', $reloaded->getIcon());
        self::assertSame(1, $reloaded->getPosition());
    }

    public function testCreateRefusesANameTheUserAlreadyHasInAnyCase(): void
    {
        $user = $this->user('tag-duplicate@example.com');
        $this->editor()->create($user, new CreateTagRequest('News'));

        $this->expectException(TagNameTakenException::class);
        $this->editor()->create($user, new CreateTagRequest('NEWS'));
    }

    public function testCreateAllowsANameAnotherUserHas(): void
    {
        $this->editor()->create($this->user('tag-owner@example.com'), new CreateTagRequest('News'));

        $tag = $this->editor()->create($this->user('tag-other@example.com'), new CreateTagRequest('News'));

        self::assertSame('News', $this->reload($tag)->getName());
    }

    public function testUpdateMayKeepTheTagsOwnNameInAnotherCase(): void
    {
        $user = $this->user('tag-renamer@example.com');
        $tag = $this->editor()->create($user, new CreateTagRequest('news'));

        $this->editor()->update($tag, new UpdateTagRequest('News', '#000000', 'label'));

        $reloaded = $this->reload($tag);
        self::assertSame('News', $reloaded->getName());
        self::assertSame('#000000', $reloaded->getColor());
        self::assertSame('label', $reloaded->getIcon());
    }

    public function testUpdateRefusesAnotherTagsName(): void
    {
        $user = $this->user('tag-clash@example.com');
        $this->editor()->create($user, new CreateTagRequest('News'));
        $tech = $this->editor()->create($user, new CreateTagRequest('Tech'));

        $this->expectException(TagNameTakenException::class);
        $this->editor()->update($tech, new UpdateTagRequest('news'));
    }

    public function testDeleteDetachesTheTagFromItsFeedsAndRemovesIt(): void
    {
        $user = $this->user('tag-deleter@example.com');
        $tag = $this->editor()->create($user, new CreateTagRequest('Doomed'));
        $subscription = $this->taggedSubscription($user, $tag);
        $tagId = $tag->requireId();

        $this->editor()->delete($tag);

        self::assertTrue($subscription->getTags()->isEmpty());
        $subscriptionId = $subscription->requireId();
        $this->em->clear();
        self::assertNull($this->em->find(Tag::class, $tagId));
        $reloaded = $this->em->find(Subscription::class, $subscriptionId);
        self::assertInstanceOf(Subscription::class, $reloaded);
        self::assertTrue($reloaded->getTags()->isEmpty());
    }

    private function editor(): TagEditor
    {
        $editor = self::getContainer()->get(TagEditor::class);
        self::assertInstanceOf(TagEditor::class, $editor);

        return $editor;
    }

    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function taggedSubscription(User $user, Tag $tag): Subscription
    {
        $feed = new Feed('https://tag-editor.example.com/rss');
        $this->em->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->em->persist($subscription);
        $subscription->addTag($tag);
        $this->em->flush();

        return $subscription;
    }

    private function reload(Tag $tag): Tag
    {
        $id = $tag->requireId();
        $this->em->clear();
        $reloaded = $this->em->find(Tag::class, $id);
        self::assertInstanceOf(Tag::class, $reloaded);

        return $reloaded;
    }
}
```

- [ ] **Step 2: Run the test and check that it fails.**

Run: `php bin/phpunit tests/Service/Tag/TagEditorTest.php`
Expected: an error: `Class "App\Service\Tag\Exception\TagNameTakenException" not found`, or `Class "App\Service\Tag\TagEditor" not found`.

- [ ] **Step 3: Move the exception and repoint its two users.**

Run: `mkdir -p src/Service/Tag/Exception && git mv src/Exception/TagNameTakenException.php src/Service/Tag/Exception/TagNameTakenException.php`

`src/Service/Tag/Exception/TagNameTakenException.php`, before:
```php
namespace App\Exception;
```
after:
```php
namespace App\Service\Tag\Exception;
```

`src/Http/Problem/TagProblems.php`, before:
```php
use App\Exception\TagNameTakenException;
```
after:
```php
use App\Service\Tag\Exception\TagNameTakenException;
```

`tests/Http/Problem/ProblemContractTest.php`, before:
```php
use App\Exception\TagNameTakenException;
```
after:
```php
use App\Service\Tag\Exception\TagNameTakenException;
```

- [ ] **Step 4: Write `TagEditor`.**

`src/Service/Tag/TagEditor.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Tag;

use App\Dto\Tag\CreateTagRequest;
use App\Dto\Tag\UpdateTagRequest;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\SubscriptionRepository;
use App\Repository\TagRepository;
use App\Service\Tag\Exception\TagNameTakenException;
use Doctrine\ORM\EntityManagerInterface;

final readonly class TagEditor
{
    public function __construct(
        private TagRepository $tags,
        private SubscriptionRepository $subscriptions,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function create(User $user, CreateTagRequest $request): Tag
    {
        if ($this->tags->existsForUserAndName($user->requireId(), $request->name)) {
            throw new TagNameTakenException();
        }

        $tag = new Tag($user, $request->name);
        $tag->setColor($request->color);
        $tag->setIcon($request->icon);
        $tag->setPosition($this->tags->nextPositionForUser($user->requireId()));
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }

    public function update(Tag $tag, UpdateTagRequest $request): void
    {
        if ($this->tags->existsForUserAndName($tag->getUser()->requireId(), $request->name, $tag->requireId())) {
            throw new TagNameTakenException();
        }

        $tag->setName($request->name);
        $tag->setColor($request->color);
        $tag->setIcon($request->icon);
        $this->entityManager->flush();
    }

    public function delete(Tag $tag): void
    {
        // Detach from every subscription first, portably across SQLite and MySQL.
        $carriers = $this->subscriptions->findForUserByTagId($tag->getUser()->requireId(), $tag->requireId());
        foreach ($carriers as $subscription) {
            $subscription->removeTag($tag);
        }
        $this->entityManager->remove($tag);
        $this->entityManager->flush();
    }
}
```

- [ ] **Step 5: Switch `TagController` over to the editor.**

Imports, before:
```php
use App\Entity\Tag;
use App\Entity\User;
use App\Exception\TagNameTakenException;
use App\Http\TagJson;
use App\Repository\SubscriptionRepository;
use App\Repository\SubscriptionTagRepository;
use App\Repository\TagRepository;
use App\Service\Reader\ExactSetGuard;
use Doctrine\ORM\EntityManagerInterface;
```
after:
```php
use App\Entity\Tag;
use App\Entity\User;
use App\Http\TagJson;
use App\Repository\SubscriptionTagRepository;
use App\Repository\TagRepository;
use App\Service\Reader\ExactSetGuard;
use App\Service\Tag\TagEditor;
use Doctrine\ORM\EntityManagerInterface;
```

Constructor, before:
```php
    public function __construct(
        private TagRepository $tags,
        private SubscriptionRepository $subscriptions,
        private SubscriptionTagRepository $subscriptionTags,
        private EntityManagerInterface $em,
        private ExactSetGuard $exactSet,
    ) {
    }
```
after:
```php
    public function __construct(
        private TagRepository $tags,
        private SubscriptionTagRepository $subscriptionTags,
        private EntityManagerInterface $em,
        private ExactSetGuard $exactSet,
        private TagEditor $editor,
    ) {
    }
```

`create`, before:
```php
    public function create(#[CurrentUser] User $user, #[MapRequestPayload] CreateTagRequest $request): JsonResponse
    {
        if ($this->tags->existsForUserAndName($user->requireId(), $request->name)) {
            throw new TagNameTakenException();
        }

        $tag = new Tag($user, $request->name);
        $tag->setColor($request->color);
        $tag->setIcon($request->icon);
        $tag->setPosition($this->tags->nextPositionForUser($user->requireId()));
        $this->em->persist($tag);
        $this->em->flush();

        return new JsonResponse(['tag' => TagJson::one($tag)], Response::HTTP_CREATED);
    }
```
after:
```php
    public function create(#[CurrentUser] User $user, #[MapRequestPayload] CreateTagRequest $request): JsonResponse
    {
        return new JsonResponse(
            ['tag' => TagJson::one($this->editor->create($user, $request))],
            Response::HTTP_CREATED,
        );
    }
```

`update`, before:
```php
        $tag = $this->tags->findOneOwnedBy($id, $user->requireId())
            ?? throw new NotFoundHttpException('No such tag.');

        if ($this->tags->existsForUserAndName($user->requireId(), $request->name, $id)) {
            throw new TagNameTakenException();
        }

        $tag->setName($request->name);
        $tag->setColor($request->color);
        $tag->setIcon($request->icon);
        $this->em->flush();

        return new JsonResponse(['tag' => TagJson::one($tag)]);
```
after:
```php
        $tag = $this->tags->findOneOwnedBy($id, $user->requireId())
            ?? throw new NotFoundHttpException('No such tag.');

        $this->editor->update($tag, $request);

        return new JsonResponse(['tag' => TagJson::one($tag)]);
```

`delete`, before:
```php
        $tag = $this->tags->findOneOwnedBy($id, $user->requireId())
            ?? throw new NotFoundHttpException('No such tag.');

        // Detach from every subscription first (portable across SQLite/MySQL).
        // A tag's subscriptions are always its own owner's, so findForUserByTagId
        // (userId + tagId) resolves the identical set findByTag(Tag) once did.
        foreach ($this->subscriptions->findForUserByTagId($user->requireId(), $id) as $sub) {
            $sub->removeTag($tag);
        }
        $this->em->remove($tag);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
```
after:
```php
        $tag = $this->tags->findOneOwnedBy($id, $user->requireId())
            ?? throw new NotFoundHttpException('No such tag.');

        $this->editor->delete($tag);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
```

- [ ] **Step 6: Run the tests and check that they pass.**

Run: `php bin/phpunit tests/Service/Tag/TagEditorTest.php tests/Controller/Api/TagControllerTest.php tests/Http/Problem/ProblemContractTest.php`
Expected: `OK`. `TagControllerTest` must pass unchanged.

- [ ] **Step 7: Run the gates.**

Run: `bin/console cache:clear && composer check && composer md`
Expected: all green. `git grep -n 'App\\Exception\\TagNameTakenException' -- src tests` prints nothing.

- [ ] **Step 8: Commit.**

```bash
git add src/Service/Tag src/Exception src/Http/Problem/TagProblems.php tests/Http/Problem/ProblemContractTest.php tests/Service/Tag/TagEditorTest.php src/Controller/Api/TagController.php
git commit -m "refactor(#1157): TagEditor owns tag writes; TagNameTakenException moves to Service/Tag"
```

---

### Task 3: `TagOrdering`

**Files:**
- Create: `src/Service/Tag/TagOrdering.php`
- Create: `tests/Service/Tag/TagOrderingTest.php`
- Modify: `src/Controller/Api/TagController.php` (imports, constructor, `reorder`, `feedOrder`)

**Interfaces:**
- Consumes: `PositionReorderer::reorder(list<int>, array<int, Positioned>): void` (Task 1), `TagEditor` (Task 2).
- Produces:
  - `TagOrdering::reorder(User $user, ReorderTagsRequest $request): list<Tag>`, which returns the tags in the new order.
  - `TagOrdering::orderFeeds(Tag $tag, TagFeedOrderRequest $request): void`.
  - Both throw `App\Exception\InvalidSelectionException` for a non-permutation.

- [ ] **Step 1: Write the failing test.**

`tests/Service/Tag/TagOrderingTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Tag;

use App\Dto\Tag\ReorderTagsRequest;
use App\Dto\Tag\TagFeedOrderRequest;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Exception\InvalidSelectionException;
use App\Repository\TagRepository;
use App\Service\Tag\TagOrdering;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class TagOrderingTest extends DbTestCase
{
    public function testReorderGivesEachTagItsIndexAndReturnsThemInThatOrder(): void
    {
        $user = $this->user('tag-orderer@example.com');
        $first = $this->tag($user, 'A', 0);
        $second = $this->tag($user, 'B', 1);
        $third = $this->tag($user, 'C', 2);

        $ordered = $this->ordering()->reorder(
            $user,
            new ReorderTagsRequest([$third->requireId(), $first->requireId(), $second->requireId()]),
        );

        self::assertSame([$third, $first, $second], $ordered);
        $this->em->clear();
        self::assertSame(['C', 'A', 'B'], $this->tagNamesInOrder($user));
    }

    public function testReorderRefusesAListThatIsNotExactlyTheUsersTags(): void
    {
        $user = $this->user('tag-partial@example.com');
        $first = $this->tag($user, 'A', 0);
        $this->tag($user, 'B', 1);

        $this->expectException(InvalidSelectionException::class);
        $this->expectExceptionMessage('tagIds must list exactly your tags.');
        $this->ordering()->reorder($user, new ReorderTagsRequest([$first->requireId()]));
    }

    public function testOrderFeedsGivesEachFeedItsIndexWithinTheTag(): void
    {
        $user = $this->user('tag-feed-orderer@example.com');
        $tag = $this->tag($user, 'News', 0);
        $first = $this->taggedSubscription($user, 'https://first.tag-ordering.example.com/rss', $tag, 0);
        $second = $this->taggedSubscription($user, 'https://second.tag-ordering.example.com/rss', $tag, 1);

        $this->ordering()->orderFeeds(
            $tag,
            new TagFeedOrderRequest([$second->requireId(), $first->requireId()]),
        );

        $this->em->clear();
        self::assertSame(1, $this->joinPosition($first->requireId(), $tag->requireId()));
        self::assertSame(0, $this->joinPosition($second->requireId(), $tag->requireId()));
    }

    public function testOrderFeedsRefusesFeedsTheTagDoesNotCarry(): void
    {
        $user = $this->user('tag-feed-stranger@example.com');
        $tag = $this->tag($user, 'News', 0);
        $this->taggedSubscription($user, 'https://carried.tag-ordering.example.com/rss', $tag, 0);

        $this->expectException(InvalidSelectionException::class);
        $this->expectExceptionMessage("subscriptionIds must list exactly this tag's feeds.");
        $this->ordering()->orderFeeds($tag, new TagFeedOrderRequest([999999]));
    }

    private function ordering(): TagOrdering
    {
        $ordering = self::getContainer()->get(TagOrdering::class);
        self::assertInstanceOf(TagOrdering::class, $ordering);

        return $ordering;
    }

    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function tag(User $user, string $name, int $position): Tag
    {
        $tag = new Tag($user, $name);
        $tag->setPosition($position);
        $this->em->persist($tag);
        $this->em->flush();

        return $tag;
    }

    private function taggedSubscription(User $user, string $url, Tag $tag, int $position): Subscription
    {
        $feed = new Feed($url);
        $this->em->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->em->persist($subscription);
        $subscription->addTag($tag, $position);
        $this->em->flush();

        return $subscription;
    }

    /** @return list<string> */
    private function tagNamesInOrder(User $user): array
    {
        $tags = self::getContainer()->get(TagRepository::class);
        self::assertInstanceOf(TagRepository::class, $tags);

        return array_map(static fn (Tag $tag): string => $tag->getName(), $tags->findForUser($user->requireId()));
    }

    private function joinPosition(int $subscriptionId, int $tagId): int
    {
        $subscription = $this->em->find(Subscription::class, $subscriptionId);
        self::assertInstanceOf(Subscription::class, $subscription);
        foreach ($subscription->getSubscriptionTags() as $join) {
            if ($join->getTag()->requireId() === $tagId) {
                return $join->getPosition();
            }
        }
        self::fail('The subscription does not carry the tag.');
    }
}
```

- [ ] **Step 2: Run the test and check that it fails.**

Run: `php bin/phpunit tests/Service/Tag/TagOrderingTest.php`
Expected: an error: `Class "App\Service\Tag\TagOrdering" not found`.

- [ ] **Step 3: Write `TagOrdering`.**

`src/Service/Tag/TagOrdering.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Tag;

use App\Dto\Tag\ReorderTagsRequest;
use App\Dto\Tag\TagFeedOrderRequest;
use App\Entity\Tag;
use App\Entity\User;
use App\Repository\SubscriptionTagRepository;
use App\Repository\TagRepository;
use App\Service\Ordering\PositionReorderer;
use App\Service\Reader\ExactSetGuard;

final readonly class TagOrdering
{
    public function __construct(
        private TagRepository $tags,
        private SubscriptionTagRepository $subscriptionTags,
        private ExactSetGuard $exactSet,
        private PositionReorderer $reorderer,
    ) {
    }

    /** @return list<Tag> */
    public function reorder(User $user, ReorderTagsRequest $request): array
    {
        $byId = $this->ownedTagsById($user);
        $this->exactSet->assertPermutation($request->tagIds, array_keys($byId), 'tagIds must list exactly your tags.');
        $this->reorderer->reorder($request->tagIds, $byId);

        return array_map(static fn (int $id): Tag => $byId[$id], $request->tagIds);
    }

    public function orderFeeds(Tag $tag, TagFeedOrderRequest $request): void
    {
        $joinsBySubscriptionId = $this->subscriptionTags->forTagBySubscriptionId($tag);
        $this->exactSet->assertPermutation(
            $request->subscriptionIds,
            array_keys($joinsBySubscriptionId),
            "subscriptionIds must list exactly this tag's feeds.",
        );
        $this->reorderer->reorder($request->subscriptionIds, $joinsBySubscriptionId);
    }

    /** @return array<int, Tag> */
    private function ownedTagsById(User $user): array
    {
        $byId = [];
        foreach ($this->tags->findForUser($user->requireId()) as $tag) {
            $byId[$tag->requireId()] = $tag;
        }

        return $byId;
    }
}
```

- [ ] **Step 4: Switch `TagController` over to `TagOrdering`.**

Imports, before (the state after Task 2):
```php
use App\Entity\Tag;
use App\Entity\User;
use App\Http\TagJson;
use App\Repository\SubscriptionTagRepository;
use App\Repository\TagRepository;
use App\Service\Reader\ExactSetGuard;
use App\Service\Tag\TagEditor;
use Doctrine\ORM\EntityManagerInterface;
```
after:
```php
use App\Entity\Tag;
use App\Entity\User;
use App\Http\TagJson;
use App\Repository\TagRepository;
use App\Service\Tag\TagEditor;
use App\Service\Tag\TagOrdering;
```

Constructor, before (the state after Task 2):
```php
    public function __construct(
        private TagRepository $tags,
        private SubscriptionTagRepository $subscriptionTags,
        private EntityManagerInterface $em,
        private ExactSetGuard $exactSet,
        private TagEditor $editor,
    ) {
    }
```
after:
```php
    public function __construct(
        private TagRepository $tags,
        private TagEditor $editor,
        private TagOrdering $ordering,
    ) {
    }
```

`reorder` body, before:
```php
    ): JsonResponse {
        $owned = $this->tags->findForUser($user->requireId());
        /** @var array<int, Tag> $byId */
        $byId = [];
        foreach ($owned as $tag) {
            $byId[$tag->requireId()] = $tag;
        }

        $this->exactSet->assertPermutation($request->tagIds, array_keys($byId), 'tagIds must list exactly your tags.');

        foreach ($request->tagIds as $index => $tagId) {
            $byId[$tagId]->setPosition($index);
        }
        $this->em->flush();

        return new JsonResponse([
            'tags' => array_map(static fn (int $id): array => TagJson::one($byId[$id]), $request->tagIds),
        ]);
    }
```
after:
```php
    ): JsonResponse {
        return new JsonResponse([
            'tags' => array_map(
                static fn (Tag $tag): array => TagJson::one($tag),
                $this->ordering->reorder($user, $request),
            ),
        ]);
    }
```

`feedOrder` body, before:
```php
        $tag = $this->tags->findOneOwnedBy($id, $user->requireId())
            ?? throw new NotFoundHttpException('No such tag.');

        $joinsBySubId = $this->subscriptionTags->forTagBySubscriptionId($tag);
        $this->exactSet->assertPermutation(
            $request->subscriptionIds,
            array_keys($joinsBySubId),
            "subscriptionIds must list exactly this tag's feeds.",
        );

        foreach ($request->subscriptionIds as $index => $subscriptionId) {
            $joinsBySubId[$subscriptionId]->setPosition($index);
        }
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
```
after:
```php
        $tag = $this->tags->findOneOwnedBy($id, $user->requireId())
            ?? throw new NotFoundHttpException('No such tag.');

        $this->ordering->orderFeeds($tag, $request);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
```

The `reorder` and `feedOrder` action docblocks describe the wire contract and stay as they are.

- [ ] **Step 5: Run the tests and check that they pass.**

Run: `php bin/phpunit tests/Service/Tag tests/Controller/Api/TagControllerTest.php`
Expected: `OK`.

- [ ] **Step 6: Run the gates.**

Run: `bin/console cache:clear && composer check && composer md`
Expected: all green. `git grep -nE 'EntityManagerInterface|->flush\(' -- src/Controller/Api/TagController.php` prints nothing.

- [ ] **Step 7: Commit.**

```bash
git add src/Service/Tag/TagOrdering.php tests/Service/Tag/TagOrderingTest.php src/Controller/Api/TagController.php
git commit -m "refactor(#1157): TagOrdering owns the tag and per-tag feed reorders"
```

---

### Task 4: `SavedSearchEditor`

**Files:**
- Create: `src/Service/Search/SavedSearchOutcome.php`
- Create: `src/Service/Search/SavedSearchEditor.php`
- Create: `tests/Service/Search/SavedSearchEditorTest.php`
- Modify: `src/Controller/Api/SavedSearchController.php` (imports, the class constant, constructor, `create`, `update`, `delete`)

**Interfaces:**
- Produces:
  - `SavedSearchOutcome` with `public SavedSearch $savedSearch`, `public bool $isNew`, and the named constructors `created()` and `existing()`
  - `SavedSearchEditor::save(User $user, CreateSavedSearchRequest $request): SavedSearchOutcome`
  - `SavedSearchEditor::changeDigestInclusion(SavedSearch $savedSearch, UpdateSavedSearchRequest $request): void`
  - `SavedSearchEditor::delete(SavedSearch $savedSearch): void`

- [ ] **Step 1: Write the failing test.**

`tests/Service/Search/SavedSearchEditorTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Dto\SavedSearch\CreateSavedSearchRequest;
use App\Dto\SavedSearch\UpdateSavedSearchRequest;
use App\Entity\SavedSearch;
use App\Entity\User;
use App\Service\Search\SavedSearchEditor;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SavedSearchEditorTest extends DbTestCase
{
    public function testSavingANewTermCreatesItWithItsSlug(): void
    {
        $user = $this->user('search-saver@example.com');

        $outcome = $this->editor()->save($user, new CreateSavedSearchRequest('climate change'));

        self::assertTrue($outcome->isNew);
        $id = $outcome->savedSearch->requireId();
        self::assertSame($id . '-climate-change', $this->reload($outcome->savedSearch)->getSlug());
    }

    public function testSavingAnAlreadySavedTermReturnsTheExistingRow(): void
    {
        $user = $this->user('search-resaver@example.com');
        $first = $this->editor()->save($user, new CreateSavedSearchRequest('punk', true));

        $again = $this->editor()->save($user, new CreateSavedSearchRequest('punk', true));

        self::assertFalse($again->isNew);
        self::assertSame($first->savedSearch->requireId(), $again->savedSearch->requireId());
    }

    public function testChangeDigestInclusionPersists(): void
    {
        $savedSearch = $this->editor()
            ->save($this->user('search-digest@example.com'), new CreateSavedSearchRequest('opera'))
            ->savedSearch;
        $wanted = !$savedSearch->isIncludeInDigest();

        $this->editor()->changeDigestInclusion($savedSearch, new UpdateSavedSearchRequest($wanted));

        self::assertSame($wanted, $this->reload($savedSearch)->isIncludeInDigest());
    }

    public function testDeleteRemovesTheRow(): void
    {
        $savedSearch = $this->editor()
            ->save($this->user('search-deleter@example.com'), new CreateSavedSearchRequest('jazz'))
            ->savedSearch;
        $id = $savedSearch->requireId();

        $this->editor()->delete($savedSearch);

        $this->em->clear();
        self::assertNull($this->em->find(SavedSearch::class, $id));
    }

    private function editor(): SavedSearchEditor
    {
        $editor = self::getContainer()->get(SavedSearchEditor::class);
        self::assertInstanceOf(SavedSearchEditor::class, $editor);

        return $editor;
    }

    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function reload(SavedSearch $savedSearch): SavedSearch
    {
        $id = $savedSearch->requireId();
        $this->em->clear();
        $reloaded = $this->em->find(SavedSearch::class, $id);
        self::assertInstanceOf(SavedSearch::class, $reloaded);

        return $reloaded;
    }
}
```

- [ ] **Step 2: Run the test and check that it fails.**

Run: `php bin/phpunit tests/Service/Search/SavedSearchEditorTest.php`
Expected: an error: `Class "App\Service\Search\SavedSearchEditor" not found`.

- [ ] **Step 3: Write the outcome and the editor.**

`src/Service/Search/SavedSearchOutcome.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Entity\SavedSearch;

final readonly class SavedSearchOutcome
{
    private function __construct(
        public SavedSearch $savedSearch,
        public bool $isNew,
    ) {
    }

    public static function created(SavedSearch $savedSearch): self
    {
        return new self($savedSearch, true);
    }

    public static function existing(SavedSearch $savedSearch): self
    {
        return new self($savedSearch, false);
    }
}
```

`src/Service/Search/SavedSearchEditor.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Dto\SavedSearch\CreateSavedSearchRequest;
use App\Dto\SavedSearch\UpdateSavedSearchRequest;
use App\Entity\SavedSearch;
use App\Entity\User;
use App\Repository\SavedSearchRepository;
use App\Service\Search\Membership\SavedSearchMembershipSweep;
use App\Service\Search\Membership\SweepBudget;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SavedSearchEditor
{
    private const int CREATE_SWEEP_BUDGET_SECONDS = 8;

    public function __construct(
        private SavedSearchRepository $savedSearches,
        private SavedSearchMembershipSweep $sweep,
        private SavedSearchSlug $slug,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function save(User $user, CreateSavedSearchRequest $request): SavedSearchOutcome
    {
        $existing = $this->savedSearches->findOneForUserByTerm(
            $user->requireId(),
            $request->term,
            $request->wholeWord,
            $request->phrase,
        );
        if (null !== $existing) {
            return SavedSearchOutcome::existing($existing);
        }

        return SavedSearchOutcome::created($this->create($user, $request));
    }

    public function changeDigestInclusion(SavedSearch $savedSearch, UpdateSavedSearchRequest $request): void
    {
        $savedSearch->setIncludeInDigest($request->includeInDigest);
        $this->entityManager->flush();
    }

    public function delete(SavedSearch $savedSearch): void
    {
        $this->entityManager->remove($savedSearch);
        $this->entityManager->flush();
    }

    private function create(User $user, CreateSavedSearchRequest $request): SavedSearch
    {
        $savedSearch = new SavedSearch($user, $request->term, $request->wholeWord, $request->phrase);
        $this->entityManager->persist($savedSearch);
        $this->entityManager->flush();
        $this->slug->assignTo($savedSearch);
        $this->entityManager->flush();
        $this->sweep->sweepOne($savedSearch, SweepBudget::seconds(self::CREATE_SWEEP_BUDGET_SECONDS));

        return $savedSearch;
    }
}
```

- [ ] **Step 4: Switch `SavedSearchController` over to the editor.**

Imports, before:
```php
use App\Http\SavedSearchJson;
use App\Repository\SavedSearchRepository;
use App\Service\Search\Membership\SavedSearchMembershipSweep;
use App\Service\Search\Membership\SweepBudget;
use App\Service\Search\SavedSearchSlug;
use App\Service\Search\SavedSearchTallies;
use Doctrine\ORM\EntityManagerInterface;
```
after:
```php
use App\Http\SavedSearchJson;
use App\Repository\SavedSearchRepository;
use App\Service\Search\SavedSearchEditor;
use App\Service\Search\SavedSearchTallies;
```

Constant and constructor, before:
```php
    private const int CREATE_SWEEP_BUDGET_SECONDS = 8;

    public function __construct(
        private SavedSearchRepository $savedSearches,
        private SavedSearchTallies $tallies,
        private SavedSearchMembershipSweep $sweep,
        private EntityManagerInterface $em,
        private SavedSearchSlug $slug,
    ) {
    }
```
after:
```php
    public function __construct(
        private SavedSearchRepository $savedSearches,
        private SavedSearchTallies $tallies,
        private SavedSearchEditor $editor,
    ) {
    }
```

`create` body, before:
```php
    ): JsonResponse {
        $userId = $user->requireId();
        // Saving a term already saved is idempotent, and answers 200 with the
        // row that was there rather than 201 with a second one.
        $savedSearch = $this->savedSearches->findOneForUserByTerm(
            $userId,
            $request->term,
            $request->wholeWord,
            $request->phrase,
        );
        $status = $savedSearch === null ? Response::HTTP_CREATED : Response::HTTP_OK;

        if ($savedSearch === null) {
            $savedSearch = new SavedSearch($user, $request->term, $request->wholeWord, $request->phrase);
            $this->em->persist($savedSearch);
            $this->em->flush();
            $this->slug->assignTo($savedSearch);
            $this->em->flush();
            $this->sweep->sweepOne($savedSearch, SweepBudget::seconds(self::CREATE_SWEEP_BUDGET_SECONDS));
        }

        return new JsonResponse(
            ['savedSearch' => SavedSearchJson::one($savedSearch, $this->tallies->forOne($savedSearch, $userId))],
            $status,
        );
    }
```
after:
```php
    ): JsonResponse {
        $userId = $user->requireId();
        $outcome = $this->editor->save($user, $request);
        $savedSearch = $outcome->savedSearch;

        return new JsonResponse(
            ['savedSearch' => SavedSearchJson::one($savedSearch, $this->tallies->forOne($savedSearch, $userId))],
            $outcome->isNew ? Response::HTTP_CREATED : Response::HTTP_OK,
        );
    }
```

`update` body, before:
```php
        $savedSearch->setIncludeInDigest($request->includeInDigest);
        $this->em->flush();
```
after:
```php
        $this->editor->changeDigestInclusion($savedSearch, $request);
```

`delete` body, before:
```php
        $this->em->remove($savedSearch);
        $this->em->flush();
```
after:
```php
        $this->editor->delete($savedSearch);
```

- [ ] **Step 5: Run the tests and check that they pass.**

Run: `php bin/phpunit tests/Service/Search/SavedSearchEditorTest.php tests/Controller/Api/SavedSearchControllerTest.php tests/Controller/Api/SavedSearchSlugRoutingTest.php`
Expected: `OK`. `testCreateListWithUnreadMatchIdsAndDelete` still proves that the create-time sweep runs.

- [ ] **Step 6: Run the gates.**

Run: `bin/console cache:clear && composer check && composer md`
Expected: all green.

- [ ] **Step 7: Commit.**

```bash
git add src/Service/Search/SavedSearchOutcome.php src/Service/Search/SavedSearchEditor.php tests/Service/Search/SavedSearchEditorTest.php src/Controller/Api/SavedSearchController.php
git commit -m "refactor(#1157): SavedSearchEditor owns find-or-create, slug, sweep, update and delete"
```

---

### Task 5: `SubscriptionEditor`

**Files:**
- Create: `src/Service/Subscription/SubscriptionEditor.php`
- Create: `tests/Service/Subscription/SubscriptionEditorTest.php`
- Modify: `src/Controller/Api/SubscriptionController.php` (imports, constructor, `update`, `moveToTag`, `reorder`)

**Interfaces:**
- Consumes: `PositionReorderer` (Task 1), and the existing `SubscriptionTagSync::sync(Subscription, list<int>, int)`, `FeedTagMove::move(Subscription, ?int, ?int, ?int, int)` and `OwnedSubscriptions::resolve(list<int>, int): array<int, Subscription>`.
- Produces:
  - `SubscriptionEditor::update(Subscription $subscription, UpdateSubscriptionRequest $request): void`
  - `SubscriptionEditor::moveToTag(Subscription $subscription, MoveFeedToTagRequest $request): void`
  - `SubscriptionEditor::reorder(User $user, ReorderSubscriptionsRequest $request): void`

- [ ] **Step 1: Write the failing test.**

`tests/Service/Subscription/SubscriptionEditorTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Subscription;

use App\Dto\Subscription\MoveFeedToTagRequest;
use App\Dto\Subscription\ReorderSubscriptionsRequest;
use App\Dto\Subscription\UpdateSubscriptionRequest;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\Tag;
use App\Entity\User;
use App\Service\Subscription\SubscriptionEditor;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SubscriptionEditorTest extends DbTestCase
{
    public function testUpdateStoresAnEmptyCustomTitleAsNone(): void
    {
        $user = $this->user('title-clear@example.com');
        $subscription = $this->subscription($user, 'https://clear.editor.example.com/rss');
        $subscription->setCustomTitle('Old');
        $this->em->flush();

        $this->editor()->update($subscription, new UpdateSubscriptionRequest(''));

        self::assertNull($this->reload($subscription)->getCustomTitle());
    }

    public function testUpdateKeepsANonEmptyCustomTitle(): void
    {
        $user = $this->user('title-keep@example.com');
        $subscription = $this->subscription($user, 'https://keep.editor.example.com/rss');

        $this->editor()->update($subscription, new UpdateSubscriptionRequest('Mine'));

        self::assertSame('Mine', $this->reload($subscription)->getCustomTitle());
    }

    public function testUpdateLeavesFlagsTheRequestOmits(): void
    {
        $user = $this->user('flags-keep@example.com');
        $subscription = $this->subscription($user, 'https://flags-keep.editor.example.com/rss');
        $subscription->setIncludeInAllItems(false);
        $subscription->setIncludeInForYou(false);
        $this->em->flush();

        $this->editor()->update($subscription, new UpdateSubscriptionRequest(null));

        $reloaded = $this->reload($subscription);
        self::assertFalse($reloaded->isIncludeInAllItems());
        self::assertFalse($reloaded->isIncludeInForYou());
    }

    public function testUpdateAppliesFlagsTheRequestCarries(): void
    {
        $user = $this->user('flags-set@example.com');
        $subscription = $this->subscription($user, 'https://flags-set.editor.example.com/rss');
        $subscription->setIncludeInAllItems(true);
        $subscription->setIncludeInForYou(true);
        $this->em->flush();

        $this->editor()->update($subscription, new UpdateSubscriptionRequest(null, [], false, false));

        $reloaded = $this->reload($subscription);
        self::assertFalse($reloaded->isIncludeInAllItems());
        self::assertFalse($reloaded->isIncludeInForYou());
    }

    public function testUpdateSyncsTheRequestedTags(): void
    {
        $user = $this->user('tags-sync@example.com');
        $tag = $this->tag($user, 'Synced');
        $subscription = $this->subscription($user, 'https://sync.editor.example.com/rss');

        $this->editor()->update($subscription, new UpdateSubscriptionRequest(null, [$tag->requireId()]));

        self::assertSame(['Synced'], $this->tagNames($this->reload($subscription)));
    }

    public function testMoveToTagPersistsTheMove(): void
    {
        $user = $this->user('move@example.com');
        $news = $this->tag($user, 'News');
        $tech = $this->tag($user, 'Tech');
        $subscription = $this->subscription($user, 'https://move.editor.example.com/rss');
        $subscription->addTag($news);
        $this->em->flush();

        $this->editor()->moveToTag($subscription, new MoveFeedToTagRequest($news->requireId(), $tech->requireId()));

        self::assertSame(['Tech'], $this->tagNames($this->reload($subscription)));
    }

    public function testReorderGivesEachFeedItsIndex(): void
    {
        $user = $this->user('reorder@example.com');
        $first = $this->subscription($user, 'https://first.editor.example.com/rss');
        $second = $this->subscription($user, 'https://second.editor.example.com/rss');

        $this->editor()->reorder($user, new ReorderSubscriptionsRequest([$second->requireId(), $first->requireId()]));

        self::assertSame(1, $this->reload($first)->getPosition());
        self::assertSame(0, $this->reload($second)->getPosition());
    }

    private function editor(): SubscriptionEditor
    {
        $editor = self::getContainer()->get(SubscriptionEditor::class);
        self::assertInstanceOf(SubscriptionEditor::class, $editor);

        return $editor;
    }

    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function tag(User $user, string $name): Tag
    {
        $tag = new Tag($user, $name);
        $this->em->persist($tag);
        $this->em->flush();

        return $tag;
    }

    private function subscription(User $user, string $url): Subscription
    {
        $feed = new Feed($url);
        $this->em->persist($feed);
        $subscription = new Subscription($user, $feed, new \DateTimeImmutable('2026-01-01T00:00:00Z'));
        $this->em->persist($subscription);
        $this->em->flush();

        return $subscription;
    }

    private function reload(Subscription $subscription): Subscription
    {
        $id = $subscription->requireId();
        $this->em->clear();
        $reloaded = $this->em->find(Subscription::class, $id);
        self::assertInstanceOf(Subscription::class, $reloaded);

        return $reloaded;
    }

    /** @return list<string> */
    private function tagNames(Subscription $subscription): array
    {
        return array_values(array_map(
            static fn (Tag $tag): string => $tag->getName(),
            $subscription->getTags()->toArray(),
        ));
    }
}
```

`testReorderGivesEachFeedItsIndex` calls `reload()` twice. The first call clears the entity manager, and the second only needs the id, which `requireId()` still returns on a detached object.

- [ ] **Step 2: Run the test and check that it fails.**

Run: `php bin/phpunit tests/Service/Subscription/SubscriptionEditorTest.php`
Expected: an error: `Class "App\Service\Subscription\SubscriptionEditor" not found`.

- [ ] **Step 3: Write `SubscriptionEditor`.**

`src/Service/Subscription/SubscriptionEditor.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Dto\Subscription\MoveFeedToTagRequest;
use App\Dto\Subscription\ReorderSubscriptionsRequest;
use App\Dto\Subscription\UpdateSubscriptionRequest;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Ordering\PositionReorderer;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SubscriptionEditor
{
    public function __construct(
        private SubscriptionTagSync $tagSync,
        private FeedTagMove $feedTagMove,
        private OwnedSubscriptions $ownedSubscriptions,
        private PositionReorderer $reorderer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function update(Subscription $subscription, UpdateSubscriptionRequest $request): void
    {
        $subscription->setCustomTitle('' === (string) $request->customTitle ? null : $request->customTitle);
        $this->tagSync->sync($subscription, $request->tagIds, $subscription->getUser()->requireId());
        $this->applyFlags($subscription, $request);
        $this->entityManager->flush();
    }

    public function moveToTag(Subscription $subscription, MoveFeedToTagRequest $request): void
    {
        $this->feedTagMove->move(
            $subscription,
            $request->fromTagId,
            $request->toTagId,
            $request->position,
            $subscription->getUser()->requireId(),
        );
        $this->entityManager->flush();
    }

    public function reorder(User $user, ReorderSubscriptionsRequest $request): void
    {
        $this->reorderer->reorder(
            $request->subscriptionIds,
            $this->ownedSubscriptions->resolve($request->subscriptionIds, $user->requireId()),
        );
    }

    private function applyFlags(Subscription $subscription, UpdateSubscriptionRequest $request): void
    {
        if (null !== $request->includeInAllItems) {
            $subscription->setIncludeInAllItems($request->includeInAllItems);
        }
        if (null !== $request->includeInForYou) {
            $subscription->setIncludeInForYou($request->includeInForYou);
        }
    }
}
```

- [ ] **Step 4: Switch `SubscriptionController` over to the editor.**

Imports, before:
```php
use App\Service\Subscription\BulkSubscriptionUpdater;
use App\Service\Subscription\FeedTagMove;
use App\Service\Subscription\OwnedSubscriptions;
use App\Service\Subscription\SubscriptionService;
use App\Service\Subscription\SubscriptionTagSync;
use Doctrine\ORM\EntityManagerInterface;
```
after:
```php
use App\Service\Subscription\BulkSubscriptionUpdater;
use App\Service\Subscription\OwnedSubscriptions;
use App\Service\Subscription\SubscriptionEditor;
use App\Service\Subscription\SubscriptionService;
```

Constructor, before:
```php
    public function __construct(
        private SubscriptionService $subscriptions,
        private SubscriptionRepository $subscriptionRepo,
        private SubscriptionTagSync $tagSync,
        private TagRepository $tags,
        private EntryStateRepository $entryStates,
        private EntityManagerInterface $em,
        private OwnedSubscriptions $ownedSubscriptions,
        private BulkSubscriptionUpdater $bulkUpdater,
        private FeedTagMove $feedTagMove,
    ) {
    }
```
after:
```php
    public function __construct(
        private SubscriptionService $subscriptions,
        private SubscriptionRepository $subscriptionRepo,
        private TagRepository $tags,
        private EntryStateRepository $entryStates,
        private OwnedSubscriptions $ownedSubscriptions,
        private BulkSubscriptionUpdater $bulkUpdater,
        private SubscriptionEditor $editor,
    ) {
    }
```

`update` body, before:
```php
        $sub = $this->subscriptionRepo->findOneOwnedBy($id, $user->requireId())
            ?? throw new NotFoundHttpException('No such subscription.');

        $sub->setCustomTitle('' === (string) $request->customTitle ? null : $request->customTitle);

        $this->tagSync->sync($sub, $request->tagIds, $user->requireId());

        // null on either flag means "leave the stored value unchanged", matching
        // EntryController::updateState()'s nullable-PATCH convention (#695).
        if (null !== $request->includeInAllItems) {
            $sub->setIncludeInAllItems($request->includeInAllItems);
        }
        if (null !== $request->includeInForYou) {
            $sub->setIncludeInForYou($request->includeInForYou);
        }

        $this->em->flush();

        return new JsonResponse(['subscription' => SubscriptionJson::one($sub)]);
```
after:
```php
        $sub = $this->subscriptionRepo->findOneOwnedBy($id, $user->requireId())
            ?? throw new NotFoundHttpException('No such subscription.');

        $this->editor->update($sub, $request);

        return new JsonResponse(['subscription' => SubscriptionJson::one($sub)]);
```

`moveToTag` body, before:
```php
        $userId = $user->requireId();
        $sub = $this->subscriptionRepo->findOneOwnedBy($id, $userId)
            ?? throw new NotFoundHttpException('No such subscription.');

        $this->feedTagMove->move($sub, $request->fromTagId, $request->toTagId, $request->position, $userId);
        $this->em->flush();

        return new JsonResponse(['subscription' => SubscriptionJson::one($sub)]);
```
after:
```php
        $sub = $this->subscriptionRepo->findOneOwnedBy($id, $user->requireId())
            ?? throw new NotFoundHttpException('No such subscription.');

        $this->editor->moveToTag($sub, $request);

        return new JsonResponse(['subscription' => SubscriptionJson::one($sub)]);
```

`reorder` body, before:
```php
        $byId = $this->ownedSubscriptions->resolve($request->subscriptionIds, $user->requireId());

        foreach ($request->subscriptionIds as $index => $subscriptionId) {
            $byId[$subscriptionId]->setPosition($index);
        }
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
```
after:
```php
        $this->editor->reorder($user, $request);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
```

- [ ] **Step 5: Run the tests and check that they pass.**

Run: `php bin/phpunit tests/Service/Subscription tests/Controller/Api/SubscriptionControllerTest.php tests/Controller/Api/MoveFeedToTagTest.php tests/Controller/Api/SubscriptionBulkTest.php`
Expected: `OK`.

- [ ] **Step 6: Run the gates.**

Run: `bin/console cache:clear && composer check && composer md`
Expected: all green. phptramp must not report `$request` or the user id. Both are read where they arrive.

- [ ] **Step 7: Commit.**

```bash
git add src/Service/Subscription/SubscriptionEditor.php tests/Service/Subscription/SubscriptionEditorTest.php src/Controller/Api/SubscriptionController.php
git commit -m "refactor(#1157): SubscriptionEditor owns PATCH, move-to-tag and reorder writes"
```

---

### Task 6: `AccountPreferencesWriter` (MeController, PasskeyOfferController)

**Files:**
- Create: `src/Service/Account/AccountPreferencesWriter.php`
- Create: `tests/Service/Account/AccountPreferencesWriterTest.php`
- Modify: `src/Controller/Api/MeController.php` (imports, constructor, `updateLocale`, `updatePreferences`, `updateMagazineStyle`, `updateDigest`)
- Modify: `src/Controller/Api/PasskeyOfferController.php` (imports, constructor, `answer`)

**Interfaces:**
- Consumes: the existing `DigestEnablement::applyTo(Preferences, UpdateDigestRequest): void` and `PasskeyOffer::markAnswered(User): void`. Neither flushes, and neither changes.
- Produces:
  - `AccountPreferencesWriter::changeLocale(User, UpdateLocaleRequest): void`
  - `AccountPreferencesWriter::changeScrapeFallback(User, UpdatePreferencesRequest): void`
  - `AccountPreferencesWriter::changeMagazineStyle(User, UpdateMagazineStyleRequest): void`
  - `AccountPreferencesWriter::changeDigest(User, UpdateDigestRequest): void`
  - `AccountPreferencesWriter::answerPasskeyOffer(User): void`
  - Each of the five flushes.

- [ ] **Step 1: Write the failing test.**

`tests/Service/Account/AccountPreferencesWriterTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Account;

use App\Dto\Me\UpdateDigestRequest;
use App\Dto\Me\UpdateLocaleRequest;
use App\Dto\Me\UpdateMagazineStyleRequest;
use App\Dto\Me\UpdatePreferencesRequest;
use App\Entity\User;
use App\Enum\SupportedLocale;
use App\Service\Account\AccountPreferencesWriter;
use App\Service\Mail\Digest\DigestCadence;
use App\Service\Mail\Digest\DigestFormat;
use App\Service\Reader\MagazineStyle;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AccountPreferencesWriterTest extends DbTestCase
{
    public function testChangeLocalePersists(): void
    {
        $user = $this->user('prefs-locale@example.com');

        $this->writer()->changeLocale($user, new UpdateLocaleRequest(SupportedLocale::GERMAN));

        self::assertSame(SupportedLocale::GERMAN, $this->reload($user)->getLocale());
    }

    public function testChangeScrapeFallbackPersists(): void
    {
        $user = $this->user('prefs-scrape@example.com');
        $wanted = !$user->getPreferences()->isScrapeFallbackEnabled();

        $this->writer()->changeScrapeFallback($user, new UpdatePreferencesRequest($wanted));

        self::assertSame($wanted, $this->reload($user)->getPreferences()->isScrapeFallbackEnabled());
    }

    public function testChangeMagazineStylePersists(): void
    {
        $user = $this->user('prefs-magazine@example.com');
        $wanted = MagazineStyle::Airy === $user->getPreferences()->getMagazineStyle()
            ? MagazineStyle::Boxed
            : MagazineStyle::Airy;

        $this->writer()->changeMagazineStyle($user, new UpdateMagazineStyleRequest($wanted));

        self::assertSame($wanted, $this->reload($user)->getPreferences()->getMagazineStyle());
    }

    public function testChangeDigestPersistsTheConfiguration(): void
    {
        $user = $this->user('prefs-digest@example.com');

        $this->writer()->changeDigest(
            $user,
            new UpdateDigestRequest(true, DigestCadence::Weekly, 7, 3, DigestFormat::Text),
        );

        $preferences = $this->reload($user)->getPreferences();
        self::assertTrue($preferences->isDigestEnabled());
        self::assertSame(DigestCadence::Weekly, $preferences->getDigestCadence());
        self::assertSame(7, $preferences->getDigestSendHour());
        self::assertSame(3, $preferences->getDigestWeekday());
        self::assertSame(DigestFormat::Text, $preferences->getDigestFormat());
    }

    public function testAnswerPasskeyOfferPersistsTheAnswer(): void
    {
        $user = $this->user('prefs-passkey@example.com');

        $this->writer()->answerPasskeyOffer($user);

        self::assertNotNull($this->reload($user)->getPreferences()->getPasskeyOfferAnsweredAt());
    }

    private function writer(): AccountPreferencesWriter
    {
        $writer = self::getContainer()->get(AccountPreferencesWriter::class);
        self::assertInstanceOf(AccountPreferencesWriter::class, $writer);

        return $writer;
    }

    private function user(string $email): User
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return (new UserFactory($this->em, $hasher))->create($email);
    }

    private function reload(User $user): User
    {
        $id = $user->requireId();
        $this->em->clear();
        $reloaded = $this->em->find(User::class, $id);
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }
}
```

- [ ] **Step 2: Run the test and check that it fails.**

Run: `php bin/phpunit tests/Service/Account/AccountPreferencesWriterTest.php`
Expected: an error: `Class "App\Service\Account\AccountPreferencesWriter" not found`.

- [ ] **Step 3: Write `AccountPreferencesWriter`.**

`src/Service/Account/AccountPreferencesWriter.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Account;

use App\Dto\Me\UpdateDigestRequest;
use App\Dto\Me\UpdateLocaleRequest;
use App\Dto\Me\UpdateMagazineStyleRequest;
use App\Dto\Me\UpdatePreferencesRequest;
use App\Entity\User;
use App\Service\Mail\Digest\DigestEnablement;
use App\Service\Passkey\PasskeyOffer;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AccountPreferencesWriter
{
    public function __construct(
        private DigestEnablement $digestEnablement,
        private PasskeyOffer $passkeyOffer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function changeLocale(User $user, UpdateLocaleRequest $request): void
    {
        $user->setLocale($request->locale);
        $this->entityManager->flush();
    }

    public function changeScrapeFallback(User $user, UpdatePreferencesRequest $request): void
    {
        $user->getPreferences()->setScrapeFallbackEnabled($request->scrapeFallbackEnabled);
        $this->entityManager->flush();
    }

    public function changeMagazineStyle(User $user, UpdateMagazineStyleRequest $request): void
    {
        $user->getPreferences()->setMagazineStyle($request->magazineStyle);
        $this->entityManager->flush();
    }

    public function changeDigest(User $user, UpdateDigestRequest $request): void
    {
        $this->digestEnablement->applyTo($user->getPreferences(), $request);
        $this->entityManager->flush();
    }

    public function answerPasskeyOffer(User $user): void
    {
        $this->passkeyOffer->markAnswered($user);
        $this->entityManager->flush();
    }
}
```

- [ ] **Step 4: Switch `MeController` over to the writer.**

Imports, before:
```php
use App\Service\Account\AccountDeleter;
use App\Service\Auth\RegistrationService;
use App\Service\Mail\Digest\DigestEnablement;
use App\Service\Mail\Digest\SendTestDigest;
use App\Service\Mail\MailCapability;
use App\Service\RateLimit\MeRateLimiters;
use App\Service\RateLimit\RateLimitGuard;
use Doctrine\ORM\EntityManagerInterface;
```
after:
```php
use App\Service\Account\AccountDeleter;
use App\Service\Account\AccountPreferencesWriter;
use App\Service\Auth\RegistrationService;
use App\Service\Mail\Digest\SendTestDigest;
use App\Service\Mail\MailCapability;
use App\Service\RateLimit\MeRateLimiters;
use App\Service\RateLimit\RateLimitGuard;
```

Constructor head, before:
```php
        private EntityManagerInterface $entityManager,
        private AccountDeleter $accountDeleter,
        private MailCapability $mail,
        private DigestEnablement $digestEnablement,
        private RegistrationService $registration,
```
after:
```php
        private AccountPreferencesWriter $preferences,
        private AccountDeleter $accountDeleter,
        private MailCapability $mail,
        private RegistrationService $registration,
```

`updateLocale` body, before:
```php
        $user->setLocale($request->locale);
        $this->entityManager->flush();
```
after:
```php
        $this->preferences->changeLocale($user, $request);
```

`updatePreferences` body, before:
```php
        $user->getPreferences()->setScrapeFallbackEnabled($request->scrapeFallbackEnabled);
        $this->entityManager->flush();
```
after:
```php
        $this->preferences->changeScrapeFallback($user, $request);
```

`updateMagazineStyle` body, before:
```php
        $user->getPreferences()->setMagazineStyle($request->magazineStyle);
        $this->entityManager->flush();
```
after:
```php
        $this->preferences->changeMagazineStyle($user, $request);
```

`updateDigest` body, before:
```php
        $this->digestEnablement->applyTo($user->getPreferences(), $request);
        $this->entityManager->flush();
```
after:
```php
        $this->preferences->changeDigest($user, $request);
```

- [ ] **Step 5: Switch `PasskeyOfferController` over to the writer.**

Imports, before:
```php
use App\Entity\User;
use App\Service\Passkey\PasskeyOffer;
use Doctrine\ORM\EntityManagerInterface;
```
after:
```php
use App\Entity\User;
use App\Service\Account\AccountPreferencesWriter;
```

Constructor and action, before:
```php
    public function __construct(
        private EntityManagerInterface $entityManager,
        private PasskeyOffer $passkeyOffer,
    ) {
    }

    #[Route('/api/me/passkey-offer/answer', name: 'api_me_passkey_offer_answer', methods: ['POST'])]
    public function answer(#[CurrentUser] User $user): JsonResponse
    {
        $this->passkeyOffer->markAnswered($user);
        $this->entityManager->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
```
after:
```php
    public function __construct(
        private AccountPreferencesWriter $preferences,
    ) {
    }

    #[Route('/api/me/passkey-offer/answer', name: 'api_me_passkey_offer_answer', methods: ['POST'])]
    public function answer(#[CurrentUser] User $user): JsonResponse
    {
        $this->preferences->answerPasskeyOffer($user);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
```

Leave the `PasskeyOfferController` class docblock ("that controller's constructor already carries eight dependencies") as it is. `MeController`'s constructor has nine parameters on develop and eight after this task, so the docblock's reasoning still holds.

- [ ] **Step 6: Run the tests and check that they pass.**

Run: `php bin/phpunit tests/Service/Account/AccountPreferencesWriterTest.php tests/Controller/Api/MeTest.php tests/Controller/Api/MeControllerTest.php tests/Controller/Api/MeDigestControllerTest.php tests/Controller/Api/PasskeyOfferControllerTest.php tests/Service/Passkey/PasskeyOfferTest.php tests/Service/Mail/Digest/DigestEnablementTest.php`
Expected: `OK`.

- [ ] **Step 7: Run the gates.**

Run: `bin/console cache:clear && composer check && composer md`
Expected: all green.

- [ ] **Step 8: Commit.**

```bash
git add src/Service/Account/AccountPreferencesWriter.php tests/Service/Account/AccountPreferencesWriterTest.php src/Controller/Api/MeController.php src/Controller/Api/PasskeyOfferController.php
git commit -m "refactor(#1157): AccountPreferencesWriter owns the /api/me settings writes and their flush"
```

---

### Task 7: `CatalogCategoryEditor`

**Files:**
- Create: `src/Service/Catalog/CatalogCategoryEditor.php`
- Create: `tests/Service/Catalog/CatalogCategoryEditorTest.php`
- Modify: `src/Controller/Admin/AdminCatalogCategoryController.php` (imports, constructor, all four actions)

**Interfaces:**
- Consumes: `PositionReorderer` (Task 1), and `CatalogCategoryRepository::getById(int): CatalogCategory`, which throws `App\Repository\Exception\RecordNotFoundException`.
- Produces:
  - `CatalogCategoryEditor::create(CatalogCategoryRequest): CatalogCategory`
  - `CatalogCategoryEditor::update(CatalogCategory, CatalogCategoryRequest): void`
  - `CatalogCategoryEditor::delete(CatalogCategory): void`
  - `CatalogCategoryEditor::reorder(ReorderRequest): void`

- [ ] **Step 1: Write the failing test.**

`tests/Service/Catalog/CatalogCategoryEditorTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Dto\Admin\CatalogCategoryRequest;
use App\Dto\Admin\ReorderRequest;
use App\Entity\CatalogCategory;
use App\Repository\Exception\RecordNotFoundException;
use App\Service\Catalog\CatalogCategoryEditor;
use App\Tests\DbTestCase;

final class CatalogCategoryEditorTest extends DbTestCase
{
    public function testCreateAppendsACategoryWithTheRequestedFields(): void
    {
        $first = $this->editor()->create(new CatalogCategoryRequest('editor_first', 'First', 'star', '#112233'));

        $second = $this->editor()->create(
            new CatalogCategoryRequest('editor_second', 'Second', 'bolt', '#445566', false, false),
        );

        $reloaded = $this->reload($second);
        self::assertSame('editor_second', $reloaded->getKey());
        self::assertSame('Second', $reloaded->getName());
        self::assertSame('bolt', $reloaded->getIcon());
        self::assertSame('#445566', $reloaded->getColor());
        self::assertFalse($reloaded->isEnabled());
        self::assertFalse($reloaded->isLocked());
        self::assertSame($first->getPosition() + 1, $reloaded->getPosition());
    }

    public function testUpdateRewritesEveryEditableFieldButTheKey(): void
    {
        $category = $this->editor()->create(new CatalogCategoryRequest('editor_update', 'Before', 'star', '#000000'));

        $this->editor()->update(
            $category,
            new CatalogCategoryRequest('ignored_key', 'After', 'bolt', '#ffffff', false, false),
        );

        $reloaded = $this->reload($category);
        self::assertSame('editor_update', $reloaded->getKey());
        self::assertSame('After', $reloaded->getName());
        self::assertSame('bolt', $reloaded->getIcon());
        self::assertSame('#ffffff', $reloaded->getColor());
        self::assertFalse($reloaded->isEnabled());
        self::assertFalse($reloaded->isLocked());
    }

    public function testDeleteRemovesTheCategory(): void
    {
        $category = $this->editor()->create(new CatalogCategoryRequest('editor_delete', 'Doomed', 'star'));
        $id = $category->requireId();

        $this->editor()->delete($category);

        $this->em->clear();
        self::assertNull($this->em->find(CatalogCategory::class, $id));
    }

    public function testReorderGivesEachCategoryItsIndex(): void
    {
        $first = $this->editor()->create(new CatalogCategoryRequest('editor_order_a', 'A', 'star'));
        $second = $this->editor()->create(new CatalogCategoryRequest('editor_order_b', 'B', 'star'));

        $this->editor()->reorder(new ReorderRequest([$second->requireId(), $first->requireId()]));

        self::assertSame(1, $this->reload($first)->getPosition());
        self::assertSame(0, $this->reload($second)->getPosition());
    }

    public function testReorderRefusesAnUnknownCategory(): void
    {
        $this->expectException(RecordNotFoundException::class);
        $this->editor()->reorder(new ReorderRequest([999999]));
    }

    private function editor(): CatalogCategoryEditor
    {
        $editor = self::getContainer()->get(CatalogCategoryEditor::class);
        self::assertInstanceOf(CatalogCategoryEditor::class, $editor);

        return $editor;
    }

    private function reload(CatalogCategory $category): CatalogCategory
    {
        $id = $category->requireId();
        $this->em->clear();
        $reloaded = $this->em->find(CatalogCategory::class, $id);
        self::assertInstanceOf(CatalogCategory::class, $reloaded);

        return $reloaded;
    }
}
```

- [ ] **Step 2: Run the test and check that it fails.**

Run: `php bin/phpunit tests/Service/Catalog/CatalogCategoryEditorTest.php`
Expected: an error: `Class "App\Service\Catalog\CatalogCategoryEditor" not found`.

- [ ] **Step 3: Write `CatalogCategoryEditor`.**

`src/Service/Catalog/CatalogCategoryEditor.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Dto\Admin\CatalogCategoryRequest;
use App\Dto\Admin\ReorderRequest;
use App\Entity\CatalogCategory;
use App\Repository\CatalogCategoryRepository;
use App\Service\Ordering\PositionReorderer;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CatalogCategoryEditor
{
    public function __construct(
        private CatalogCategoryRepository $categories,
        private PositionReorderer $reorderer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function create(CatalogCategoryRequest $request): CatalogCategory
    {
        $category = new CatalogCategory($request->key, $request->name, $request->icon, $request->color);
        $category->setEnabled($request->enabled);
        $category->setLocked($request->locked);
        $category->setPosition($this->categories->nextPosition());
        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }

    public function update(CatalogCategory $category, CatalogCategoryRequest $request): void
    {
        $category->setName($request->name);
        $category->setIcon($request->icon);
        $category->setColor($request->color);
        $category->setEnabled($request->enabled);
        $category->setLocked($request->locked);
        $this->entityManager->flush();
    }

    public function delete(CatalogCategory $category): void
    {
        // Its feeds go with it through the foreign key's ON DELETE CASCADE.
        $this->entityManager->remove($category);
        $this->entityManager->flush();
    }

    public function reorder(ReorderRequest $request): void
    {
        $byId = [];
        foreach ($request->ids as $id) {
            $byId[$id] = $this->categories->getById($id);
        }
        $this->reorderer->reorder($request->ids, $byId);
    }
}
```

- [ ] **Step 4: Switch `AdminCatalogCategoryController` over to the editor.**

Imports, before:
```php
use App\Dto\Admin\CatalogCategoryRequest;
use App\Dto\Admin\ReorderRequest;
use App\Entity\CatalogCategory;
use App\Http\AdminCatalogJson;
use App\Repository\CatalogCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
```
after:
```php
use App\Dto\Admin\CatalogCategoryRequest;
use App\Dto\Admin\ReorderRequest;
use App\Http\AdminCatalogJson;
use App\Repository\CatalogCategoryRepository;
use App\Service\Catalog\CatalogCategoryEditor;
```

Constructor, before:
```php
    public function __construct(
        private CatalogCategoryRepository $categories,
        private EntityManagerInterface $em,
    ) {
    }
```
after:
```php
    public function __construct(
        private CatalogCategoryRepository $categories,
        private CatalogCategoryEditor $editor,
    ) {
    }
```

`create`, before:
```php
    public function create(#[MapRequestPayload] CatalogCategoryRequest $request): JsonResponse
    {
        $category = new CatalogCategory($request->key, $request->name, $request->icon, $request->color);
        $category->setEnabled($request->enabled);
        $category->setLocked($request->locked);
        $category->setPosition($this->categories->nextPosition());
        $this->em->persist($category);
        $this->em->flush();

        return new JsonResponse(
            ['category' => AdminCatalogJson::category($category)],
            Response::HTTP_CREATED,
        );
    }
```
after:
```php
    public function create(#[MapRequestPayload] CatalogCategoryRequest $request): JsonResponse
    {
        return new JsonResponse(
            ['category' => AdminCatalogJson::category($this->editor->create($request))],
            Response::HTTP_CREATED,
        );
    }
```

`reorder`, before:
```php
    public function reorder(#[MapRequestPayload] ReorderRequest $request): JsonResponse
    {
        foreach ($request->ids as $index => $id) {
            $this->categories->getById($id)->setPosition($index);
        }
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
```
after:
```php
    public function reorder(#[MapRequestPayload] ReorderRequest $request): JsonResponse
    {
        $this->editor->reorder($request);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
```

`update`, before:
```php
        $category = $this->categories->getById($id);
        $category->setName($request->name);
        $category->setIcon($request->icon);
        $category->setColor($request->color);
        $category->setEnabled($request->enabled);
        $category->setLocked($request->locked);
        $this->em->flush();

        return new JsonResponse(['category' => AdminCatalogJson::category($category)]);
```
after:
```php
        $category = $this->categories->getById($id);
        $this->editor->update($category, $request);

        return new JsonResponse(['category' => AdminCatalogJson::category($category)]);
```

`delete`, before:
```php
        $category = $this->categories->getById($id);
        // Its feeds go with it via the FK's ON DELETE CASCADE. Subscriptions a
        // user already made are untouched: they are Feed rows, not catalog rows.
        $this->em->remove($category);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
```
after:
```php
        $this->editor->delete($this->categories->getById($id));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
```

- [ ] **Step 5: Run the tests and check that they pass.**

Run: `php bin/phpunit tests/Service/Catalog/CatalogCategoryEditorTest.php tests/Controller/Admin/AdminCatalogControllerTest.php`
Expected: `OK`.

- [ ] **Step 6: Run the gates.**

Run: `bin/console cache:clear && composer check && composer md`
Expected: all green.

- [ ] **Step 7: Commit.**

```bash
git add src/Service/Catalog/CatalogCategoryEditor.php tests/Service/Catalog/CatalogCategoryEditorTest.php src/Controller/Admin/AdminCatalogCategoryController.php
git commit -m "refactor(#1157): CatalogCategoryEditor owns catalog category writes"
```

---

### Task 8: `CatalogFeedEditor` (absorbs `CatalogFeedWriter`)

**Files:**
- Create: `src/Service/Catalog/CatalogFeedEditor.php`
- Create: `tests/Service/Catalog/CatalogFeedEditorTest.php`
- Delete: `src/Service/Catalog/CatalogFeedWriter.php` (its only caller is this controller; R5)
- Modify: `src/Controller/Admin/AdminCatalogFeedController.php` (imports, constructor, `create`, `reorder`, `update`, `delete`)

**Interfaces:**
- Consumes: `PositionReorderer` (Task 1), `CatalogCategoryRepository::getById(int)`, `CatalogFeedRepository::getById(int)` and `CatalogFeedRepository::nextPositionInCategory(int): int`.
- Produces:
  - `CatalogFeedEditor::create(CatalogFeedRequest): CatalogFeed`
  - `CatalogFeedEditor::update(CatalogFeed, CatalogFeedRequest): void`
  - `CatalogFeedEditor::delete(CatalogFeed): void`
  - `CatalogFeedEditor::reorder(ReorderRequest): void`

- [ ] **Step 1: Write the failing test.**

`tests/Service/Catalog/CatalogFeedEditorTest.php`:
```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Dto\Admin\CatalogFeedRequest;
use App\Dto\Admin\ReorderRequest;
use App\Entity\CatalogCategory;
use App\Entity\CatalogFeed;
use App\Enum\SourceFormat;
use App\Repository\Exception\RecordNotFoundException;
use App\Service\Catalog\CatalogFeedEditor;
use App\Tests\DbTestCase;

final class CatalogFeedEditorTest extends DbTestCase
{
    public function testCreateAppendsAFeedToItsCategoryWithEveryField(): void
    {
        $category = $this->category('feed_editor_create');
        $first = $this->editor()->create($this->request($category, 'https://first.feed-editor.example.com/rss'));

        $second = $this->editor()->create(new CatalogFeedRequest(
            $category->requireId(),
            'Second',
            'https://second.feed-editor.example.com/rss',
            'https://second.feed-editor.example.com',
            'About the second',
            SourceFormat::SCRAPED,
            false,
            false,
        ));

        $reloaded = $this->reload($second);
        self::assertSame($category->requireId(), $reloaded->getCategory()->requireId());
        self::assertSame('Second', $reloaded->getTitle());
        self::assertSame('https://second.feed-editor.example.com/rss', $reloaded->getUrl());
        self::assertSame('https://second.feed-editor.example.com', $reloaded->getSiteUrl());
        self::assertSame('About the second', $reloaded->getDescription());
        self::assertSame(SourceFormat::SCRAPED, $reloaded->getSourceFormat());
        self::assertFalse($reloaded->isEnabled());
        self::assertFalse($reloaded->isLocked());
        self::assertSame($first->getPosition() + 1, $reloaded->getPosition());
    }

    public function testUpdateMovesTheFeedAndRewritesItsFields(): void
    {
        $from = $this->category('feed_editor_from');
        $to = $this->category('feed_editor_to');
        $feed = $this->editor()->create($this->request($from, 'https://before.feed-editor.example.com/rss'));

        $this->editor()->update($feed, new CatalogFeedRequest(
            $to->requireId(),
            'After',
            'https://after.feed-editor.example.com/rss',
            null,
            null,
            SourceFormat::XML,
            false,
            false,
        ));

        $reloaded = $this->reload($feed);
        self::assertSame($to->requireId(), $reloaded->getCategory()->requireId());
        self::assertSame('After', $reloaded->getTitle());
        self::assertSame('https://after.feed-editor.example.com/rss', $reloaded->getUrl());
        self::assertFalse($reloaded->isEnabled());
        self::assertFalse($reloaded->isLocked());
    }

    public function testUpdateRefusesAnUnknownCategory(): void
    {
        $feed = $this->editor()->create(
            $this->request($this->category('feed_editor_orphan'), 'https://orphan.feed-editor.example.com/rss'),
        );

        $this->expectException(RecordNotFoundException::class);
        $this->editor()->update($feed, new CatalogFeedRequest(999999, 'T', 'https://x.feed-editor.example.com/rss'));
    }

    public function testDeleteRemovesTheFeed(): void
    {
        $feed = $this->editor()->create(
            $this->request($this->category('feed_editor_delete'), 'https://doomed.feed-editor.example.com/rss'),
        );
        $id = $feed->requireId();

        $this->editor()->delete($feed);

        $this->em->clear();
        self::assertNull($this->em->find(CatalogFeed::class, $id));
    }

    public function testReorderGivesEachFeedItsIndex(): void
    {
        $category = $this->category('feed_editor_order');
        $first = $this->editor()->create($this->request($category, 'https://a.feed-editor.example.com/rss'));
        $second = $this->editor()->create($this->request($category, 'https://b.feed-editor.example.com/rss'));

        $this->editor()->reorder(new ReorderRequest([$second->requireId(), $first->requireId()]));

        self::assertSame(1, $this->reload($first)->getPosition());
        self::assertSame(0, $this->reload($second)->getPosition());
    }

    private function editor(): CatalogFeedEditor
    {
        $editor = self::getContainer()->get(CatalogFeedEditor::class);
        self::assertInstanceOf(CatalogFeedEditor::class, $editor);

        return $editor;
    }

    private function category(string $key): CatalogCategory
    {
        $category = new CatalogCategory($key, $key, 'star', '#000000');
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function request(CatalogCategory $category, string $url): CatalogFeedRequest
    {
        return new CatalogFeedRequest($category->requireId(), 'Title', $url);
    }

    private function reload(CatalogFeed $feed): CatalogFeed
    {
        $id = $feed->requireId();
        $this->em->clear();
        $reloaded = $this->em->find(CatalogFeed::class, $id);
        self::assertInstanceOf(CatalogFeed::class, $reloaded);

        return $reloaded;
    }
}
```

- [ ] **Step 2: Run the test and check that it fails.**

Run: `php bin/phpunit tests/Service/Catalog/CatalogFeedEditorTest.php`
Expected: an error: `Class "App\Service\Catalog\CatalogFeedEditor" not found`.

- [ ] **Step 3: Write `CatalogFeedEditor` and delete the writer.**

`src/Service/Catalog/CatalogFeedEditor.php`:
```php
<?php

declare(strict_types=1);

namespace App\Service\Catalog;

use App\Dto\Admin\CatalogFeedRequest;
use App\Dto\Admin\ReorderRequest;
use App\Entity\CatalogFeed;
use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
use App\Service\Ordering\PositionReorderer;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CatalogFeedEditor
{
    public function __construct(
        private CatalogFeedRepository $feeds,
        private CatalogCategoryRepository $categories,
        private PositionReorderer $reorderer,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function create(CatalogFeedRequest $request): CatalogFeed
    {
        $category = $this->categories->getById($request->categoryId);
        $feed = new CatalogFeed($category, $request->title, $request->url);
        $this->applyEditableFields($feed, $request);
        $feed->setPosition($this->feeds->nextPositionInCategory($category->requireId()));
        $this->entityManager->persist($feed);
        $this->entityManager->flush();

        return $feed;
    }

    public function update(CatalogFeed $feed, CatalogFeedRequest $request): void
    {
        $feed->setCategory($this->categories->getById($request->categoryId));
        $feed->setTitle($request->title);
        $feed->setUrl($request->url);
        $this->applyEditableFields($feed, $request);
        $this->entityManager->flush();
    }

    public function delete(CatalogFeed $feed): void
    {
        $this->entityManager->remove($feed);
        $this->entityManager->flush();
    }

    public function reorder(ReorderRequest $request): void
    {
        $byId = [];
        foreach ($request->ids as $id) {
            $byId[$id] = $this->feeds->getById($id);
        }
        $this->reorderer->reorder($request->ids, $byId);
    }

    private function applyEditableFields(CatalogFeed $feed, CatalogFeedRequest $request): void
    {
        $feed->setSiteUrl($request->siteUrl);
        $feed->setDescription($request->description);
        $feed->setSourceFormat($request->sourceFormat);
        $feed->setEnabled($request->enabled);
        $feed->setLocked($request->locked);
    }
}
```

Run: `git rm src/Service/Catalog/CatalogFeedWriter.php`

- [ ] **Step 4: Switch `AdminCatalogFeedController` over to the editor.**

Imports, before:
```php
use App\Dto\Admin\CatalogFeedRequest;
use App\Dto\Admin\ReorderRequest;
use App\Entity\CatalogFeed;
use App\Http\AdminCatalogJson;
use App\Repository\CatalogCategoryRepository;
use App\Repository\CatalogFeedRepository;
use App\Service\Catalog\CatalogFaviconWarmer;
use App\Service\Catalog\CatalogFeedWriter;
use Doctrine\ORM\EntityManagerInterface;
```
after:
```php
use App\Dto\Admin\CatalogFeedRequest;
use App\Dto\Admin\ReorderRequest;
use App\Http\AdminCatalogJson;
use App\Repository\CatalogFeedRepository;
use App\Service\Catalog\CatalogFaviconWarmer;
use App\Service\Catalog\CatalogFeedEditor;
```

Constructor, before:
```php
    public function __construct(
        private CatalogFeedRepository $feeds,
        private CatalogCategoryRepository $categories,
        private CatalogFaviconWarmer $warmer,
        private CatalogFeedWriter $feedWriter,
        private EntityManagerInterface $em,
    ) {
    }
```
after:
```php
    public function __construct(
        private CatalogFeedRepository $feeds,
        private CatalogFaviconWarmer $warmer,
        private CatalogFeedEditor $editor,
    ) {
    }
```

`create`, before:
```php
    public function create(#[MapRequestPayload] CatalogFeedRequest $request): JsonResponse
    {
        $category = $this->categories->getById($request->categoryId);

        $feed = new CatalogFeed($category, $request->title, $request->url);
        $this->feedWriter->apply($feed, $request);
        $feed->setPosition($this->feeds->nextPositionInCategory($category->requireId()));
        $this->em->persist($feed);
        $this->em->flush();

        return new JsonResponse(['feed' => AdminCatalogJson::feed($feed)], Response::HTTP_CREATED);
    }
```
after:
```php
    public function create(#[MapRequestPayload] CatalogFeedRequest $request): JsonResponse
    {
        return new JsonResponse(
            ['feed' => AdminCatalogJson::feed($this->editor->create($request))],
            Response::HTTP_CREATED,
        );
    }
```

`reorder`, before:
```php
    public function reorder(#[MapRequestPayload] ReorderRequest $request): JsonResponse
    {
        foreach ($request->ids as $index => $id) {
            $this->feeds->getById($id)->setPosition($index);
        }
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
```
after:
```php
    public function reorder(#[MapRequestPayload] ReorderRequest $request): JsonResponse
    {
        $this->editor->reorder($request);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
```

`update`, before:
```php
        $feed = $this->feeds->getById($id);
        $category = $this->categories->getById($request->categoryId);

        $feed->setCategory($category);
        $feed->setTitle($request->title);
        $feed->setUrl($request->url);
        $this->feedWriter->apply($feed, $request);
        $this->em->flush();

        return new JsonResponse(['feed' => AdminCatalogJson::feed($feed)]);
```
after:
```php
        $feed = $this->feeds->getById($id);
        $this->editor->update($feed, $request);

        return new JsonResponse(['feed' => AdminCatalogJson::feed($feed)]);
```

`delete`, before:
```php
        $feed = $this->feeds->getById($id);
        $this->em->remove($feed);
        $this->em->flush();

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
```
after:
```php
        $this->editor->delete($this->feeds->getById($id));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
```

The lookup order is unchanged: the feed is looked up before the category, and neither lookup mutates anything. An unknown feed id and an unknown category id therefore still answer the same `RecordNotFoundException` 404s.

- [ ] **Step 5: Run the tests and check that they pass.**

Run: `php bin/phpunit tests/Service/Catalog tests/Controller/Admin/AdminCatalogControllerTest.php`
Expected: `OK`. `git grep -n CatalogFeedWriter -- src tests` prints nothing.

- [ ] **Step 6: Run the gates.**

Run: `bin/console cache:clear && composer check && composer md`
Expected: all green.

- [ ] **Step 7: Commit.**

```bash
git add src/Service/Catalog/CatalogFeedEditor.php tests/Service/Catalog/CatalogFeedEditorTest.php src/Controller/Admin/AdminCatalogFeedController.php
git add -u src/Service/Catalog/CatalogFeedWriter.php
git commit -m "refactor(#1157): CatalogFeedEditor owns catalog feed writes and absorbs CatalogFeedWriter"
```

---

### Task 9: `ThinControllerRule` rejects persistence parameters

**Files:**
- Modify: `tests/PhpStan/ThinControllerRule.php` (whole file)
- Modify: `tests/PhpStan/ThinControllerRuleTest.php` (whole file)
- Modify: `tests/PhpStan/data/thin-controller-fixtures.php` (append after line 54)

**Interfaces:**
- Produces: identifier `simpleFeedReader.thinController.persistence` on every controller-method parameter whose type is an `Doctrine\Persistence\ObjectManager` or a `Doctrine\Persistence\ManagerRegistry` (nullable or not). The existing `simpleFeedReader.thinController` error, its message and the allow-list behaviour are unchanged.

- [ ] **Step 1: Extend the fixture.** Append the following to `tests/PhpStan/data/thin-controller-fixtures.php` after its closing `}` on line 54. The expected line numbers in Step 2 depend on exactly this text: `__construct` lands on line 64, `action` on 68, `clean` on 73 and `PersistingService::__construct` on 87.

```php

/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Controller\Fixtures\Persistence {
    use Doctrine\ORM\EntityManagerInterface;
    use Doctrine\Persistence\ManagerRegistry;

    final readonly class PersistingController
    {
        public function __construct(private EntityManagerInterface $entityManager)
        {
        }

        public function action(?ManagerRegistry $registry): int
        {
            return null === $registry ? 0 : 1;
        }

        public function clean(int $id): int
        {
            return $id;
        }
    }
}

/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixtures\Persistence {
    use Doctrine\ORM\EntityManagerInterface;

    final readonly class PersistingService
    {
        public function __construct(private EntityManagerInterface $entityManager)
        {
        }
    }
}
```

Check the numbering with `cat -n tests/PhpStan/data/thin-controller-fixtures.php | sed -n '62,90p'`.

- [ ] **Step 2: Write the failing test.** Replace `tests/PhpStan/ThinControllerRuleTest.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<ThinControllerRule>
 */
final class ThinControllerRuleTest extends RuleTestCase
{
    private const string FIXTURE_CONTROLLER = 'App\Controller\Fixtures\ViolatingController';
    private const string PERSISTING_CONTROLLER = 'App\Controller\Fixtures\Persistence\PersistingController';

    protected function getRule(): Rule
    {
        // A dedicated allow-list keyed at the fixture's helper, so the test never
        // depends on the seeded production allow-list, which shrinks over #186.
        return new ThinControllerRule([
            self::FIXTURE_CONTROLLER . '::allowedHelper' => 'trivial fixture helper',
        ]);
    }

    public function testItFlagsHiddenHelpersAndPersistenceParametersOnControllersOnly(): void
    {
        $this->analyse(
            [__DIR__ . '/data/thin-controller-fixtures.php'],
            [
                // A private method that carries responsibility is reported.
                [$this->expectedMessage(self::FIXTURE_CONTROLLER, 'private', 'assembleResponse'), 22],
                // A private *static* method is reported too — a plain "private function"
                // grep would miss it, the rule does not.
                [$this->expectedMessage(self::FIXTURE_CONTROLLER, 'private', 'readParameter'), 27],
                // allowedHelper (line 32) is allow-listed, so it is not reported.
                // NotAController::helper (line 49) is outside App\Controller, so it is ignored.
                [$this->persistenceMessage('__construct', 'entityManager'), 64],
                [$this->persistenceMessage('action', 'registry'), 68],
                // clean() (line 73) takes no persistence; PersistingService (line 87) is no controller.
            ],
        );
    }

    private function expectedMessage(string $className, string $visibility, string $method): string
    {
        return sprintf(
            'Controller %s has a %s method %s(). An action reads the request, delegates, and returns a '
            . 'response; move querying, response assembly, validation, entity mutation and security '
            . 'decisions into a service, a repository, or an src/Http/*Json.php mapper. See the '
            . '"Controllers hold no private methods that carry responsibility" rule in CLAUDE.md. If this '
            . 'is a trivial single-expression helper used by exactly one action in exactly one controller, '
            . 'add %s to ThinControllerRule::ALLOW_LIST with a comment that says why.',
            $className,
            $visibility,
            $method,
            $className . '::' . $method,
        );
    }

    private function persistenceMessage(string $method, string $parameter): string
    {
        return sprintf(
            'Controller %s receives persistence through %s($%s). An action reads the request, delegates, '
            . 'and returns a response; persisting, flushing and removing entities belong in a service '
            . 'under src/Service (#1157).',
            self::PERSISTING_CONTROLLER,
            $method,
            $parameter,
        );
    }
}
```

- [ ] **Step 3: Run the test and check that it fails.**

Run: `php bin/phpunit tests/PhpStan/ThinControllerRuleTest.php`
Expected: FAIL. The two persistence errors, on lines 64 and 68, are expected but not reported.

- [ ] **Step 4: Widen the rule.** Replace `tests/PhpStan/ThinControllerRule.php` with:

```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * CLAUDE.md's thin-controller rule for method shapes: no private or protected helper outside {@see self::ALLOW_LIST},
 * and no ObjectManager or ManagerRegistry parameter on any controller method (#1157).
 *
 * @implements Rule<InClassMethodNode>
 */
final readonly class ThinControllerRule implements Rule
{
    private const string CONTROLLER_NAMESPACE_PREFIX = 'App\\Controller\\';

    private const array PERSISTENCE_TYPES = [ObjectManager::class, ManagerRegistry::class];

    /**
     * Keyed `Fully\Qualified\Class::method`; only ever shrinks, and every entry carries a comment justifying it.
     *
     * @var array<string, string>
     */
    private const array ALLOW_LIST = [];

    /** @param array<string, string> $allowList overridable only for the rule's own test */
    public function __construct(private array $allowList = self::ALLOW_LIST)
    {
    }

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $className = $node->getClassReflection()->getName();
        if (!str_starts_with($className, self::CONTROLLER_NAMESPACE_PREFIX)) {
            return [];
        }

        $method = $node->getMethodReflection();

        return [
            ...$this->hiddenHelperErrors($className, $method),
            ...self::persistenceParameterErrors($className, $method),
        ];
    }

    /** @return list<IdentifierRuleError> */
    private function hiddenHelperErrors(string $className, ExtendedMethodReflection $method): array
    {
        if ($method->isPublic()) {
            return [];
        }

        $qualifiedName = $className . '::' . $method->getName();
        if (array_key_exists($qualifiedName, $this->allowList)) {
            return [];
        }

        $visibility = $method->isPrivate() ? 'private' : 'protected';

        return [
            RuleErrorBuilder::message(sprintf(
                'Controller %s has a %s method %s(). An action reads the request, delegates, and returns a '
                . 'response; move querying, response assembly, validation, entity mutation and security '
                . 'decisions into a service, a repository, or an src/Http/*Json.php mapper. See the '
                . '"Controllers hold no private methods that carry responsibility" rule in CLAUDE.md. If this '
                . 'is a trivial single-expression helper used by exactly one action in exactly one controller, '
                . 'add %s to ThinControllerRule::ALLOW_LIST with a comment that says why.',
                $className,
                $visibility,
                $method->getName(),
                $qualifiedName,
            ))
                ->identifier('simpleFeedReader.thinController')
                ->build(),
        ];
    }

    /** @return list<IdentifierRuleError> */
    private static function persistenceParameterErrors(string $className, ExtendedMethodReflection $method): array
    {
        $errors = [];
        foreach ($method->getOnlyVariant()->getParameters() as $parameter) {
            if (!self::isPersistence($parameter->getType())) {
                continue;
            }
            $errors[] = RuleErrorBuilder::message(sprintf(
                'Controller %s receives persistence through %s($%s). An action reads the request, delegates, '
                . 'and returns a response; persisting, flushing and removing entities belong in a service '
                . 'under src/Service (#1157).',
                $className,
                $method->getName(),
                $parameter->getName(),
            ))
                ->identifier('simpleFeedReader.thinController.persistence')
                ->build();
        }

        return $errors;
    }

    private static function isPersistence(Type $type): bool
    {
        $nonNullable = TypeCombinator::removeNull($type);
        foreach (self::PERSISTENCE_TYPES as $persistenceClass) {
            if ((new ObjectType($persistenceClass))->isSuperTypeOf($nonNullable)->yes()) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 5: Run the test and check that it passes.**

Run: `php bin/phpunit tests/PhpStan/ThinControllerRuleTest.php`
Expected: `OK`, with no failures.

- [ ] **Step 6: Run the whole-tree gate.** Tasks 2–8 removed every `EntityManagerInterface` from `src/Controller`, so `composer stan` must be green.

Run: `composer stan`
Expected: `[OK] No errors`. If any `simpleFeedReader.thinController.persistence` error is reported, stop and report it. Do not allow-list it.

- [ ] **Step 7: Break test.** Show that the gate bites on real code.
  1. Temporarily add `private \Doctrine\ORM\EntityManagerInterface $entityManager,` as a second promoted parameter of `PasskeyOfferController::__construct`.
  2. Run `bin/console cache:clear && composer stan`. Expected: exactly one `simpleFeedReader.thinController.persistence` error on `PasskeyOfferController`.
  3. Remove the line again with the Edit tool (not `git checkout --`).
  4. Run `composer stan` again. Expected: `[OK] No errors`.

- [ ] **Step 8: Run the gates and commit.**

Run: `composer check`
Expected: all green.

```bash
git add tests/PhpStan/ThinControllerRule.php tests/PhpStan/ThinControllerRuleTest.php tests/PhpStan/data/thin-controller-fixtures.php
git commit -m "refactor(#1157): ThinControllerRule rejects ObjectManager and ManagerRegistry on any controller method"
```

---

### Task 10: `ControllerMutatesNoEntityRule`; CLAUDE.md and architecture §7

**Files:**
- Create: `tests/PhpStan/ControllerMutatesNoEntityRule.php`
- Create: `tests/PhpStan/ControllerMutatesNoEntityRuleTest.php`
- Create: `tests/PhpStan/data/controller-mutates-no-entity-fixtures.php`
- Modify: `phpstan.dist.neon` (the `services:` list)
- Modify: `CLAUDE.md` (two bullets, R7)
- Modify: `docs/architecture.md` (§7, the **Controllers** bullet)

**Interfaces:**
- Produces: identifier `simpleFeedReader.thinController.entity`. It fires on `new App\Entity\*` and on any method or nullsafe-method call on an `App\Entity\*` object whose name is neither `requireId` nor matches `/^(get|is|has)[A-Z]/`, when the code sits inside an `App\Controller\*` class, closures included.

- [ ] **Step 1: Write the fixture.** `tests/PhpStan/data/controller-mutates-no-entity-fixtures.php`, exactly as below. The expected lines are 50, 55, 56, 57 and 64. The `requireId()` call on line 69 must not be reported (R3).

```php
<?php

declare(strict_types=1);

// Fixtures for ControllerMutatesNoEntityRuleTest, analysed only by that RuleTestCase (see excludePaths in
// phpstan.dist.neon). The namespaces deliberately do not match the path, hence the PSR-4 suppressions.
/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Entity\Fixtures {
    class Widget
    {
        private string $label = '';

        public function getLabel(): string
        {
            return $this->label;
        }

        public function isVisible(): bool
        {
            return '' !== $this->label;
        }

        public function requireId(): int
        {
            return 1;
        }

        public function setLabel(string $label): void
        {
            $this->label = $label;
        }

        public function rename(string $label): void
        {
            $this->label = $label;
        }
    }
}

/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Controller\Fixtures\Mutation {
    use App\Entity\Fixtures\Widget;

    final class MutatingController
    {
        public function create(): Widget
        {
            return new Widget();
        }

        public function update(Widget $widget, ?Widget $maybe): string
        {
            $widget->setLabel('new');
            $widget->rename('newer');
            $maybe?->setLabel('nullsafe');

            return $widget->getLabel() . ($widget->isVisible() ? 'shown' : 'hidden');
        }

        public function inClosure(Widget $widget): void
        {
            (static fn (Widget $inner) => $inner->setLabel('closure'))($widget);
        }

        public function readsTheId(Widget $widget): int
        {
            return $widget->requireId();
        }

        public function nonEntity(\ArrayObject $bag): \ArrayObject
        {
            $bag->setFlags(0);

            return new \ArrayObject();
        }
    }
}

/** @noinspection PhpIllegalPsrClassPathInspection */

namespace App\Service\Fixtures\Mutation {
    use App\Entity\Fixtures\Widget;

    final class WidgetService
    {
        public function create(): Widget
        {
            $widget = new Widget();
            $widget->setLabel('services may');

            return $widget;
        }
    }
}
```

- [ ] **Step 2: Write the failing test.** `tests/PhpStan/ControllerMutatesNoEntityRuleTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<ControllerMutatesNoEntityRule>
 */
final class ControllerMutatesNoEntityRuleTest extends RuleTestCase
{
    private const string WIDGET = 'App\Entity\Fixtures\Widget';
    private const string ADVICE = 'An action reads the request, delegates, and returns a response; construct and '
        . 'change entities in a service under src/Service, which also persists them (#1157).';

    protected function getRule(): Rule
    {
        return new ControllerMutatesNoEntityRule();
    }

    public function testItFlagsEntityConstructionAndMutationInControllersButNotQueriesOrRequireId(): void
    {
        $this->analyse(
            [__DIR__ . '/data/controller-mutates-no-entity-fixtures.php'],
            [
                [$this->constructionMessage(), 50],
                [$this->mutationMessage('setLabel'), 55],
                [$this->mutationMessage('rename'), 56],
                [$this->mutationMessage('setLabel'), 57],
                [$this->mutationMessage('setLabel'), 64],
                // getLabel(), isVisible() (line 59) and requireId() (line 69) are queries, so they are not reported.
            ],
        );
    }

    private function constructionMessage(): string
    {
        return sprintf('A controller constructs the entity %s. %s', self::WIDGET, self::ADVICE);
    }

    private function mutationMessage(string $method): string
    {
        return sprintf(
            'A controller calls %s::%s(), which changes an entity. %s',
            self::WIDGET,
            $method,
            self::ADVICE,
        );
    }
}
```

- [ ] **Step 3: Run the test and check that it fails.**

Run: `php bin/phpunit tests/PhpStan/ControllerMutatesNoEntityRuleTest.php`
Expected: an error: `Class "App\Tests\PhpStan\ControllerMutatesNoEntityRule" not found`.

- [ ] **Step 4: Write the rule.** `tests/PhpStan/ControllerMutatesNoEntityRule.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\PhpStan;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * The expression half of the thin-controller rule (#1157): a controller constructs no App\Entity object and calls
 * only get/is/has queries and requireId() on one. Needs per-expression types, so not part of ThinControllerRule.
 *
 * @implements Rule<CallLike>
 */
final readonly class ControllerMutatesNoEntityRule implements Rule
{
    private const string CONTROLLER_NAMESPACE_PREFIX = 'App\\Controller\\';
    private const string ENTITY_NAMESPACE_PREFIX = 'App\\Entity\\';
    private const string QUERY_METHOD = '/^(get|is|has)[A-Z]/';
    private const string ID_READ = 'requireId';
    private const string ADVICE = 'An action reads the request, delegates, and returns a response; construct and '
        . 'change entities in a service under src/Service, which also persists them (#1157).';

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        if (!self::isInController($scope)) {
            return [];
        }

        return match (true) {
            $node instanceof New_ => self::constructionErrors($node, $scope),
            $node instanceof MethodCall, $node instanceof NullsafeMethodCall => self::mutationErrors($node, $scope),
            default => [],
        };
    }

    private static function isInController(Scope $scope): bool
    {
        $classReflection = $scope->getClassReflection();

        return null !== $classReflection
            && str_starts_with($classReflection->getName(), self::CONTROLLER_NAMESPACE_PREFIX);
    }

    /** @return list<IdentifierRuleError> */
    private static function constructionErrors(New_ $node, Scope $scope): array
    {
        if (!$node->class instanceof Name) {
            return [];
        }

        $className = $scope->resolveName($node->class);
        if (!self::isEntity($className)) {
            return [];
        }

        return [self::error(sprintf('A controller constructs the entity %s. %s', $className, self::ADVICE))];
    }

    /** @return list<IdentifierRuleError> */
    private static function mutationErrors(MethodCall|NullsafeMethodCall $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier || self::isQuery($node->name->name)) {
            return [];
        }

        $entities = array_values(array_filter(
            $scope->getType($node->var)->getObjectClassNames(),
            self::isEntity(...),
        ));
        if ([] === $entities) {
            return [];
        }

        return [self::error(sprintf(
            'A controller calls %s::%s(), which changes an entity. %s',
            $entities[0],
            $node->name->name,
            self::ADVICE,
        ))];
    }

    private static function isQuery(string $methodName): bool
    {
        return self::ID_READ === $methodName || 1 === preg_match(self::QUERY_METHOD, $methodName);
    }

    private static function isEntity(string $className): bool
    {
        return str_starts_with($className, self::ENTITY_NAMESPACE_PREFIX);
    }

    private static function error(string $message): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('simpleFeedReader.thinController.entity')
            ->build();
    }
}
```

- [ ] **Step 5: Run the test and check that it passes.**

Run: `php bin/phpunit tests/PhpStan/ControllerMutatesNoEntityRuleTest.php`
Expected: `OK`, with no failures.

- [ ] **Step 6: Register the rule and run the whole-tree gate.**

`phpstan.dist.neon`, before (the last entry of `services:`, lines 39-42):
```yaml
    -
        class: App\Tests\PhpStan\QueriesLiveInRepositoriesRule
        tags:
            - phpstan.rules.rule
```
after:
```yaml
    -
        class: App\Tests\PhpStan\QueriesLiveInRepositoriesRule
        tags:
            - phpstan.rules.rule
    -
        class: App\Tests\PhpStan\ControllerMutatesNoEntityRule
        tags:
            - phpstan.rules.rule
```

Run: `composer stan`
Expected: `[OK] No errors`. The 47 `requireId()` calls in `src/Controller` must not be reported. If any `simpleFeedReader.thinController.entity` error is reported, the inventory missed a site. Stop and report it; do not suppress it.

**Break test:**
1. Temporarily add `$user->setLocale('en');` as the first line of `MeController::show()`.
2. Run `composer stan`. Expected: exactly one error, on that line:
   `A controller calls App\Entity\User::setLocale(), which changes an entity. An action reads the request, delegates, and returns a response; construct and change entities in a service under src/Service, which also persists them (#1157).`
3. Remove the line with the Edit tool (not `git checkout --`).
4. Run `composer stan` again. Expected: `[OK] No errors`.

- [ ] **Step 7: Document the widened rule in CLAUDE.md and in `docs/architecture.md` §7 (R7).**

`CLAUDE.md`, in the bullet **"Controllers hold no private methods that carry responsibility."**, before:
```markdown
  service, a repository, or an `src/Http/*Json.php` mapper — never in a private
  method on the controller. Enforced by `ThinControllerRule` (PHPStan). The one
```
after:
```markdown
  service, a repository, or an `src/Http/*Json.php` mapper — never in a private
  method on the controller, and never inline in a public action either: a
  controller takes no `EntityManagerInterface`/`ManagerRegistry`, constructs no
  entity, and calls only `get*`/`is*`/`has*` and `requireId()` on one (#1157).
  Enforced by `ThinControllerRule` and `ControllerMutatesNoEntityRule`
  (PHPStan). The one
```

`CLAUDE.md`, in the "Enforced mechanically" list, before:
```markdown
- **`ThinControllerRule`** (`tests/PhpStan/ThinControllerRule.php`, run by
  `composer stan`) — controllers carry no private method that does real work; the
  allow-list of permitted trivial helpers lives in the rule and only ever shrinks.
```
after:
```markdown
- **`ThinControllerRule`** (`tests/PhpStan/ThinControllerRule.php`, run by
  `composer stan`) — controllers carry no private method that does real work and
  take no `ObjectManager`/`ManagerRegistry`; the allow-list of permitted trivial
  helpers lives in the rule and only ever shrinks. Its sibling
  **`ControllerMutatesNoEntityRule`** rejects entity construction and any call on
  an entity other than `get*`/`is*`/`has*` and `requireId()` inside a controller.
```

`docs/architecture.md` §7, before:
```markdown
- **Controllers** follow the same rule. Their remaining `persist`/`flush` calls are #1157's to move into services.
```
after:
```markdown
- **Controllers** follow the same rule and go further: they hold no unit of work either. `ThinControllerRule` and
  `ControllerMutatesNoEntityRule` keep `persist`, `flush`, `remove` and entity mutation in services (#1157).
```

- [ ] **Step 8: Run the gates and commit.**

Run: `composer check && composer md`
Expected: all green.

```bash
git add tests/PhpStan/ControllerMutatesNoEntityRule.php tests/PhpStan/ControllerMutatesNoEntityRuleTest.php tests/PhpStan/data/controller-mutates-no-entity-fixtures.php phpstan.dist.neon ../CLAUDE.md ../docs/architecture.md
git commit -m "refactor(#1157): ControllerMutatesNoEntityRule keeps entity construction and mutation out of controllers"
```

---

## Finishing

1. **Run the branch-wide gates, all green:**
   - `composer check`
   - `composer md`
   - `php bin/phpunit` (SQLite)
   - `docker compose exec php composer test` (MySQL). First confirm that the php container runs this checkout's code; see the "check the container is current" rule.
   - `composer infection:diff`. Every new file is untracked until committed, so run it after the last commit.

   Then scan today's dev log: `ls -t var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`. It must show no new deprecations or errors.
2. **Run the SDD final whole-branch review.** It is not optional. Ask the reviewer to attack four things:
   - **Did any response change?** `git diff origin/develop -- tests/Controller` must be empty, because the controller tests are the contract net and none of them was edited.
   - **Did any flush move or get lost?**
     - Each old controller `flush()` needs exactly one matching flush in the new service.
     - `SavedSearchEditor::create` must still flush twice, once before `assignTo()` so the id exists.
     - `PasskeyOffer::markAnswered()` must still not flush, because `AttestationVerifier` depends on that.
   - **Do the rules hold?** `composer stan` is green with an empty `ALLOW_LIST`. Both break tests (Tasks 9 and 10) reported their error.
   - **Did anything cross a neighbouring issue's boundary?**
     - No `createQueryBuilder`, `createQuery` or DBAL call appears in a new service (#1170).
     - No `*Json::` call has moved into a service (#1158).
     - Every new service and test uses `requireId()` (#1165).
3. **Run `/simplify`** over the branch diff, then re-run the gates from step 1 if it changed anything.
4. **Open the PR against `develop`** with this body. Per R1 it says `Refs #1157`, not `Closes`, and no follow-up issue is opened.

   ```markdown
   Refs #1157

   Controllers no longer persist, flush, remove, construct or mutate entities. The work moved into
   `Service/Tag/{TagEditor,TagOrdering}`, `Service/Search/SavedSearchEditor`,
   `Service/Subscription/SubscriptionEditor`, `Service/Account/AccountPreferencesWriter` and
   `Service/Catalog/{CatalogCategoryEditor,CatalogFeedEditor}`. The five "position = index, flush" loops
   became `Service/Ordering/PositionReorderer`. `TagNameTakenException` now lives in `Service/Tag/Exception`.

   PHPStan now enforces this in public actions too: `ThinControllerRule` rejects `ObjectManager` /
   `ManagerRegistry` parameters on any controller method, and the new `ControllerMutatesNoEntityRule`
   rejects `new App\Entity\*` and any entity call other than `get*`/`is*`/`has*` and `requireId()` in a
   controller. The allow-list stays empty.

   CLAUDE.md and `docs/architecture.md` §7 describe the widened rule.

   No wire-contract change: no controller test was edited.

   Inline security decisions, response assembly and shared controller boilerplate are PR B's, which closes #1157.
   ```

5. **Merge when CI is green** (Lars's instruction for this plan).
   - Arm a Monitor that polls `gh pr checks <pr>` until every check has passed. Then run `gh pr merge <pr> --merge`.
   - Never use `gh pr merge --auto`: it merges immediately on this repository.
   - If a check fails, stop and report. If phptramp fails, look at `composer show larspohlmann/phptramp` before you blame the diff.
   - After the merge, confirm that #1157 is still open: PR B closes it. Do not close it by hand.

---

## Appendix B: PR B inventory (not implemented here)

These are the issue items that no PHPStan rule can gate. Each one has a target that respects #1158: presentation goes to `src/Http/*Json`, never into a service.

**1. Security decisions inline**
- `Controller/Api/RefreshController::__invoke:56-74` holds the feed-then-tag ownership chain. Target: `Service/Refresh/RefreshScope`, which resolves a `RefreshRequest` for the user and throws a typed `Service/Refresh/Exception/RefreshTargetNotFoundException`. `RefreshProblems` maps it to the same 404 texts.
- `Controller/Api/MeController::sendTestDigest:132-134` holds the mail/verified gate. Target: a guard in `Service/Mail/Digest/SendTestDigest` that throws a typed exception. `MailProblems` maps it to the same 403.
- `Controller/Api/OAuthController::callback:147-202` holds the state and provider checks (155-175). #1165 already made the state refusal a typed `InvalidOAuthStateException`, which the callback catches at 166-170.

**2. Response assembly inline** (each target is an `src/Http` mapper)
- `VersionController:21-36` → `Http/VersionJson`
- `OnboardingController::subscribe:36-43` → `Http/OnboardingJson`
- `OpmlController::import:43-55`: the size guard goes into `Service/Opml/OpmlBodyReader`, which already exists, and the result goes to `Http/OpmlJson`
- `AdminCatalogImportController::describeBundled:39-54` → `AdminCatalogJson::bundled`
- `AdminUserLimitsController:38-41` and `:50-53` build the same array → `Http/AdminUserLimitsJson::trial`
- `AdminCatalogController::list:41-50` and `warmFavicons:64-70` → `AdminCatalogJson`
- `CatalogController::favicon:48-63`: the choice between bytes and monogram goes to `Service/Catalog`, and the cache headers go to `Http/CatalogFaviconResponse`
- `SubscriptionController::list:52-69` → reuse `SubscriptionCountsJson`
- `SubscriptionController::create:93-105` (candidates payload) → `Http/SubscribeOutcomeJson`

**3. Shared boilerplate**
- A lookup followed by `?? throw new NotFoundHttpException(...)` appears 16 times in 9 controllers, 10 of them after `findOneOwnedBy(...)`. Replace it with an owned-lookup service that throws a typed not-found through a `*Problems` arm, and keep the 404 texts.
- `AiSettingsController` calls `$this->configuration->require($user, $id)` 10 times and `AiSettingsJson::configuration($configuration, $this->configurator->settingsFor($user)?->getId())` 8 times. `ConfigurationNotFoundException` already maps through `AiProblems`.
- `savedSearchLoader->loadInto(categoryLoader->loadInto(...))` appears 5 times.
- `EntryController::list` reads 7 `#[MapQueryParameter]` query parameters. Give it a `#[MapQueryString]` DTO and reuse it in the two sibling actions.
- `MeJson::profile($user, $this->mail->isEnabled(), $this->instanceTimezone)` appears 5 times.

**4. Commands**
- `Command/CheckCatalogUrlsCommand` → `Service/Catalog/CatalogUrlChecker`, which takes its path from `BundledCatalog`. #1165 already typed the failure as `Service/Catalog/Exception/BrokenCatalogUrlException`, thrown by `assertServesFeed()` (lines 108-130), which moves with it.
- `Command/ReaderAuditCommand::execute:71` holds the file I/O and the sharding → `Service/ReaderAudit`.
