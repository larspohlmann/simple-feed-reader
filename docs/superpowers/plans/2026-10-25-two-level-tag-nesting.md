# Two levels of tag nesting: Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tags nest two levels below the top (root › child › grandchild) instead of one, everywhere a tag's place matters: the tag rules, a tag's reading scope and unread count, the backup, the OPML export, the sidebar, the organise page, the tag form and the tag picker.

**Architecture:** A single limit, `Tag::MAX_DEPTH = 2` in PHP and `MAX_TAG_DEPTH = 2` in TypeScript. Nothing hard-codes a tier. The backend checks placement through one per-call `TagTree` (depth, subtree height, "is within"). It expresses a tag's whole subtree in DQL through one `TagLineageDql` helper (a tag joined to its ancestors), which both the scope predicate and the unread roll-up use. The frontend gets one `TagHierarchy` class with the same three questions. The tree derivations, the drag resolver, the sidebar, the organise tree and the parent select all recurse, with no fixed number of levels.

**Tech Stack:** Symfony 7.4 / Doctrine ORM (PHP 8.4), Angular 20 (standalone, signals), PHPUnit 12, Jest.

**Spec:** this document (the decisions below come from the conversation that asked for it; #1736 and #1765 hold the one-level design this extends).

## The rule (the contract)

- **Depth.** A root is at depth 0. A tag may sit at depth ≤ `MAX_DEPTH` (2): root, child, grandchild.
- **Placement.** Tag *T* may be placed under parent *P* (on create, edit, drag, restore) when all of these hold:
  1. *P* is not *T*.
  2. *P* is not anywhere inside *T*'s subtree.
  3. `depth(P) + 1 + height(T) ≤ MAX_DEPTH`, where `height` is the number of levels hanging below *T* (a leaf is 0).
  The top level (no parent) always accepts.
- **A tag with children may move** under another tag when the rule above holds. Its subtree moves with it.
- **Deleting a tag** moves its children (with their subtrees) **up one level**, to the deleted tag's parent, placed after that parent's existing children. Deleting a root moves its children to the top level, as before.
- **Scope.** A tag's entry list, unread count, mark-read and refresh span its own feeds and those of every tag below it, each entry once.
- **Labels.** A nested tag reads with its whole path: `Reading / Long reads / Essays`.
- **OPML export** nests every level. **OPML import is unchanged.** It still maps a file onto two tiers (innermost group as the tag, outermost as its parent). That mapping stays valid under the deeper limit; see "Not in this plan".
- **Backup** carries any valid depth. A restore refuses a file that nests deeper than `MAX_DEPTH`.

## Global Constraints

- Issue #1790. Branch `feature/1790-two-level-tag-nesting` off `develop`, commits `type(#1790): …`, PR body `Closes #1790`. No Claude attribution.
- **No migration.** `tag.parent_id` already exists (nullable self-reference, `ON DELETE SET NULL`).
- The wire stays as it is: `parentId` on tag JSON and requests, the problem type `invalid_tag_parent` with `errors.parentId`.
- PHP: `final readonly` by default, no abbreviations, no flag parameters, queries in `src/Repository/`, errors are typed exceptions, comments only where a reader would otherwise get the code wrong (one line). Every touched `src` file is PHPMD-clean.
- Gates: `composer check` (cs + stan + tramp) and `composer md` from `backend/`. `php bin/phpunit` natively **and** `docker compose exec php composer test` (MySQL). `docker compose exec -T frontend npm run check` and `npm run build` (check misses template errors).
- Frontend tests run in the container: `docker compose exec -T frontend npx jest <path>`. Never run two Jest processes at once (the container OOMs).
- Never nest `cdkDropList`s. Recursive templates keep every drop list a DOM sibling of the others: a list never wraps another list.
- No hex colours, ad-hoc `px` spacing or media-query literals in component `.scss`; use tokens.
- Mutation testing gates changed files (`composer infection:diff`). Every new branch needs a test that would fail without it.

## Not in this plan

- **OPML import of three tiers.** A three-level file still imports as two tiers, so a three-level export does not round-trip through OPML. The backup round-trips losslessly. Making the import keep the middle level means replacing `BulkSubscribeItemModel`'s `tagName` + `parentTagName` with a name path and teaching `BulkSubscriber::nestUnder` the depth rule against unflushed tags. That is a self-contained follow-up of roughly one task's size.

## File structure

| File | Responsibility |
|---|---|
| Modify `backend/src/Entity/Tag.php` | `MAX_DEPTH` constant |
| Create `backend/src/Service/Tag/Pass/TagTree.php` | One user's tags as a tree: `depthOf`, `heightOf`, `isWithin` |
| Create `backend/tests/Service/Tag/Pass/TagTreeTest.php` | Its unit tests |
| Modify `backend/src/Service/Tag/TagEditor.php` | Placement rule through `TagTree`; delete lifts children one level |
| Modify `backend/src/Service/Tag/Exception/InvalidTagParentException.php` | `tooDeep()`, `underItsOwnChild()` replace `parentIsAChild()`, `tagHasChildren()` |
| Modify `backend/tests/Service/Tag/TagEditorTest.php`, `backend/tests/Controller/Api/TagControllerTest.php` | New rule |
| Create `backend/src/Repository/TagLineageDql.php` | A tag and its ancestors as DQL aliases; the "reaches" condition |
| Modify `backend/src/Repository/TagScopeDql.php` | Scope spans the whole subtree |
| Modify `backend/src/Repository/UnreadCountRepository.php` | One roll-up per tag over its whole subtree |
| Modify `backend/tests/Controller/Api/EntryTagScopeTest.php`, `backend/tests/Repository/UnreadCountRepositoryTest.php`, `backend/tests/Repository/FeedRepositoryTagScopeTest.php` | Grandchild cases |
| Modify `backend/src/Service/Backup/AccountBackupExporter.php`, `backend/src/Service/Backup/Pass/BackupTally.php` | Parents-first at any depth; restore depth check |
| Modify `backend/tests/Service/Backup/AccountBackupExporterTest.php`, `backend/tests/Service/Backup/BackupInspectorTest.php` | Grandchild cases |
| Modify `backend/src/Service/Opml/Pass/OpmlTagTree.php` | Remember every ancestor |
| Modify `backend/tests/Service/Opml/OpmlExporterTest.php` | Three-level export |
| Create `frontend/src/app/reader/state/tag-hierarchy.ts` (+ `.spec.ts`) | `MAX_TAG_DEPTH`, `TagHierarchy` |
| Modify `frontend/src/app/reader/state/subscription-derivations.ts` | Recursive tree, flatten, sibling sets, tree order, path label |
| Modify `frontend/src/app/reader/state/subscriptions.store.spec.ts` | Grandchild tree |
| Modify `frontend/src/app/reader/state/tags.store.ts` (+ spec) | Drop `rootTags`, unused once the tag form moves to `TagHierarchy` (Task 8) |
| Modify `frontend/src/app/reader/feeds/tag-picker/tag-search.ts`, `tag-option-list.component.{html,scss}` (+ specs) | Ancestor path and indentation by depth |
| Modify `frontend/src/app/reader/tag-drop.ts` (+ spec) | Depth-aware nest and landing |
| Modify `frontend/src/app/reader/shell/sidebar/sidebar.component.{ts,html,scss,spec.ts}` | Recursive branches |
| Modify `frontend/src/app/settings/organise/organise.store.ts`, `organise-section.component.{html,scss}` (+ specs) | Recursive group tree; depth as a number |
| Modify `frontend/src/app/reader/feeds/manage/tag-form-dialog.component.{ts,html}` (+ spec), `frontend/public/i18n/{en,de}.json` | Parent options by the rule |
| Modify `frontend/src/app/reader/shell/sidebar/sidebar-tag-row-menu.component.{ts,html}`, `sidebar-row-actions.service.ts` (+ specs) | "Create tag here" while there is room below |

---

### Task 1: The backend placement rule

**Files:**
- Modify: `backend/src/Entity/Tag.php`
- Create: `backend/src/Service/Tag/Pass/TagTree.php`
- Create: `backend/tests/Service/Tag/Pass/TagTreeTest.php`
- Modify: `backend/src/Service/Tag/TagEditor.php`
- Modify: `backend/src/Service/Tag/Exception/InvalidTagParentException.php`
- Test: `backend/tests/Service/Tag/TagEditorTest.php`, `backend/tests/Controller/Api/TagControllerTest.php`

**Interfaces:**
- Produces: `Tag::MAX_DEPTH` (`public const int`, value `2`). `App\Service\Tag\Pass\TagTree` with `__construct(list<Tag> $tags)`, `depthOf(int $tagId): int`, `heightOf(int $tagId): int`, `isWithin(int $tagId, int $ancestorId): bool`. `InvalidTagParentException::tooDeep()` and `::underItsOwnChild()`.

- [ ] **Step 1: Write the failing `TagTree` test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Tag\Pass;

use App\Entity\Tag;
use App\Entity\User;
use App\Service\Tag\Pass\TagTree;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;

final class TagTreeTest extends DbTestCase
{
    use SeedsUsers;

    public function testARootSitsAtDepthZeroAndEachLevelBelowAddsOne(): void
    {
        [$reading, $longReads, $essays] = $this->chain($this->user('tag-tree-depth@example.com'));
        $tree = new TagTree([$reading, $longReads, $essays]);

        self::assertSame(0, $tree->depthOf($reading->requireId()));
        self::assertSame(1, $tree->depthOf($longReads->requireId()));
        self::assertSame(2, $tree->depthOf($essays->requireId()));
    }

    public function testHeightCountsTheDeepestBranchBelowATag(): void
    {
        $user = $this->user('tag-tree-height@example.com');
        [$reading, $longReads, $essays] = $this->chain($user);
        $shallow = $this->tag($user, 'shallow', $reading);
        $tree = new TagTree([$reading, $longReads, $essays, $shallow]);

        self::assertSame(2, $tree->heightOf($reading->requireId()));
        self::assertSame(1, $tree->heightOf($longReads->requireId()));
        self::assertSame(0, $tree->heightOf($essays->requireId()));
    }

    public function testATagIsWithinItselfAndEveryAncestorButNotASibling(): void
    {
        $user = $this->user('tag-tree-within@example.com');
        [$reading, $longReads, $essays] = $this->chain($user);
        $sibling = $this->tag($user, 'sibling', $reading);
        $tree = new TagTree([$reading, $longReads, $essays, $sibling]);

        self::assertTrue($tree->isWithin($essays->requireId(), $essays->requireId()));
        self::assertTrue($tree->isWithin($essays->requireId(), $reading->requireId()));
        self::assertFalse($tree->isWithin($essays->requireId(), $sibling->requireId()));
        self::assertFalse($tree->isWithin($reading->requireId(), $essays->requireId()));
    }

    /** @return array{0: Tag, 1: Tag, 2: Tag} */
    private function chain(User $user): array
    {
        $reading = $this->tag($user, 'reading', null);
        $longReads = $this->tag($user, 'long reads', $reading);

        return [$reading, $longReads, $this->tag($user, 'essays', $longReads)];
    }

    private function tag(User $user, string $name, ?Tag $parent): Tag
    {
        $tag = new Tag($user, $name);
        $tag->setParent($parent);
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }
}
```

- [ ] **Step 2: Run it to see it fail**

Run (from `backend/`): `php bin/phpunit tests/Service/Tag/Pass/TagTreeTest.php`
Expected: FAIL, `Class "App\Service\Tag\Pass\TagTree" not found`.

- [ ] **Step 3: Add the constant and `TagTree`**

In `backend/src/Entity/Tag.php`, inside the class, above `use PersistedId;`:

```php
    /** Levels below the top a tag may sit; a root is at 0. */
    public const int MAX_DEPTH = 2;
```

Create `backend/src/Service/Tag/Pass/TagTree.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Tag\Pass;

use App\Entity\Tag;

final readonly class TagTree
{
    /** @var array<int, int|null> parent id by tag id */
    private array $parentIds;

    /** @var array<int, list<int>> child ids by parent id */
    private array $childIds;

    /** @param list<Tag> $tags */
    public function __construct(array $tags)
    {
        $parentIds = [];
        $childIds = [];
        foreach ($tags as $tag) {
            $parentId = $tag->getParent()?->requireId();
            $parentIds[$tag->requireId()] = $parentId;
            if ($parentId !== null) {
                $childIds[$parentId][] = $tag->requireId();
            }
        }
        $this->parentIds = $parentIds;
        $this->childIds = $childIds;
    }

    public function depthOf(int $tagId): int
    {
        $parentId = $this->parentIds[$tagId] ?? null;

        return $parentId === null ? 0 : $this->depthOf($parentId) + 1;
    }

    public function heightOf(int $tagId): int
    {
        $height = 0;
        foreach ($this->childIds[$tagId] ?? [] as $childId) {
            $height = max($height, $this->heightOf($childId) + 1);
        }

        return $height;
    }

    /** Whether $tagId is $ancestorId itself or sits anywhere below it. */
    public function isWithin(int $tagId, int $ancestorId): bool
    {
        if ($tagId === $ancestorId) {
            return true;
        }
        $parentId = $this->parentIds[$tagId] ?? null;

        return $parentId !== null && $this->isWithin($parentId, $ancestorId);
    }
}
```

- [ ] **Step 4: Run the `TagTree` test**

Run: `php bin/phpunit tests/Service/Tag/Pass/TagTreeTest.php`
Expected: PASS.

- [ ] **Step 5: Rewrite the editor tests for the new rule**

In `backend/tests/Service/Tag/TagEditorTest.php`:

- **Delete** `testCreateRefusesAChildAsParent` and `testUpdateRefusesAParentForATagThatHasChildren`. Both encode the old one-level rule.
- **Add** these tests (the class already `use`s `InvalidTagParentException`, `TagDetailsModel`, `TagRepository`, `Tag`):

```php
    public function testCreateNestsATagTwoLevelsDown(): void
    {
        $user = $this->user('tag-grandchild-create@example.com');
        $reading = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $longReads = $this->editor()->create($user, new TagDetailsModel('Long reads', parentId: $reading->requireId()));

        $essays = $this->editor()->create($user, new TagDetailsModel('Essays', parentId: $longReads->requireId()));

        self::assertSame($longReads->requireId(), $this->reload($essays)->getParent()?->getId());
    }

    public function testCreateRefusesAThirdLevelBelowTheTop(): void
    {
        $user = $this->user('tag-too-deep-create@example.com');
        $reading = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $longReads = $this->editor()->create($user, new TagDetailsModel('Long reads', parentId: $reading->requireId()));
        $essays = $this->editor()->create($user, new TagDetailsModel('Essays', parentId: $longReads->requireId()));

        $this->expectException(InvalidTagParentException::class);
        $this->expectExceptionMessage('A tag can sit at most 2 levels below the top.');
        $this->editor()->create($user, new TagDetailsModel('Short essays', parentId: $essays->requireId()));
    }

    public function testUpdateMovesATagWithChildrenUnderARoot(): void
    {
        $user = $this->user('tag-move-subtree@example.com');
        $reading = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $books = $this->editor()->create($user, new TagDetailsModel('Books'));
        $novels = $this->editor()->create($user, new TagDetailsModel('Novels', parentId: $books->requireId()));

        $this->editor()->update($books, new TagDetailsModel('Books', parentId: $reading->requireId()));

        self::assertSame($reading->requireId(), $this->reload($books)->getParent()?->getId());
        self::assertSame($books->requireId(), $this->reload($novels)->getParent()?->getId());
    }

    public function testUpdateRefusesAMoveThatPushesAChildPastTheLimit(): void
    {
        $user = $this->user('tag-move-too-deep@example.com');
        $reading = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $longReads = $this->editor()->create($user, new TagDetailsModel('Long reads', parentId: $reading->requireId()));
        $books = $this->editor()->create($user, new TagDetailsModel('Books'));
        $this->editor()->create($user, new TagDetailsModel('Novels', parentId: $books->requireId()));

        $this->expectException(InvalidTagParentException::class);
        $this->expectExceptionMessage('A tag can sit at most 2 levels below the top.');
        $this->editor()->update($books, new TagDetailsModel('Books', parentId: $longReads->requireId()));
    }

    public function testUpdateRefusesAMoveUnderTheTagsOwnChild(): void
    {
        $user = $this->user('tag-move-under-child@example.com');
        $reading = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $longReads = $this->editor()->create($user, new TagDetailsModel('Long reads', parentId: $reading->requireId()));

        $this->expectException(InvalidTagParentException::class);
        $this->expectExceptionMessage('A tag cannot move under one of its own child tags.');
        $this->editor()->update($reading, new TagDetailsModel('Reading', parentId: $longReads->requireId()));
    }

    public function testDeletingAMiddleTagMovesItsChildrenUpUnderItsParentAfterTheOthers(): void
    {
        $user = $this->user('tag-middle-deleter@example.com');
        $reading = $this->editor()->create($user, new TagDetailsModel('Reading'));
        $longReads = $this->editor()->create($user, new TagDetailsModel('Long reads', parentId: $reading->requireId()));
        $essays = $this->editor()->create($user, new TagDetailsModel('Essays', parentId: $longReads->requireId()));
        $poems = $this->editor()->create($user, new TagDetailsModel('Poems', parentId: $reading->requireId()));

        $this->editor()->delete($longReads);

        $this->entityManager->clear();
        $reloadedEssays = $this->entityManager->find(Tag::class, $essays->requireId());
        $reloadedPoems = $this->entityManager->find(Tag::class, $poems->requireId());
        self::assertInstanceOf(Tag::class, $reloadedEssays);
        self::assertInstanceOf(Tag::class, $reloadedPoems);
        self::assertSame($reading->requireId(), $reloadedEssays->getParent()?->getId());
        self::assertGreaterThan($reloadedPoems->getPosition(), $reloadedEssays->getPosition());
    }
```

The existing tests `testUpdateRefusesTheTagItselfAsParent`, `testCreateRefusesAnUnknownParent`, `testCreateRefusesAnotherUsersTagAsParent` and `testDeletingAParentMovesItsChildrenToRootAfterTheLastRoot` keep their assertions unchanged. A root's parent is `null`, so the last one now passes through the "up one level" path.

In `backend/tests/Controller/Api/TagControllerTest.php`, change `testAChildOfAChildIsAnInvalidParentProblem` so it nests one level deeper and asserts the new message:

```php
    public function testAThirdLevelBelowTheTopIsAnInvalidParentProblem(): void
    {
        $client = self::createClient();
        $headers = $this->authHeader('tag-depth-wire@example.com');
        $parentId = $this->createTag($client, $headers, ['name' => 'Reading']);
        $childId = $this->createTag($client, $headers, ['name' => 'Long reads', 'parentId' => $parentId]);
        $grandchildId = $this->createTag($client, $headers, ['name' => 'Essays', 'parentId' => $childId]);

        $client->request(
            'POST',
            '/api/tags',
            server: $headers,
            content: json_encode(['name' => 'Short essays', 'parentId' => $grandchildId], \JSON_THROW_ON_ERROR),
        );

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        $problem = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($problem);
        self::assertSame('invalid_tag_parent', $problem['type']);
        self::assertSame('Invalid parent tag', $problem['title']);
        self::assertSame('A tag can sit at most 2 levels below the top.', $problem['detail']);
        self::assertSame(['parentId' => ['A tag can sit at most 2 levels below the top.']], $problem['errors']);
    }
```

- [ ] **Step 6: Run the editor and controller tests to see the new ones fail**

Run: `php bin/phpunit tests/Service/Tag/TagEditorTest.php tests/Controller/Api/TagControllerTest.php`
Expected: FAIL. The two-level create is refused with "A parent tag cannot itself have a parent.", the subtree move with "A tag with child tags cannot have a parent.", and the middle-tag delete lands the grandchild at the top level.

- [ ] **Step 7: Rewrite the exception factories**

Replace `parentIsAChild()` and `tagHasChildren()` in `InvalidTagParentException.php` (keep `unknown()` and `itself()`):

```php
    public static function tooDeep(): self
    {
        return new self(sprintf('A tag can sit at most %d levels below the top.', Tag::MAX_DEPTH));
    }

    public static function underItsOwnChild(): self
    {
        return new self('A tag cannot move under one of its own child tags.');
    }
```

Add `use App\Entity\Tag;` to the file.

- [ ] **Step 8: Rewrite the rule in `TagEditor`**

Replace `create`'s parent line, `delete`'s first line and the private methods `releaseChildren`, `parentFor`, `resolveParent`. `moveUnder` stays as it is. Add `use App\Service\Tag\Pass\TagTree;`.

```php
    public function create(User $user, TagDetailsModel $details): Tag
    {
        if ($this->tags->existsForUserAndName($user->requireId(), $details->name)) {
            throw new TagNameTakenException();
        }

        $tag = $this->tagFactory->create($user, $details, $this->tags->nextPositionForUser($user->requireId()));
        $tag->setParent($this->parentForNewTag($user, $details->parentId));
        $this->entityManager->persist($tag);
        $this->entityManager->flush();

        return $tag;
    }

    public function delete(Tag $tag): void
    {
        $this->liftChildren($tag);
        $carriers = $this->subscriptionScope->findForUserByTagId($tag->getUser()->requireId(), $tag->requireId());
        foreach ($carriers as $subscription) {
            $subscription->removeTag($tag);
        }
        $this->entityManager->remove($tag);
        $this->entityManager->flush();
    }

    private function liftChildren(Tag $tag): void
    {
        $children = $this->tags->findChildren($tag);
        if ($children === []) {
            return;
        }
        $nextPosition = $this->tags->nextPositionForUser($tag->getUser()->requireId());
        foreach ($children as $child) {
            $child->setParent($tag->getParent());
            $child->setPosition($nextPosition++);
        }
    }

    private function parentForNewTag(User $user, ?int $parentId): ?Tag
    {
        if ($parentId === null) {
            return null;
        }
        $parent = $this->ownedParent($user, $parentId);
        $this->assertFitsUnder($this->treeOf($user), $parent, 0);

        return $parent;
    }

    private function parentFor(Tag $tag, ?int $parentId): ?Tag
    {
        if ($parentId === null) {
            return null;
        }
        if ($tag->requireId() === $parentId) {
            throw InvalidTagParentException::itself();
        }
        $parent = $this->ownedParent($tag->getUser(), $parentId);
        $tree = $this->treeOf($tag->getUser());
        if ($tree->isWithin($parent->requireId(), $tag->requireId())) {
            throw InvalidTagParentException::underItsOwnChild();
        }
        $this->assertFitsUnder($tree, $parent, $tree->heightOf($tag->requireId()));

        return $parent;
    }

    private function ownedParent(User $user, int $parentId): Tag
    {
        return $this->tags->findOneForUser($user->requireId(), $parentId)
            ?? throw InvalidTagParentException::unknown();
    }

    private function assertFitsUnder(TagTree $tree, Tag $parent, int $height): void
    {
        if ($tree->depthOf($parent->requireId()) + 1 + $height > Tag::MAX_DEPTH) {
            throw InvalidTagParentException::tooDeep();
        }
    }

    private function treeOf(User $user): TagTree
    {
        return new TagTree($this->tags->findForUser($user->requireId()));
    }
```

`TagRepository::hasChildren` is still used by `BulkSubscriber`, so leave it.

- [ ] **Step 9: Run the tests**

Run: `php bin/phpunit tests/Service/Tag tests/Controller/Api/TagControllerTest.php`
Expected: PASS.

- [ ] **Step 10: Gates for the touched files**

Run: `composer check && composer md`
Expected: clean. If `ServiceRoleRule` names another folder for `TagTree`, move it there and re-run.

- [ ] **Step 11: Commit**

```bash
git add backend/src/Entity/Tag.php backend/src/Service/Tag backend/tests/Service/Tag backend/tests/Controller/Api/TagControllerTest.php
git commit -m "feat(#1790): tags nest two levels below the top"
```

---

### Task 2: A tag's scope and unread count span its whole subtree

**Files:**
- Create: `backend/src/Repository/TagLineageDql.php`
- Modify: `backend/src/Repository/TagScopeDql.php`
- Modify: `backend/src/Repository/UnreadCountRepository.php`
- Test: `backend/tests/Controller/Api/EntryTagScopeTest.php`, `backend/tests/Repository/UnreadCountRepositoryTest.php`, `backend/tests/Repository/FeedRepositoryTagScopeTest.php`

**Interfaces:**
- Consumes: `Tag::MAX_DEPTH` (Task 1).
- Produces: `App\Repository\TagLineageDql` with static `alias(string $prefix, int $level): string`, `ancestorJoins(string $prefix): string`, `joinAncestors(QueryBuilder $qb, string $prefix): void`, `reaches(string $prefix, string $tagIdExpression): string`.

- [ ] **Step 1: Write the failing grandchild tests**

`EntryTagScopeTest` (it has `auth`, `tag`, `taggedFeed`, `markRead` and `titlesOfResponse` already):

```php
    public function testATopTagListsAndMarksReadItsGrandchildsEntries(): void
    {
        [$headers, $user] = $this->auth('tag-grandchild@example.com');
        $reading = $this->tag($user, 'reading', null);
        $longReads = $this->tag($user, 'long reads', $reading);
        $essays = $this->tag($user, 'essays', $longReads);
        $this->taggedFeed($user, 'own.example', 'essay', [$essays], '2026-10-04T00:00:00Z');
        $this->entityManager()->flush();

        $this->client->request('GET', '/api/entries?tag=' . $reading->requireId(), server: $headers);
        self::assertSame(['own.example essay'], $this->titlesOfResponse());
        $this->client->request('GET', '/api/entries?tag=' . $longReads->requireId(), server: $headers);
        self::assertSame(['own.example essay'], $this->titlesOfResponse());

        $this->markRead($headers, $reading->requireId());
        $this->client->request('GET', '/api/entries?tag=' . $essays->requireId() . '&view=unread', server: $headers);
        self::assertSame([], $this->titlesOfResponse());
    }
```

`UnreadCountRepositoryTest` (it has `user`, `tag`, `childTag`, `subscribedFeed`, `unreadEntry`):

```php
    public function testEveryAncestorCountsAGrandchildsEntryOnce(): void
    {
        $user = $this->user();
        $reading = $this->tag($user, 'reading');
        $longReads = $this->childTag($user, 'long reads', $reading);
        $essays = $this->childTag($user, 'essays', $longReads);
        [$deep, $deepFeed] = $this->subscribedFeed($user, 'https://example.com/deep.xml');
        $deep->addTag($essays);
        $deep->addTag($longReads);
        [$top, $topFeed] = $this->subscribedFeed($user, 'https://example.com/top.xml');
        $top->addTag($reading);
        $this->unreadEntry($deepFeed, 'deep-entry', null);
        $this->unreadEntry($topFeed, 'top-entry', null);

        $counts = $this->repository()->perTag($user->requireId());
        ksort($counts);

        self::assertSame(
            [$reading->requireId() => 2, $longReads->requireId() => 1, $essays->requireId() => 1],
            $counts,
        );
    }
```

`FeedRepositoryTagScopeTest` (it has `taggedFeed(User, string, Tag): Feed`):

```php
    public function testATopScopeRefreshesItsGrandchildsFeed(): void
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $owner = (new UserFactory($this->entityManager, $hasher))->create('owner@example.com');
        $reading = new Tag($owner, 'reading');
        $this->entityManager->persist($reading);
        $longReads = new Tag($owner, 'long reads');
        $longReads->setParent($reading);
        $this->entityManager->persist($longReads);
        $essays = new Tag($owner, 'essays');
        $essays->setParent($longReads);
        $this->entityManager->persist($essays);
        $essayFeed = $this->taggedFeed($owner, 'https://example.com/essay.xml', $essays);
        $this->entityManager->flush();

        $repository = $this->entityManager->getRepository(Feed::class);
        self::assertInstanceOf(FeedRepository::class, $repository);
        $scope = new DueFeedCriteria(
            new \DateTimeImmutable('2026-06-01T00:00:00Z'),
            $owner->requireId(),
            tagId: $reading->requireId(),
            force: true,
        );

        self::assertSame(
            [$essayFeed->getId()],
            array_map(static fn (Feed $feed): ?int => $feed->getId(), $repository->findDue($scope, 50)),
        );
    }
```

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit tests/Controller/Api/EntryTagScopeTest.php tests/Repository/UnreadCountRepositoryTest.php tests/Repository/FeedRepositoryTagScopeTest.php`
Expected: the three new tests FAIL. The top tag's list and the refresh scope are empty, and `reading` counts 1 instead of 2.

- [ ] **Step 3: Create `TagLineageDql`**

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Tag;
use Doctrine\ORM\QueryBuilder;

/** A tag and its ancestors as DQL aliases: `<prefix>0` is the tag, `<prefix>1` its parent, up to Tag::MAX_DEPTH. */
final class TagLineageDql
{
    public static function alias(string $prefix, int $level): string
    {
        return $prefix . $level;
    }

    public static function ancestorJoins(string $prefix): string
    {
        $joins = '';
        foreach (range(1, Tag::MAX_DEPTH) as $level) {
            $joins .= \sprintf(
                ' LEFT JOIN %s.parent %s',
                self::alias($prefix, $level - 1),
                self::alias($prefix, $level),
            );
        }

        return $joins;
    }

    public static function joinAncestors(QueryBuilder $qb, string $prefix): void
    {
        foreach (range(1, Tag::MAX_DEPTH) as $level) {
            $qb->leftJoin(self::alias($prefix, $level - 1) . '.parent', self::alias($prefix, $level));
        }
    }

    /** True when the tag or one of its ancestors has the id $tagIdExpression names. */
    public static function reaches(string $prefix, string $tagIdExpression): string
    {
        $matches = array_map(
            static fn (int $level): string => \sprintf('%s.id = %s', self::alias($prefix, $level), $tagIdExpression),
            range(0, Tag::MAX_DEPTH),
        );

        return '(' . implode(' OR ', $matches) . ')';
    }

    private function __construct()
    {
    }
}
```

- [ ] **Step 4: Route `TagScopeDql` through it**

Replace the class docblock and `predicate()`:

```php
/**
 * A subscription is in a tag's scope when it carries the tag or any tag below it; a semi-join, so a feed
 * carrying several of them appears once.
 */
final class TagScopeDql
{
    /** The caller binds `:tagId`. */
    public static function predicate(string $subscriptionAlias): string
    {
        $join = $subscriptionAlias . 'TagJoin';
        $lineage = $subscriptionAlias . 'ScopedTag';

        return \sprintf(
            'EXISTS (SELECT 1 FROM %1$s %2$s JOIN %2$s.tag %3$s%4$s WHERE %2$s.subscription = %5$s AND %6$s)',
            SubscriptionTag::class,
            $join,
            TagLineageDql::alias($lineage, 0),
            TagLineageDql::ancestorJoins($lineage),
            $subscriptionAlias,
            TagLineageDql::reaches($lineage, ':tagId'),
        );
    }
```

`restrict()` and the private constructor stay.

- [ ] **Step 5: One roll-up in `UnreadCountRepository`**

Replace `perTag`, delete `perChildTag` and `perRootTag`, and replace `taggedUnreadEntries` and the docblock on `countedDistinctBy`. Add `use App\Entity\Tag;`.

```php
    /**
     * @return array<int, int> tag id => unread entries across the tag and every tag below it; a tag with
     *                         none is absent
     */
    public function perTag(int $userId): array
    {
        $qb = $this->taggedUnreadEntries($userId)
            ->innerJoin(Tag::class, 'scopeTag', 'WITH', TagLineageDql::reaches('lineage', 'scopeTag.id'));

        return $this->countsByIntegerKey($this->countedDistinctBy($qb, 'scopeTag.id'));
    }
```

```php
    private function taggedUnreadEntries(int $userId): QueryBuilder
    {
        $aliases = EntryAliases::primary();
        $qb = $this->unreadEntries($userId);
        $this->scope->joinTags($qb, $aliases);
        $qb->innerJoin($aliases->tag . '.tag', TagLineageDql::alias('lineage', 0));
        TagLineageDql::joinAncestors($qb, 'lineage');

        return $qb;
    }
```

```php
    /** A feed carrying a tag and one below it yields a row per carried tag; DISTINCT folds them. */
    private function countedDistinctBy(QueryBuilder $qb, string $scopeKey): QueryBuilder
```

`EntryAliases::tagKey()` was used only by `perChildTag`. If nothing else calls it (`grep -rn "tagKey()" backend/src`), delete it.

- [ ] **Step 6: Run the scope and count tests on both databases**

Run: `php bin/phpunit tests/Controller/Api/EntryTagScopeTest.php tests/Repository tests/Controller/Api/EntryControllerTest.php`
Then: `docker compose exec php php bin/phpunit tests/Controller/Api/EntryTagScopeTest.php tests/Repository`
Expected: PASS on both. The existing one-level tests (`testAParentCountsItsOwnAndItsChildrensFeedsEachEntryOnce`, `testAParentListsItsOwnAndItsChildrensEntriesEachOnce`, …) still pass unchanged.

- [ ] **Step 7: Look at the real statement on MySQL**

Run the unread-count query against the Docker dev database. Use the Symfony profiler for one `GET /api/subscriptions` (or `vendor/bin/mate tools:list` for a query tool), copy the `perTag` SQL, and run `EXPLAIN` on it in `docker compose exec mysql mysql …`.
Expected: the `scopeTag` join reads the `tag` primary key. Note the timing in the PR body. If the OR join scans the whole `tag` table per row, report it before going on rather than guessing a rewrite.

- [ ] **Step 8: Gates and commit**

Run: `composer check && composer md`

```bash
git add backend/src/Repository backend/tests/Controller/Api/EntryTagScopeTest.php backend/tests/Repository
git commit -m "feat(#1790): a tag's scope and unread count span its whole subtree"
```

---

### Task 3: Backup and OPML export carry every level

**Files:**
- Modify: `backend/src/Service/Backup/AccountBackupExporter.php:75,111-125`
- Modify: `backend/src/Service/Backup/Pass/BackupTally.php`
- Modify: `backend/src/Service/Opml/Pass/OpmlTagTree.php`
- Test: `backend/tests/Service/Backup/AccountBackupExporterTest.php`, `backend/tests/Service/Backup/BackupInspectorTest.php`, `backend/tests/Service/Opml/OpmlExporterTest.php`

**Interfaces:**
- Consumes: `Tag::MAX_DEPTH`, `App\Service\Tag\Pass\TagTree` (Task 1).

- [ ] **Step 1: Write the failing tests**

`AccountBackupExporterTest`, next to `testAParentTagIsWrittenBeforeAChildOrderedAheadOfIt`:

```php
    public function testEachTagIsWrittenAfterItsParentAtEveryDepth(): void
    {
        $user = $this->makeUser('export-depth-first@example.com');
        $reading = new Tag($user, 'Reading');
        $reading->setPosition(9);
        $longReads = new Tag($user, 'Long reads');
        $longReads->setPosition(5);
        $longReads->setParent($reading);
        $essays = new Tag($user, 'Essays');
        $essays->setPosition(1);
        $essays->setParent($longReads);
        $this->entityManager->persist($reading);
        $this->entityManager->persist($longReads);
        $this->entityManager->persist($essays);
        $this->entityManager->flush();

        [$foundationPart] = $this->decodedParts($user);
        $tagLines = array_values(
            array_filter($foundationPart, static fn (array $line): bool => $line['kind'] === 'tag'),
        );

        self::assertSame(
            [['Reading', null], ['Long reads', 'Reading'], ['Essays', 'Long reads']],
            array_map(static fn (array $line): array => [$line['name'], $line['parent']], $tagLines),
        );
    }
```

`BackupInspectorTest`: **replace** `testAParentThatHasAParentItselfIsRefusedByName` with:

```php
    public function testAGrandchildTagIsAccepted(): void
    {
        $gzip = self::gzipOf([
            self::foundationHeader(), self::account(),
            self::tag('Reading'), self::tag('Long reads', 'Reading'), self::tag('Essays', 'Long reads'),
            self::footer(['tag' => 3]),
        ]);

        self::assertSame(3, self::inspector()->inspect($gzip)->counts['tags']);
    }

    public function testATagNestedPastTheLimitIsRefusedByName(): void
    {
        $gzip = self::gzipOf([
            self::foundationHeader(), self::account(),
            self::tag('Reading'), self::tag('Long reads', 'Reading'), self::tag('Essays', 'Long reads'),
            self::tag('Short essays', 'Essays'),
            self::footer(['tag' => 4]),
        ]);

        $this->expectException(InvalidBackupException::class);
        $this->expectExceptionMessage('The backup nests tag "Short essays" more than 2 levels below the top.');

        self::inspector()->inspect($gzip);
    }
```

Before writing the first test, check how `BackupInspectorTest` reads counts off an inspection (look for an existing assertion on a `BackupInventoryModel`) and use that exact accessor in place of `->counts['tags']`.

`OpmlExporterTest`:

```php
    public function testAGrandchildTagNestsTwoOutlinesDeepThoughItsAncestorsHaveNoFeeds(): void
    {
        $user = new User('opml-grandchild@example.com', new \DateTimeImmutable('2026-10-25T00:00:00Z'));
        $this->entityManager->persist($user);
        $reading = $this->tagAt($user, 'Reading', 0);
        $longReads = $this->tagAt($user, 'Long reads', 1, $reading);
        $essays = $this->tagAt($user, 'Essays', 2, $longReads);
        $this->taggedSubscription($user, 'https://essay.example.com/feed.xml', $essays);
        $this->entityManager->flush();

        self::assertMatchesRegularExpression(
            '#<outline text="Reading" title="Reading">\s*'
            . '<outline text="Long reads" title="Long reads">\s*'
            . '<outline text="Essays" title="Essays">\s*'
            . '<outline type="rss" text="[^"]*" title="[^"]*" xmlUrl="https://essay.example.com/feed.xml"/>#',
            $this->exporter()->export($user),
        );
    }
```

- [ ] **Step 2: Run them to see them fail**

Run: `php bin/phpunit tests/Service/Backup/AccountBackupExporterTest.php tests/Service/Backup/BackupInspectorTest.php tests/Service/Opml/OpmlExporterTest.php`
Expected: FAIL. The export writes `Essays` before `Long reads`, the inspector refuses the grandchild ("which has a parent itself"), and the OPML export drops `Reading` and everything under it.

- [ ] **Step 3: Parents first at any depth**

In `AccountBackupExporter.php`, rename `rootsFirst` to `parentsFirst` at its call site (line 75), replace its body, and add `use App\Service\Tag\Pass\TagTree;`:

```php
    /**
     * A child names its parent, and the restore resolves names in file order.
     *
     * @param list<Tag> $tags
     *
     * @return list<Tag>
     */
    private static function parentsFirst(array $tags): array
    {
        $tree = new TagTree($tags);
        usort($tags, static fn (Tag $left, Tag $right): int => $tree->depthOf($left->requireId())
            <=> $tree->depthOf($right->requireId()));

        return $tags;
    }
```

`usort` is stable, so tags at one depth keep `findForUser`'s position order.

- [ ] **Step 4: The restore checks depth**

In `BackupTally.php`, replace both `$tagNames` and `$parentsByChildName` with one map. Change every `isset($this->tagNames[…])` (lines 84, 99, 145) to `isset($this->tagDepths[…])`. Rewrite `acceptTag` and `acceptParent`, and add `use App\Entity\Tag;`:

```php
    /** @var array<string, int> each declared tag's depth below the top, by name */
    private array $tagDepths = [];
```

```php
    private function acceptTag(TagLine $line): void
    {
        if (isset($this->tagDepths[$line->name])) {
            throw new InvalidBackupException(sprintf('The backup declares tag "%s" twice.', $line->name));
        }

        $this->tagDepths[$line->name] = $line->parent === null ? 0 : $this->depthUnder($line->parent, $line->name);
        ++$this->counts['tags'];
    }

    private function depthUnder(string $parent, string $child): int
    {
        if (!isset($this->tagDepths[$parent])) {
            throw new InvalidBackupException(sprintf('The backup names parent tag "%s" before declaring it.', $parent));
        }
        $depth = $this->tagDepths[$parent] + 1;
        if ($depth > Tag::MAX_DEPTH) {
            throw new InvalidBackupException(
                sprintf('The backup nests tag "%s" more than %d levels below the top.', $child, Tag::MAX_DEPTH),
            );
        }

        return $depth;
    }
```

Keep the existing docblock above `acceptTag` (the unique-index note).

- [ ] **Step 5: OPML export remembers every ancestor**

In `OpmlTagTree.php`, replace `remember`:

```php
    private function remember(Tag $tag): void
    {
        for ($known = $tag; $known !== null; $known = $known->getParent()) {
            $this->tags[$known->requireId()] = $known;
        }
    }
```

Update the class docblock to "an ancestor appears even when only a tag below it has feeds". Update the `OpmlExporter` class docblock from "a child tag nested in its parent" to "each tag nested in its parent".

- [ ] **Step 6: Run the tests**

Run: `php bin/phpunit tests/Service/Backup tests/Service/Opml`
Expected: PASS, including `GoldenBackupRestoreTest` and the `testAParentNamedBeforeItIsDeclaredIsRefusedByName` case.

- [ ] **Step 7: Gates and commit**

Run: `composer check && composer md`

```bash
git add backend/src/Service/Backup backend/src/Service/Opml backend/tests/Service/Backup backend/tests/Service/Opml
git commit -m "feat(#1790): backup and OPML export carry every tag level"
```

---

### Task 4: The frontend hierarchy and recursive tree derivations

**Files:**
- Create: `frontend/src/app/reader/state/tag-hierarchy.ts`, `frontend/src/app/reader/state/tag-hierarchy.spec.ts`
- Modify: `frontend/src/app/reader/state/subscription-derivations.ts`
- Modify: `frontend/src/app/reader/feeds/tag-picker/tag-search.ts`, `tag-option-list.component.html`, `tag-option-list.component.scss`
- Test: `frontend/src/app/reader/state/subscriptions.store.spec.ts`, `frontend/src/app/reader/feeds/tag-picker/tag-search.spec.ts`, `tag-option-list.component.spec.ts`, `frontend/src/app/settings/organise/bulk-tag-dialog.component.spec.ts`

**Interfaces:**
- Produces: `MAX_TAG_DEPTH = 2` and `class TagHierarchy { constructor(tags: readonly TagDto[]); depthOf(tagId: number): number; heightOf(tagId: number): number; isWithin(tagId: number, ancestorId: number): boolean; mayPlaceUnder(tagId: number | null, parentId: number | null): boolean }` from `reader/state/tag-hierarchy.ts`. `tagPath(tag: TagDto, byId: ReadonlyMap<number, TagDto>): string[]` from `subscription-derivations.ts`. `TagNode.children` is now filled at every level. `TagOption.ancestorPath: string | null` and `TagOption.depth: number` replace `parentName` and `indented`.

- [ ] **Step 1: Write the failing `TagHierarchy` spec**

```ts
import { makeTag } from '../testing/tag.factory';
import { TagHierarchy } from './tag-hierarchy';

describe('TagHierarchy', () => {
  const reading = makeTag(1, 'Reading');
  const longReads = makeTag(2, 'Long reads', { parentId: 1 });
  const essays = makeTag(3, 'Essays', { parentId: 2 });
  const tech = makeTag(4, 'Tech');
  const books = makeTag(5, 'Books');
  const novels = makeTag(6, 'Novels', { parentId: 5 });
  const hierarchy = new TagHierarchy([reading, longReads, essays, tech, books, novels]);

  it('puts a root at depth 0 and adds one per level', () => {
    expect([1, 2, 3].map((id) => hierarchy.depthOf(id))).toEqual([0, 1, 2]);
  });

  it('measures the deepest branch below a tag', () => {
    expect([1, 2, 3].map((id) => hierarchy.heightOf(id))).toEqual([2, 1, 0]);
  });

  it('counts a tag within itself and its ancestors only', () => {
    expect(hierarchy.isWithin(3, 1)).toBe(true);
    expect(hierarchy.isWithin(3, 3)).toBe(true);
    expect(hierarchy.isWithin(1, 3)).toBe(false);
    expect(hierarchy.isWithin(3, 4)).toBe(false);
  });

  it('places a leaf two levels down but not three', () => {
    expect(hierarchy.mayPlaceUnder(4, 2)).toBe(true);
    expect(hierarchy.mayPlaceUnder(4, 3)).toBe(false);
  });

  it('places a tag with children only where its subtree still fits', () => {
    expect(hierarchy.mayPlaceUnder(5, 1)).toBe(true);
    expect(hierarchy.mayPlaceUnder(5, 2)).toBe(false);
  });

  it('never places a tag under itself or anything below it', () => {
    expect(hierarchy.mayPlaceUnder(1, 1)).toBe(false);
    expect(hierarchy.mayPlaceUnder(1, 2)).toBe(false);
  });

  it('always places at the top level, and a new tag anywhere with room below', () => {
    expect(hierarchy.mayPlaceUnder(3, null)).toBe(true);
    expect(hierarchy.mayPlaceUnder(null, 2)).toBe(true);
    expect(hierarchy.mayPlaceUnder(null, 3)).toBe(false);
  });

  it('treats a tag whose parent is unknown as a root', () => {
    const orphan = makeTag(7, 'Orphan', { parentId: 99 });
    expect(new TagHierarchy([orphan]).depthOf(7)).toBe(0);
  });
});
```

- [ ] **Step 2: Run it to see it fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/state/tag-hierarchy.spec.ts`
Expected: FAIL, cannot find module `./tag-hierarchy`.

- [ ] **Step 3: Create `tag-hierarchy.ts`**

```ts
import { TagDto } from '../models';
import { isRootTag } from './subscription-derivations';

/** Levels below the top a tag may sit; a root is at 0. Mirrors the backend's `Tag::MAX_DEPTH`. */
export const MAX_TAG_DEPTH = 2;

export class TagHierarchy {
  private readonly parentIds: ReadonlyMap<number, number | null>;
  private readonly childIds: ReadonlyMap<number, readonly number[]>;

  constructor(tags: readonly TagDto[]) {
    const knownIds = new Set(tags.map((tag) => tag.id));
    this.parentIds = new Map(
      tags.map((tag) => [tag.id, isRootTag(tag, knownIds) ? null : tag.parentId]),
    );
    const childIds = new Map<number, number[]>();
    for (const [id, parentId] of this.parentIds) {
      if (parentId !== null) childIds.set(parentId, [...(childIds.get(parentId) ?? []), id]);
    }
    this.childIds = childIds;
  }

  depthOf(tagId: number): number {
    const parentId = this.parentIds.get(tagId) ?? null;
    return parentId === null ? 0 : this.depthOf(parentId) + 1;
  }

  heightOf(tagId: number): number {
    return (this.childIds.get(tagId) ?? []).reduce(
      (height, childId) => Math.max(height, this.heightOf(childId) + 1),
      0,
    );
  }

  /** Whether `tagId` is `ancestorId` itself or sits anywhere below it. */
  isWithin(tagId: number, ancestorId: number): boolean {
    if (tagId === ancestorId) return true;
    const parentId = this.parentIds.get(tagId) ?? null;
    return parentId !== null && this.isWithin(parentId, ancestorId);
  }

  /** Whether a tag (null: a new, childless one) may sit under `parentId` (null: the top level). */
  mayPlaceUnder(tagId: number | null, parentId: number | null): boolean {
    if (parentId === null) return true;
    if (tagId !== null && this.isWithin(parentId, tagId)) return false;
    const height = tagId === null ? 0 : this.heightOf(tagId);
    return this.depthOf(parentId) + 1 + height <= MAX_TAG_DEPTH;
  }
}
```

- [ ] **Step 4: Run the `TagHierarchy` spec**

Run: `docker compose exec -T frontend npx jest src/app/reader/state/tag-hierarchy.spec.ts`
Expected: PASS.

- [ ] **Step 5: Write the failing derivation specs**

In `subscriptions.store.spec.ts`, add next to the existing `buildTagTree` tests (use its existing tag/subscription fixture helpers; read them first and keep their names):

```ts
  it('builds grandchildren under their parent and counts a top tag across its whole subtree', () => {
    const reading = makeTag(1, 'Reading');
    const longReads = makeTag(2, 'Long reads', { parentId: 1 });
    const essays = makeTag(3, 'Essays', { parentId: 2 });
    const essayFeed = makeSubscription({ id: 30, entryCount: 4, tags: [{ ...essays, position: 0 }] });

    const [root] = buildTagTree([essayFeed], [reading, longReads, essays]);

    expect(root.children.map((child) => child.tag.id)).toEqual([2]);
    expect(root.children[0].children.map((grandchild) => grandchild.tag.id)).toEqual([3]);
    expect(root.entryCount).toBe(4);
    expect(flattenTagTree([root]).map((node) => node.tag.id)).toEqual([1, 2, 3]);
  });

  it('orders and groups siblings at every level', () => {
    const tags = [
      makeTag(1, 'Reading'),
      makeTag(2, 'Long reads', { parentId: 1 }),
      makeTag(3, 'Essays', { parentId: 2 }),
      makeTag(4, 'Poems', { parentId: 2 }),
    ];

    expect(tagsInTreeOrder(tags).map((tag) => tag.id)).toEqual([1, 2, 3, 4]);
    expect(siblingIdsByTagId(tags).get(3)).toEqual([3, 4]);
  });

  it('labels a grandchild with its whole path', () => {
    const tags = [
      makeTag(1, 'Reading'),
      makeTag(2, 'Long reads', { parentId: 1 }),
      makeTag(3, 'Essays', { parentId: 2 }),
    ];

    expect(tagLabel(tags[2], tags)).toBe('Reading / Long reads / Essays');
  });
```

If `makeSubscription` lives elsewhere than `reader/testing/`, import it from where the spec already does. Add the missing imports (`flattenTagTree`, `tagsInTreeOrder`, `siblingIdsByTagId`, `tagLabel`) from `./subscription-derivations`.

- [ ] **Step 6: Run them to see them fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/state/subscriptions.store.spec.ts`
Expected: FAIL. The child has no children, `flattenTagTree` yields `[1, 2]`, and the label reads `Long reads / Essays`.

- [ ] **Step 7: Make the derivations recurse**

In `subscription-derivations.ts`:

Change the `children` doc on `TagNode` to `/** The tag's children in position order, each with its own. */`.

Replace `buildTagTree`'s body after `const byTagId = …`, and its docblock's "one level deep" with "at every level" and "its children's" with "every tag's below it":

```ts
  const children = childrenByParentId(tags);
  const node = (tag: TagDto): TagNode => {
    const branch: TagNode = {
      tag,
      subscriptions: feedsOf(byTagId, tag.id),
      children: (children.get(tag.id) ?? []).map(node),
      unreadCount: tagUnread.get(tag.id) ?? 0,
      entryCount: 0,
    };
    return { ...branch, entryCount: totalEntryCount(distinctFeedsOf(branch)) };
  };
  return rootsOf(tags).map(node);
```

```ts
/** Every node, each followed by the tags below it: the order the sidebar shows. */
export function flattenTagTree(nodes: TagNode[]): TagNode[] {
  return nodes.flatMap((node) => [node, ...flattenTagTree(node.children)]);
}
```

```ts
/** Each tag's sibling set (the roots, or one parent's children) as ids in the order given. */
export function siblingIdsByTagId(tags: readonly TagDto[]): Map<number, readonly number[]> {
  const children = childrenByParentId(tags);
  const siblingSets = [rootsOf(tags), ...tags.map((tag) => children.get(tag.id) ?? [])];
  const byTagId = new Map<number, readonly number[]>();
  for (const siblings of siblingSets) {
    const ids = siblings.map((sibling) => sibling.id);
    for (const id of ids) byTagId.set(id, ids);
  }
  return byTagId;
}
```

```ts
/** Each tag followed by the tags below it, whatever order the caller holds them in. */
export function tagsInTreeOrder(tags: TagDto[]): TagDto[] {
  const children = childrenByParentId(tags);
  const branch = (tag: TagDto): TagDto[] => [tag, ...(children.get(tag.id) ?? []).flatMap(branch)];
  return rootsOf(tags).flatMap(branch);
}

/** A nested tag reads with its ancestors, so a pill never shows a bare child out of context. */
export function tagLabel(tag: TagDto, tags: readonly TagDto[]): string {
  return tagPath(tag, new Map(tags.map((candidate) => [candidate.id, candidate]))).join(' / ');
}

/** The names from the top-most known ancestor down to the tag. */
export function tagPath(tag: TagDto, byId: ReadonlyMap<number, TagDto>): string[] {
  const parent = tag.parentId === null ? undefined : byId.get(tag.parentId);
  return parent === undefined ? [tag.name] : [...tagPath(parent, byId), tag.name];
}
```

Delete `labelWithParent`. Replace `distinctFeedsOf` with:

```ts
function distinctFeedsOf(node: TagNode): SubscriptionDto[] {
  const byId = new Map<number, SubscriptionDto>();
  for (const feed of subtreeFeeds(node)) byId.set(feed.id, feed);
  return [...byId.values()];
}

function subtreeFeeds(node: TagNode): SubscriptionDto[] {
  return [...node.subscriptions, ...node.children.flatMap(subtreeFeeds)];
}
```

- [ ] **Step 8: Path and depth in the tag picker**

In `tag-search.ts`:
- `TagOption`: replace `parentName: string | null` with `ancestorPath: string | null`, and `indented: boolean` with `depth: number`.
- `IndexedTag`: replace `parentName` with `ancestorPath: string | null` and add `depth: number`.
- Import `tagPath` (not `labelWithParent`) and build the index like this:

```ts
  private readonly indexed = computed(() => {
    const tags = this.source.tags();
    const byId = new Map(tags.map((tag) => [tag.id, tag]));
    return tagsInTreeOrder(tags).map((tag): IndexedTag => {
      const path = tagPath(tag, byId);
      const label = path.join(' / ');
      const ancestorPath = path.length > 1 ? path.slice(0, -1).join(' / ') : null;
      return { tag, ancestorPath, depth: path.length - 1, label, searchText: label.toLowerCase() };
    });
  });
```

- `optionOf(indexed, indented: boolean)` becomes `optionOf(indexed, nested: boolean)`. It sets `ancestorPath: indexed.ancestorPath` and `depth: nested ? indexed.depth : 0`. The browse list passes `true` (where it passed `indexed.tag.parentId !== null`). Every other call keeps passing `false`.

In `tag-option-list.component.html`, replace `[class.indented]="entry.option.indented"` with `[style.--depth]="entry.option.depth"`, and the prefix condition with:

```html
          @if (entry.option.ancestorPath && entry.option.depth === 0) {
            <span class="parent">{{ entry.option.ancestorPath }} / </span>
          }
```

In `tag-option-list.component.scss`, replace the `.option.indented` rule with:

```scss
.option {
  padding-inline-start: calc(var(--space-2) + var(--space-5) * var(--depth, 0));
}
```

If `.option` already sets `padding-inline-start`, merge this into that rule instead of adding a second one.

Update `tag-search.spec.ts` and `tag-option-list.component.spec.ts` for the renamed fields. Any assertion on `indented: true` becomes `depth: 1`, and `parentName: 'X'` becomes `ancestorPath: 'X'`. Add one case per spec with a grandchild: depth 2, ancestor path `Reading / Long reads`.

- [ ] **Step 9: Run the touched specs**

Run: `docker compose exec -T frontend npx jest src/app/reader/state src/app/reader/feeds/tag-picker src/app/settings/organise/bulk-tag-dialog.component.spec.ts`
Expected: PASS.

- [ ] **Step 10: Commit**

```bash
git add frontend/src/app/reader/state frontend/src/app/reader/feeds/tag-picker
git commit -m "feat(#1790): the frontend tag tree recurses to every level"
```

---

### Task 5: Dragging a tag follows the depth rule

**Files:**
- Modify: `frontend/src/app/reader/tag-drop.ts`
- Test: `frontend/src/app/reader/tag-drop.spec.ts`

**Interfaces:**
- Consumes: `TagHierarchy` (Task 4).
- Produces: `resolveTagDrop` keeps its signature and `TagDrop` shape.

The rule (replaces the #1765 lines about one level):
- A header has a **middle zone** when it can take the dragged tag as a child: it is not the dragged tag's current parent, and `TagHierarchy.mayPlaceUnder(dragged, header)` holds. Otherwise its middle splits into halves.
- An **edge** drop lands in the header's sibling set, and only where `mayPlaceUnder(dragged, header's parent)` holds. Otherwise it resolves to `null`.

- [ ] **Step 1: Rewrite the one-level specs and add the deep ones**

In `tag-drop.spec.ts`:

Replace `'counts the exact middle of a child header as its lower half'` (tagC at 120 now nests under B1):

```ts
  it('nests a childless tag under a child from its middle', () => {
    expect(resolveTagDrop({ tag: tagC, layout: layout() }, TAGS, 120)).toEqual({
      parentId: 21,
      order: [3],
      mark: { kind: 'nest', tagId: 21 },
    });
  });
```

Replace `'keeps a tag with children at the top level: no nest zone, no child slot'`:

```ts
  it('nests a tag with children under a root, but never inside its own subtree', () => {
    expect(resolveTagDrop({ tag: tagB, layout: layout() }, TAGS, 20)).toEqual({
      parentId: 1,
      order: [2],
      mark: { kind: 'nest', tagId: 1 },
    });
    expect(resolveTagDrop({ tag: tagB, layout: layout() }, TAGS, 155)).toBeNull();
  });
```

Add a three-level fixture and its cases at the end of the `describe`:

```ts
  describe('a grandchild level', () => {
    const B1a = tag(211, 21, 0);
    const DEEP = [...TAGS, B1a];
    /** A 0–40, B 50–90, B1 100–140, B1a 150–190, B2 200–240, C 250–290. */
    const deep = () => layout([1, 2, 21, 211, 22, 3]);

    it('splits a grandchild header into halves: nothing nests below it', () => {
      expect(resolveTagDrop({ tag: tagC, layout: deep() }, DEEP, 170)).toEqual({
        parentId: 21,
        order: [211, 3],
        mark: { kind: 'after', tagId: 211 },
      });
    });

    it('makes the bottom edge of a child with shown children its first grandchild', () => {
      expect(resolveTagDrop({ tag: tagC, layout: deep() }, DEEP, 138)).toEqual({
        parentId: 21,
        order: [3, 211],
        mark: { kind: 'before', tagId: 211 },
      });
    });

    it('keeps a tag with children out of the grandchild level', () => {
      expect(resolveTagDrop({ tag: tagB, layout: deep() }, DEEP, 152)).toBeNull();
      const tagD = tag(4, null, 3);
      const D1 = tag(41, 4, 0);
      expect(
        resolveTagDrop({ tag: tagD, layout: deep() }, [...DEEP, tagD, D1], 120),
      ).toBeNull();
    });

    it('moves a child with children under another root', () => {
      expect(resolveTagDrop({ tag: B1, layout: deep() }, DEEP, 20)).toEqual({
        parentId: 1,
        order: [21],
        mark: { kind: 'nest', tagId: 1 },
      });
    });
  });
```

`tagD` at 120 is B1's middle. B1 is at depth 1 and D has height 1, so 1+1+1 > 2: no nest zone. The lower half then resolves to "before B1a" under B1, which is also too deep, so the result is `null`. `tagB` at 152 is the top edge of B1a: landing under B1 is inside B's own subtree, so `null`.

- [ ] **Step 2: Run the spec to see the new cases fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/tag-drop.spec.ts`
Expected: FAIL on the replaced cases and the grandchild-level cases.

- [ ] **Step 3: Rewrite the rule in `tag-drop.ts`**

Import `TagHierarchy` from `./state/tag-hierarchy`. In `slotAt`, drop the root-only condition:

```ts
  if (tree.mayNest(dragged, anchor) && share >= NEST_FROM && share < NEST_TO) {
    return { parentId: anchor.id, beforeId: null, mark: { kind: 'nest', tagId: anchor.id } };
  }
```

In `class TagTree`, add the field `private readonly hierarchy: TagHierarchy;` and set it in the constructor (`this.hierarchy = new TagHierarchy(tags);`). Delete `hasChildren`, and replace `mayNest` and `mayLandUnder`:

```ts
  mayNest(dragged: TagDto, parent: TagDto): boolean {
    return parent.id !== dragged.parentId && this.hierarchy.mayPlaceUnder(dragged.id, parent.id);
  }

  mayLandUnder(dragged: TagDto, parentId: number | null): boolean {
    return this.hierarchy.mayPlaceUnder(dragged.id, parentId);
  }
```

`slotBelow` already handles any depth: the next header is the anchor's first child exactly when that child's `parentId` is the anchor.

- [ ] **Step 4: Run the drag specs**

Run: `docker compose exec -T frontend npx jest src/app/reader/tag-drop.spec.ts src/app/reader/tag-drag-session.spec.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/reader/tag-drop.ts frontend/src/app/reader/tag-drop.spec.ts
git commit -m "feat(#1790): dragging a tag nests it as deep as the rule allows"
```

---

### Task 6: The sidebar renders every level

**Files:**
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar.component.ts:58-68,206-215,284-289`
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar.component.html:147-185,282,311`
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar.component.scss`
- Test: `frontend/src/app/reader/shell/sidebar/sidebar.component.spec.ts`

**Interfaces:**
- Consumes: recursive `TagNode` (Task 4).
- Produces: `export interface TagBranchView { readonly node: TagNode; readonly open: boolean; readonly depth: number; readonly children: readonly TagBranchView[] }`. It replaces `TagGroupView` and `TagChildView`.

Look: a grandchild box nests inside its parent's `.tag-child` box by the same `.tag-child` rule, so the indent steps once more and density stays dense. This is a proposal. Task 9's live check shows it at desktop and phone width.

- [ ] **Step 1: Write the failing spec**

In `sidebar.component.spec.ts`, inside `describe('child tags (#1736)')`:

```ts
      it('nests a grandchild box inside its open parent box, before the parent feeds', () => {
        const fixture = mount({
          tagTree: [
            parentNode(10, [
              { ...parentNode(11, [tagNode(12, [sub(3)], 11)]), subscriptions: [sub(2)] },
            ]),
          ],
        });
        fixture.componentInstance.toggle(10);
        fixture.componentInstance.toggle(11);
        fixture.componentInstance.toggle(12);
        fixture.detectChanges();

        const childBox = (fixture.nativeElement as HTMLElement).querySelector(
          '.tag-group > .tag-child',
        )!;
        expect(blockKinds(childBox)).toEqual(['taghead', 'tag-child', 'tagfeeds']);
        const grandchildBox = childBox.querySelector(':scope > .tag-child')!;
        expect(blockKinds(grandchildBox)).toEqual(['taghead', 'tagfeeds']);
      });

      it('shows grandchildren only while their parent is open', () => {
        const fixture = mount({
          tagTree: [parentNode(10, [parentNode(11, [tagNode(12, [], 11)])])],
        });
        fixture.componentInstance.toggle(10);
        fixture.detectChanges();
        expect(heads(fixture).map((head) => head.name)).toEqual(['t10', 't11']);

        fixture.componentInstance.toggle(11);
        fixture.detectChanges();
        expect(heads(fixture).map((head) => head.name)).toEqual(['t10', 't11', 't12']);
      });
```

- [ ] **Step 2: Run it to see it fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/shell/sidebar/sidebar.component.spec.ts`
Expected: FAIL. The child box has no nested `.tag-child`, and `t12` never renders.

- [ ] **Step 3: One recursive view**

In `sidebar.component.ts`, replace `TagChildView` and `TagGroupView` with `TagBranchView` (above), and `tagGroups` with:

```ts
  readonly tagGroups = computed<TagBranchView[]>(() => {
    const expanded = this.expanded();
    const branch = (node: TagNode, depth: number): TagBranchView => {
      const open = expanded.has(node.tag.id);
      const children = open ? node.children.map((child) => branch(child, depth + 1)) : [];
      return { node, open, depth, children };
    };
    return this.tagTree().map((root) => branch(root, 0));
  });
```

- [ ] **Step 4: One recursive template**

In `sidebar.component.html`, replace the body of each `.tag-group` (the `tagHead` outlet, the `@for` over `group.children` and the trailing `tagFeeds` outlet) with a single outlet:

```html
            <div
              class="tag-group group"
              [class.open]="group.open"
              [class.drop-before]="tagSession.cueFor(group.node.tag.id) === 'before'"
              [class.drop-after]="tagSession.cueFor(group.node.tag.id) === 'after'"
            >
              <ng-container *ngTemplateOutlet="tagBranch; context: { $implicit: group }" />
            </div>
```

Add the template next to `#tagHead`:

```html
  <ng-template #tagBranch let-branch>
    <ng-container
      *ngTemplateOutlet="
        tagHead;
        context: { $implicit: branch.node, depth: branch.depth, open: branch.open }
      "
    />
    @for (child of branch.children; track child.node.tag.id) {
      <div
        class="tag-child"
        [class.drop-before]="tagSession.cueFor(child.node.tag.id) === 'before'"
        [class.drop-after]="tagSession.cueFor(child.node.tag.id) === 'after'"
      >
        <ng-container *ngTemplateOutlet="tagBranch; context: { $implicit: child }" />
      </div>
    }
    @if (branch.open) {
      <ng-container *ngTemplateOutlet="tagFeeds; context: { $implicit: branch.node }" />
    }
  </ng-template>
```

In `#tagHead`, change both `depth === 1 ? 'dense' : 'md'` to `depth > 0 ? 'dense' : 'md'`. Each `.taghead` and `.tagfeeds` drop list is still a sibling block inside a plain `div`, so no drop list wraps another.

- [ ] **Step 5: Styles**

Read `sidebar.component.scss:150-200` and `:315-345`. The `.tag-child` rules use descendant selectors (`.tag-child .tagfeeds`, `.organising .tag-child .tagfeeds`), so a nested box inherits them as is. Only add a rule if the live check (Task 9) shows a real defect. Use tokens only.

`endTagDrag` (lines 284-289) still only expands the drop's `parentId`. Its ancestors are open already, since a header is only droppable while shown.

- [ ] **Step 6: Run the sidebar specs**

Run: `docker compose exec -T frontend npx jest src/app/reader/shell/sidebar`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/shell/sidebar
git commit -m "feat(#1790): the sidebar nests grandchild tags"
```

---

### Task 7: The organise page nests every level

**Files:**
- Modify: `frontend/src/app/settings/organise/organise.store.ts:21-37,84-190`
- Modify: `frontend/src/app/settings/organise/organise-section.component.html:129-136,283-313`
- Modify: `frontend/src/app/settings/organise/organise-section.component.scss:288-295`
- Test: `frontend/src/app/settings/organise/organise.store.spec.ts`, `organise-section.component.spec.ts`

**Interfaces:**
- Consumes: `TagHierarchy` (Task 4).
- Produces: `OrganiseGroup.depth: number` (ancestors among all tags), and `OrganiseGroupNode { readonly group: OrganiseGroup; readonly children: readonly OrganiseGroupNode[] }`.

- [ ] **Step 1: Write the failing store specs**

Rename `'lists each root followed by its children, children one level deep'` to `'lists each tag followed by the tags below it, with their depth'` and give it a grandchild:

```ts
  it('lists each tag followed by the tags below it, with their depth', () => {
    const store = make(
      [],
      [
        tag(1, 'Reading', 0),
        { ...tag(2, 'Long reads', 1), parentId: 1 },
        { ...tag(4, 'Essays', 3), parentId: 2 },
        tag(3, 'Tech', 2),
      ],
    );

    expect(store.groups().map((group) => [group.tag?.name ?? 'untagged', group.depth])).toEqual([
      ['Reading', 0],
      ['Long reads', 1],
      ['Essays', 2],
      ['Tech', 0],
      ['untagged', 0],
    ]);
  });
```

Replace the two `groupTree` assertions' mapping, `node.children.map((child) => child.key)`, with a recursive shape helper defined at the top of the `describe`:

```ts
  type Shape = [GroupKey, Shape[]];
  const shape = (node: OrganiseGroupNode): Shape => [node.group.key, node.children.map(shape)];
```

Then write `expect(store.groupTree().map(shape)).toEqual([[1, [[2, []]]], [3, []], ['untagged', []]]);` and `.toEqual([[2, []]])` for the filtered case. Import `GroupKey` and `OrganiseGroupNode` from `./organise.store`. Add:

```ts
  it('nests a grandchild under its parent, and under its nearest shown ancestor when filtered', () => {
    const tags = [
      tag(1, 'Reading', 0),
      { ...tag(2, 'Long reads', 1), parentId: 1 },
      { ...tag(3, 'Essays', 2), parentId: 2 },
    ];
    const store = make(
      [sub(10, 'essay', { tagIds: [3] }), sub(11, 'reading', { tagIds: [1] })],
      tags,
    );

    expect(store.groupTree().map(shape)).toEqual([[1, [[2, [[3, []]]]]], ['untagged', []]]);

    store.titleFilter.set('e');
    expect(store.groupTree().map(shape)).toEqual([[1, [[3, []]]]]);
  });
```

With the filter `e`, both feeds match, so `Reading` and `Essays` show and `Long reads` (no feeds) hides. `Essays` then sits under `Reading`. If `make`/`sub` take different arguments, follow the helpers as the spec defines them.

- [ ] **Step 2: Run to see them fail**

Run: `docker compose exec -T frontend npx jest src/app/settings/organise/organise.store.spec.ts`
Expected: FAIL.

- [ ] **Step 3: Recursive store**

In `organise.store.ts`:
- `OrganiseGroup.depth`: type `number`, doc `/** Levels below the top among all tags; 0 for a root and the untagged group. */`.
- `OrganiseGroupNode.children: readonly OrganiseGroupNode[]`, doc `/** A group with the groups shown below it. */`.
- In `groups`, replace `knownIds` and `isRootTag` with `const hierarchy = new TagHierarchy(orderedTags);` and `depth: hierarchy.depthOf(tag.id)`. Drop the `isRootTag` import and add `TagHierarchy` from `../../reader/state/tag-hierarchy`.
- Replace `groupTree`:

```ts
  /** `groups()` as a tree: each group under its nearest ancestor the filter still shows. */
  readonly groupTree = computed<OrganiseGroupNode[]>(() => {
    const groups = this.groups();
    const shownKeys = new Set(groups.map((group) => group.key));
    const tagsById = new Map(this.tags().map((tag) => [tag.id, tag]));
    const shownAncestorOf = (group: OrganiseGroup): GroupKey | null => {
      let ancestorId = group.tag?.parentId ?? null;
      while (ancestorId !== null && !shownKeys.has(ancestorId)) {
        ancestorId = tagsById.get(ancestorId)?.parentId ?? null;
      }
      return ancestorId;
    };
    const nodesUnder = (key: GroupKey | null): OrganiseGroupNode[] =>
      groups
        .filter((group) => shownAncestorOf(group) === key)
        .map((group) => ({ group, children: nodesUnder(group.key) }));
    return nodesUnder(null);
  });
```

- [ ] **Step 4: Recursive panel template**

In `organise-section.component.html`:
- The top loop passes the node: `context: { $implicit: node }`.
- The `#panel` template takes `let-node`. It reads `node.group` where it read `group`, uses `[class.child]="node.group.depth > 0"`, and recurses:

```html
          @if (node.children.length > 0) {
            <div class="children">
              @for (child of node.children; track child.group.key) {
                <ng-container *ngTemplateOutlet="panel; context: { $implicit: child }" />
              }
            </div>
          }
```

- In the tag filter menu, replace `[class.child]="t.parentId !== null"` with `[style.--depth]="depthOf(t)"`. Add `protected depthOf(tag: TagDto): number` to the component. It reads a `computed(() => new TagHierarchy(this.tags.tags()))` the component holds, next to `orderedTags`.

In `organise-section.component.scss`, replace `.menu .pop label.child { padding-left: var(--space-4); }` with:

```scss
.menu .pop label {
  padding-left: calc(var(--space-4) * var(--depth, 0));
}
```

Leave `.tree > app-organise-tag-group.child`: a filter-lifted group of any depth still reads as indented.

- [ ] **Step 5: Section spec**

In `organise-section.component.spec.ts`, find every test that builds or reads `groupTree()` children as groups (search `children`). Rewrite them to the node shape (`child.group.key`). Add one render test: a three-level tree shows the grandchild's `app-organise-tag-group` inside the child's `.children`.

- [ ] **Step 6: Run the organise specs**

Run: `docker compose exec -T frontend npx jest src/app/settings/organise`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/settings/organise
git commit -m "feat(#1790): the organise page nests every tag level"
```

---

### Task 8: Parent choices in the tag form and the row menus

**Files:**
- Modify: `frontend/src/app/reader/feeds/manage/tag-form-dialog.component.{ts,html}`
- Modify: `frontend/public/i18n/en.json:851`, `frontend/public/i18n/de.json:851`
- Modify: `frontend/src/app/reader/state/tags.store.ts:22` and `tags.store.spec.ts:46`
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar-tag-row-menu.component.{ts,html}`, `sidebar-row-actions.service.ts:108-113`
- Test: `tag-form-dialog.component.spec.ts`, `sidebar-tag-row-menu.component.spec.ts` (if present), `sidebar-row-actions.service.spec.ts`

**Interfaces:**
- Consumes: `TagHierarchy`, `tagLabel`, `tagsInTreeOrder` (Task 4).

- [ ] **Step 1: Write the failing dialog specs**

In `tag-form-dialog.component.spec.ts`, replace `'offers the root tags except itself as parents'` and `'locks the parent for a tag that has children and sends null'` with:

```ts
  const optionTexts = (fixture: ReturnType<typeof mount>) =>
    [...(fixture.nativeElement as HTMLElement).querySelectorAll('select#tag-parent option')].map(
      (option) => option.textContent?.trim(),
    );

  it('offers every tag with room below it, in tree order, by path', () => {
    const fixture = mount(createAtTop, [
      tagOf(1, 'Reading', null),
      tagOf(2, 'Long reads', 1),
      tagOf(3, 'Essays', 2),
    ]);

    expect(optionTexts(fixture)).toEqual(['dialog.tagForm.noParent', 'Reading', 'Reading / Long reads']);
  });

  it('offers a tag with children only the parents its subtree still fits under', () => {
    const fixture = mount(editing(tagOf(4, 'Books', null)), [
      tagOf(1, 'Reading', null),
      tagOf(2, 'Long reads', 1),
      tagOf(4, 'Books', null),
      tagOf(5, 'Novels', 4),
    ]);

    expect(optionTexts(fixture)).toEqual(['dialog.tagForm.noParent', 'Reading']);
  });

  it('locks the parent with a hint when the subtree already uses every level', () => {
    const fixture = mount(editing(tagOf(1, 'Reading', null)), [
      tagOf(1, 'Reading', null),
      tagOf(2, 'Long reads', 1),
      tagOf(3, 'Essays', 2),
    ]);

    const select = (fixture.nativeElement as HTMLElement).querySelector(
      'select#tag-parent',
    ) as HTMLSelectElement;
    expect(select.disabled).toBe(true);
    expect((fixture.nativeElement as HTMLElement).textContent).toContain(
      'dialog.tagForm.parentLockedByDepth',
    );
  });
```

If the Transloco testing provider renders real strings rather than keys, match the strings it renders, as the other tests in this spec do. Rename the 422 fixtures' message to `'A tag can sit at most 2 levels below the top.'`.

- [ ] **Step 2: Run to see them fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/feeds/manage/tag-form-dialog.component.spec.ts`
Expected: FAIL.

- [ ] **Step 3: Options by the rule**

In `tag-form-dialog.component.ts`, replace `parentOptions` and `hasChildren`:

```ts
  private readonly hierarchy = computed(() => new TagHierarchy(this.tagsStore.tags()));
  readonly parentOptions = computed(() => {
    const tags = this.tagsStore.tags();
    const hierarchy = this.hierarchy();
    return tagsInTreeOrder(tags)
      .filter((tag) => hierarchy.mayPlaceUnder(this.editedTag?.id ?? null, tag.id))
      .map((tag) => ({ id: tag.id, label: tagLabel(tag, tags) }));
  });
  readonly parentLocked = computed(() => this.parentOptions().length === 0);
```

In `submit`, `parentId: this.hasChildren() ? null : this.parentId()` becomes `parentId: this.parentLocked() ? null : this.parentId()`.

`mayPlaceUnder(editedId, editedId)` is false, so the tag never offers itself. For a create (`null`), every tag below the limit is offered.

In the template, use `parentLocked()` for `[disabled]` and the hint. The hint key becomes `dialog.tagForm.parentLockedByDepth`. The option loop becomes:

```html
          @for (option of parentOptions(); track option.id) {
            <option [value]="option.id" [selected]="parentId() === option.id">{{ option.label }}</option>
          }
```

In both i18n files, replace `parentLockedHasChildren` with:
- en: `"parentLockedByDepth": "Its child tags already use every level, so this tag stays at the top."`
- de: `"parentLockedByDepth": "Seine untergeordneten Tags belegen schon alle Ebenen, darum bleibt dieser Tag oben."`

Delete `rootTags` from `tags.store.ts` and its assertion from `tags.store.spec.ts` (`rootsOf` stays; others use it).

- [ ] **Step 4: "Create tag here" while there is room below**

The row menu (`sidebar-tag-row-menu.component.html:11`) and the phone sheet (`sidebar-row-actions.service.ts:109`) offer "Create tag here" only on a root. Make both offer it when `new TagHierarchy(tags).mayPlaceUnder(null, tag.id)`:
- In the menu component, add a `computed` `roomBelow` from its injected `TagsStore` (inject it if the component doesn't yet) and the `tag` input. Change the `@if` to `@if (roomBelow())`.
- In `createHereActions(tag)`, guard with `if (!new TagHierarchy(this.tags.tags()).mayPlaceUnder(null, tag.id)) return [];`.

Add a spec per surface: a child gets "Create tag here", a grandchild doesn't.

- [ ] **Step 5: Run the touched specs**

Run: `docker compose exec -T frontend npx jest src/app/reader/feeds/manage src/app/reader/shell/sidebar src/app/reader/state/tags.store.spec.ts`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/feeds/manage frontend/src/app/reader/shell/sidebar frontend/src/app/reader/state frontend/public/i18n
git commit -m "feat(#1790): the tag form and menus offer every level with room"
```

---

### Task 9: Full gates and the live check

**Files:** none new. Fixes land in the task that owns the file.

- [ ] **Step 1: Backend gates, both legs**

From `backend/`: `composer check && composer md && composer test:parallel`, then from the repo root `docker compose exec php composer test`.
Expected: green on SQLite and MySQL.

- [ ] **Step 2: Mutation gate**

From `backend/`: `composer infection:diff` (commit first; it ignores untracked files).
Expected: MSI at or above `minMsi`. Kill escaped mutants in `TagTree`, `TagLineageDql` (range bounds) and `BackupTally::depthUnder` with tests; never lower the threshold.

- [ ] **Step 3: Frontend gates**

`docker compose exec -T frontend npm run check`, then `docker compose exec -T frontend npm run build`.
Expected: green.

- [ ] **Step 4: Live check on :4200 (containers current, see memory)**

On the Docker stack (from the checkout that ran `docker compose up`):
1. Create `Reading › Long reads › Essays` through the tag form. The parent select lists `Reading / Long reads`.
2. Sidebar: open all three. Check the grandchild box's indent and density at desktop width and in the Mobile viewport (390 wide), in normal and Organise mode. The unread count on `Reading` includes the grandchild's feeds.
3. Drag `Tech` onto the middle of `Long reads`: it nests. Drag a tag with children onto the middle of `Long reads`: no nest cue.
4. Delete `Long reads`: `Essays` appears under `Reading`, after its other children.
5. /settings/organise: three levels nest. Filter so the middle hides: the grandchild sits under the root.
6. Export a backup and restore it on the same account: the tree survives. Export OPML: three nested outlines.
7. Scan today's dev log (`ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 100 | jq .`) for errors or deprecations.

If the grandchild's look needs more than the inherited `.tag-child` rule, stop and show the user screenshots before changing styles.

- [ ] **Step 5: Push and open the PR**

```bash
git push -u origin feature/1790-two-level-tag-nesting
```

PR into `develop`, body ends with `Closes #1790`. Mention the OPML-import follow-up and the `EXPLAIN` result from Task 2.
