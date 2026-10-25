# OPML Import Keeps Three Tag Levels — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An OPML file nested A › B › C imports as A › B › C (issue #1800), and every bulk writer nests by the same placement rule the tag editor uses.

**Architecture:** `BulkSubscribeItemModel` carries a tag *path* (outermost first) instead of `tagName` + `parentTagName`. A new `BulkTagger` service takes the tag work out of `BulkSubscriber` and walks the path innermost-out, placing each tag under the next by the #1790 rule, checked against a per-batch `BulkSubscribeTagTree` that knows the stored tags and the ones this batch created but has not flushed. `BulkSubscriber` holds `TagStructureLock` itself, so `OpmlImporter` no longer does.

**Tech Stack:** Symfony 7.4, PHP 8.4, Doctrine ORM, PHPUnit 12 (`DbTestCase`, SQLite natively, MySQL in Docker).

**Spec:** GitHub issue #1800 (`gh issue view 1800`); the rule comes from #1790, `docs/superpowers/plans/2026-10-25-two-level-tag-nesting.md`.

## Global Constraints

- Placement rule: T under P iff P ≠ T, P is not in T's subtree, and depth(P) + 1 + height(T) ≤ `Tag::MAX_DEPTH` (= 2).
- The rule decides **silently** in a bulk import — a refused placement never fails the import, it just stops nesting that path.
- Outlines deeper than the limit keep their **outermost** `Tag::MAX_DEPTH + 1` levels; the feed takes the deepest kept level as its tag.
- A tag the user already placed keeps its parent; the walk continues upward only while the file agrees with it (T's stored parent *is* the next name in the path).
- Tag names are clipped to 100 characters (`mb_substr`) before lookup, as today.
- CLAUDE.md Clean Code rules apply: `final readonly` services, per-call objects in `Pass/`, no new comments unless they clear the bar, PHPMD-clean touched files, phptramp under its thresholds.
- Commit format `fix(#1800): …`, no attribution lines.

## File Structure

| File | Change |
|---|---|
| `backend/src/Service/Tag/Pass/TagTree.php` | add `canPlace()` and static `fits()`: the rule in one place |
| `backend/src/Service/Tag/TagEditor.php` | `assertFitsUnder` uses `TagTree::fits()` |
| `backend/src/Service/Subscription/Pass/BulkSubscribeTagTree.php` | **create** — mutable per-batch tree keyed by `spl_object_id` |
| `backend/src/Service/Subscription/Pass/BulkSubscribeBatch.php` | holds the tag tree; tracks created tags; drops `rememberParent`/`isParent` |
| `backend/src/Service/Subscription/Model/BulkSubscribeItemModel.php` | `tagName` + `parentTagName` → `list<string> $tagPath` |
| `backend/src/Service/Subscription/BulkTagger.php` | **create** — attaches and nests an item's tags |
| `backend/src/Service/Subscription/BulkSubscriber.php` | delegates to `BulkTagger`, holds `TagStructureLock` |
| `backend/src/Service/Opml/OpmlImporter.php` | collects the full group path; no lock |
| `backend/src/Service/Catalog/CatalogSubscriber.php` | `tagPath: [category name]` |
| `backend/tests/Service/Tag/Pass/TagTreeTest.php` | `canPlace` cases (create if absent) |
| `backend/tests/Service/Subscription/BulkSubscriberTest.php` | migrate to `tagPath`, new nesting + lock tests |
| `backend/tests/Service/Opml/OpmlImporterTest.php` | rewrite one-level tests to the new rule; the four "done when" cases |

---

### Task 1: The placement rule lives on `TagTree`

**Files:**
- Modify: `backend/src/Service/Tag/Pass/TagTree.php`
- Modify: `backend/src/Service/Tag/TagEditor.php` (`assertFitsUnder`)
- Test: `backend/tests/Service/Tag/Pass/TagTreeTest.php` (check `ls backend/tests/Service/Tag/Pass/`; extend if it exists)

**Interfaces:**
- Produces: `TagTree::canPlace(int $tagId, int $parentId): bool`; `TagTree::fits(int $parentDepth, int $height): bool` (static).

- [ ] **Step 1: Write the failing tests**

```php
#[DataProvider('placements')]
public function testCanPlaceFollowsTheNestingRule(int $tagId, int $parentId, bool $expected): void
{
    // 1 › 2 › 3, and 4 › 5 beside it
    $tree = new TagTree([1 => null, 2 => 1, 3 => 2, 4 => null, 5 => 4]);

    self::assertSame($expected, $tree->canPlace($tagId, $parentId));
}

/** @return iterable<string, array{int, int, bool}> */
public static function placements(): iterable
{
    yield 'under itself' => [4, 4, false];
    yield 'under its own child' => [4, 5, false];
    yield 'under its own grandchild' => [1, 3, false];
    yield 'a leaf under a grandchild is too deep' => [5, 3, false];
    yield 'a leaf under a child fits' => [5, 2, true];
    yield 'a parent under a root fits' => [4, 1, true];
    yield 'a parent under a child is too deep' => [4, 2, false];
}

public function testFitsCountsTheParentsDepthPlusOnePlusTheHeight(): void
{
    self::assertTrue(TagTree::fits(0, 1));
    self::assertTrue(TagTree::fits(1, 0));
    self::assertFalse(TagTree::fits(1, 1));
    self::assertFalse(TagTree::fits(0, 2));
}
```

- [ ] **Step 2: Run, expect FAIL** (`canPlace`/`fits` undefined)

Run: `php bin/phpunit tests/Service/Tag/Pass/TagTreeTest.php`

- [ ] **Step 3: Implement**

In `TagTree` (import `App\Entity\Tag`, already imported):

```php
public static function fits(int $parentDepth, int $height): bool
{
    return $parentDepth + 1 + $height <= Tag::MAX_DEPTH;
}

public function canPlace(int $tagId, int $parentId): bool
{
    return !$this->isWithin($parentId, $tagId) && self::fits($this->depthOf($parentId), $this->heightOf($tagId));
}
```

In `TagEditor::assertFitsUnder`:

```php
if (!TagTree::fits($tree->depthOf($parent->requireId()), $height)) {
    throw InvalidTagParentException::tooDeep();
}
```

- [ ] **Step 4: Run `TagTreeTest` and `tests/Service/Tag/TagEditorTest.php`, expect PASS**

- [ ] **Step 5: Commit** — `refactor(#1800): the tag placement rule lives on TagTree`

---

### Task 2: Tag paths, `BulkTagger` and the batch tag tree

**Files:**
- Create: `backend/src/Service/Subscription/Pass/BulkSubscribeTagTree.php`
- Create: `backend/src/Service/Subscription/BulkTagger.php`
- Modify: `BulkSubscribeItemModel.php`, `BulkSubscribeBatch.php`, `BulkSubscriber.php`, `CatalogSubscriber.php`, `OpmlImporter.php` (path collection only; lock moves in Task 3)
- Test: `backend/tests/Service/Subscription/BulkSubscriberTest.php`, `backend/tests/Service/Opml/OpmlImporterTest.php`

**Interfaces:**
- Consumes: `TagTree::canPlace`, `TagTree::fits` (Task 1).
- Produces: `BulkSubscribeItemModel(string $feedUrl, ?string $feedTitle = null, array $tagPath = [], ?TagStyleModel $tagStyle = null, string $sourceFormat = SourceFormat::XML)` with `@param list<string> $tagPath` outermost first; `BulkTagger::tag(BulkSubscribeBatch $batch, Subscription $subscription, BulkSubscribeItemModel $item): void`.

- [ ] **Step 1: Migrate the existing tests to `tagPath`**

In `BulkSubscriberTest`, every positional third argument `'X'` becomes `['X']` (`null` becomes `[]`); the two `parentTagName: 'Parent'` tests become `tagPath: ['Parent', 'Child']` (drop the third positional argument there). The `str_repeat('ä', 120)` and overlong-name tests wrap their name in `[…]`.

- [ ] **Step 2: Write the failing `BulkSubscriberTest` cases**

```php
public function testAThreeLevelPathNestsEachTagUnderTheNext(): void
{
    $user = $this->user('three-levels@example.com');

    $result = $this->subscriber()->subscribeAll($user, [
        new BulkSubscribeItemModel('https://three.example.com/rss.xml', 'Three', ['A', 'B', 'C']),
    ]);

    self::assertSame(['C', 'B', 'A'], $this->namesOf($result->tagsCreated));
    $c = $result->tagsCreated[0];
    self::assertSame('B', $c->getParent()?->getName());
    self::assertSame('A', $c->getParent()?->getParent()?->getName());
}

public function testAPathDeeperThanTheLimitKeepsItsOutermostLevels(): void
{
    $user = $this->user('clamped@example.com');

    $result = $this->subscriber()->subscribeAll($user, [
        new BulkSubscribeItemModel('https://clamped.example.com/rss.xml', 'Clamped', ['A', 'B', 'C', 'D']),
    ]);

    self::assertSame(['C', 'B', 'A'], $this->namesOf($result->tagsCreated));
    self::assertSame(['C'], $this->namesOf($this->subscriptionTo($user, 'https://clamped.example.com/rss.xml')->getTags()->toArray()));
}

public function testATagNestsUnderAnExistingChildTag(): void
{
    $user = $this->user('under-child@example.com');
    $tech = new Tag($user, 'Tech');
    $web = new Tag($user, 'Web');
    $web->setParent($tech);
    $this->entityManager->persist($tech);
    $this->entityManager->persist($web);
    $this->entityManager->flush();

    $result = $this->subscriber()->subscribeAll($user, [
        new BulkSubscribeItemModel('https://frontend.example.com/rss.xml', 'Frontend', ['Web', 'Frontend']),
    ]);

    self::assertSame(['Frontend'], $this->namesOf($result->tagsCreated));
    self::assertSame($web, $result->tagsCreated[0]->getParent());
}

public function testTagsCreatedEarlierInTheBatchCountTowardsTheDepth(): void
{
    $user = $this->user('batch-depth@example.com');

    $this->subscriber()->subscribeAll($user, [
        new BulkSubscribeItemModel('https://first.example.com/rss.xml', 'First', ['A', 'B', 'C']),
        new BulkSubscribeItemModel('https://second.example.com/rss.xml', 'Second', ['X', 'A']),
    ]);

    self::assertNull($this->storedTag($user, 'A')->getParent());
    self::assertNull($this->entityManager->getRepository(Tag::class)->findOneBy(['user' => $user, 'name' => 'X']));
}

public function testAPathThatWouldLoopStopsNesting(): void
{
    $user = $this->user('loop@example.com');

    $this->subscriber()->subscribeAll($user, [
        new BulkSubscribeItemModel('https://ab.example.com/rss.xml', 'AB', ['A', 'B']),
        new BulkSubscribeItemModel('https://ba.example.com/rss.xml', 'BA', ['B', 'A']),
    ]);

    self::assertNull($this->storedTag($user, 'A')->getParent());
    self::assertSame('A', $this->storedTag($user, 'B')->getParent()?->getName());
}
```

Helpers at the bottom of the test class:

```php
/** @param array<array-key, Tag> $tags @return list<string> */
private function namesOf(array $tags): array
{
    return array_values(array_map(static fn (Tag $tag): string => $tag->getName(), $tags));
}

private function storedTag(User $user, string $name): Tag
{
    $tag = $this->entityManager->getRepository(Tag::class)->findOneBy(['user' => $user, 'name' => $name]);
    self::assertInstanceOf(Tag::class, $tag);

    return $tag;
}
```

Replace the two existing inline `array_map(static fn (Tag $tag) …)` name lists with `namesOf`.

- [ ] **Step 3: Rewrite the `OpmlImporterTest` cases the new rule changes, and add the issue's cases**

Each of these encoded the one-level rule and now asserts the opposite; rewrite rather than delete:

- `testADeeperGroupKeepsTheInnermostTagUnderTheOutermostGroup` → `testAThreeLevelFileImportsEveryLevel`: same OPML; assert C › B › A and the feed tagged `['C']`.
- `testAnExistingParentTagKeepsItsPlaceWhenAFileWouldNestIt` → `testATagWhoseSubtreeIsFullKeepsItsPlace`: seed Long reads › Essays › Deep (height 2); file `Reading › Long reads`; Long reads stays a root, no Reading.
- `testATagNestedEarlierInTheFileIsNotMadeAParentLater` → `testATagNestedEarlierInTheFileTakesAChildLater`: same OPML; assert C under B under A.
- `testATagGivenAChildEarlierInTheFileIsNotNestedLater` → `testATagWithAFullSubtreeEarlierInTheFileIsNotNestedLater`: first `X › Y › Z`, then `W › X`; X stays a root, no W.

Add:

```php
public function testADeeperFileKeepsItsOutermostThreeLevels(): void
{
    $user = $this->user('opml-clamped@example.com');
    $opml = $this->opml(
        '<outline text="A"><outline text="B"><outline text="C"><outline text="D">'
        . '<outline type="rss" text="F" xmlUrl="https://clamped.example.com/feed.xml"/>'
        . '</outline></outline></outline></outline>',
    );

    $this->importer()->import($user, $opml);

    self::assertSame('B', $this->tagNamed($user, 'C')->getParent()?->getName());
    self::assertSame(['C'], $this->tagNamesOf($this->subscriptionsOf($user)[0]));
    self::assertNull($this->entityManager->getRepository(Tag::class)->findOneBy(['user' => $user, 'name' => 'D']));
}

public function testReimportingIntoExistingTagsCreatesNoneAndKeepsTheTree(): void
{
    $user = $this->user('opml-reimport@example.com');
    $opml = fn (string $host): string => $this->opml(
        '<outline text="A"><outline text="B"><outline text="C">'
        . sprintf('<outline type="rss" text="F" xmlUrl="https://%s.example.com/feed.xml"/>', $host)
        . '</outline></outline></outline>',
    );
    $this->importer()->import($user, $opml('first-import'));

    $result = $this->importer()->import($user, $opml('second-import'));

    self::assertSame(1, $result->imported);
    self::assertCount(3, $this->entityManager->getRepository(Tag::class)->findBy(['user' => $user]));
    self::assertSame('B', $this->tagNamed($user, 'C')->getParent()?->getName());
    self::assertSame('A', $this->tagNamed($user, 'B')->getParent()?->getName());
    self::assertSame(['C'], $this->tagNamesOf($this->subscriptionsOf($user)[1]));
}
```

The importer's result model has no `tagsCreated`, so the tag count is the assertion.

The rewrites and the file-level twin of "nesting under an existing child tag":

```php
public function testAThreeLevelFileImportsEveryLevel(): void
{
    $user = $this->user('opml-deep@example.com');
    $opml = $this->opml(
        '<outline text="A"><outline text="B"><outline text="C">'
        . '<outline type="rss" text="F" xmlUrl="https://deep.example.com/feed.xml"/>'
        . '</outline></outline></outline>',
    );

    $this->importer()->import($user, $opml);

    self::assertSame('B', $this->tagNamed($user, 'C')->getParent()?->getName());
    self::assertSame('A', $this->tagNamed($user, 'B')->getParent()?->getName());
    self::assertSame(['C'], $this->tagNamesOf($this->subscriptionsOf($user)[0]));
}

public function testATagWhoseSubtreeIsFullKeepsItsPlace(): void
{
    $user = $this->user('opml-conflict@example.com');
    $longReads = $this->storedTag($user, 'Long reads', null);
    $this->storedTag($user, 'Deep', $this->storedTag($user, 'Essays', $longReads));
    $opml = $this->opml(
        '<outline text="Reading"><outline text="Long reads">'
        . '<outline type="rss" text="F" xmlUrl="https://conflict.example.com/feed.xml"/>'
        . '</outline></outline>',
    );

    $result = $this->importer()->import($user, $opml);

    self::assertSame(1, $result->imported);
    self::assertNull($this->tagNamed($user, 'Long reads')->getParent());
    self::assertNull($this->entityManager->getRepository(Tag::class)->findOneBy([
        'user' => $user,
        'name' => 'Reading',
    ]));
}

public function testATagNestedEarlierInTheFileTakesAChildLater(): void
{
    $user = $this->user('opml-child-as-parent@example.com');
    $opml = $this->opml(
        '<outline text="A"><outline text="B">'
        . '<outline type="rss" text="F1" xmlUrl="https://child-parent-1.example.com/feed.xml"/>'
        . '</outline></outline>'
        . '<outline text="B"><outline text="C">'
        . '<outline type="rss" text="F2" xmlUrl="https://child-parent-2.example.com/feed.xml"/>'
        . '</outline></outline>',
    );

    $result = $this->importer()->import($user, $opml);

    self::assertSame(2, $result->imported);
    self::assertSame('A', $this->tagNamed($user, 'B')->getParent()?->getName());
    self::assertSame('B', $this->tagNamed($user, 'C')->getParent()?->getName());
}

public function testATagWithAFullSubtreeEarlierInTheFileIsNotNestedLater(): void
{
    $user = $this->user('opml-parent-as-child@example.com');
    $opml = $this->opml(
        '<outline text="X"><outline text="Y"><outline text="Z">'
        . '<outline type="rss" text="F1" xmlUrl="https://parent-child-1.example.com/feed.xml"/>'
        . '</outline></outline></outline>'
        . '<outline text="W"><outline text="X">'
        . '<outline type="rss" text="F2" xmlUrl="https://parent-child-2.example.com/feed.xml"/>'
        . '</outline></outline>',
    );

    $result = $this->importer()->import($user, $opml);

    self::assertSame(2, $result->imported);
    self::assertNull($this->tagNamed($user, 'X')->getParent());
    self::assertNull($this->entityManager->getRepository(Tag::class)->findOneBy(['user' => $user, 'name' => 'W']));
}

public function testAFileNestsUnderAnExistingChildTag(): void
{
    $user = $this->user('opml-under-child@example.com');
    $this->storedTag($user, 'Web', $this->storedTag($user, 'Tech', null));
    $opml = $this->opml(
        '<outline text="Web"><outline text="Frontend">'
        . '<outline type="rss" text="F" xmlUrl="https://frontend.example.com/feed.xml"/>'
        . '</outline></outline>',
    );

    $this->importer()->import($user, $opml);

    self::assertSame('Web', $this->tagNamed($user, 'Frontend')->getParent()?->getName());
    self::assertSame('Tech', $this->tagNamed($user, 'Web')->getParent()?->getName());
}

private function storedTag(User $user, string $name, ?Tag $parent): Tag
{
    $tag = new Tag($user, $name);
    $tag->setParent($parent);
    $this->entityManager->persist($tag);
    $this->entityManager->flush();

    return $tag;
}
```

- [ ] **Step 4: Run both test files, expect FAIL** (constructor signature / new behaviour)

Run: `php bin/phpunit tests/Service/Subscription/BulkSubscriberTest.php tests/Service/Opml/OpmlImporterTest.php`

- [ ] **Step 5: Implement the model and the tree pass**

`BulkSubscribeItemModel`:

```php
/**
 * @param list<string> $tagPath group names, outermost first; the feed is tagged with the innermost
 */
public function __construct(
    public string $feedUrl,
    public ?string $feedTitle = null,
    public array $tagPath = [],
    public ?TagStyleModel $tagStyle = null,
    public string $sourceFormat = SourceFormat::XML,
) {
}
```

(keep the class docblock about `$feedTitle`.) `CatalogSubscriber`: `tagPath: [$feed->getCategory()->getName()],`. `SocialImporter` needs no change.

`Pass/BulkSubscribeTagTree.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription\Pass;

use App\Entity\Tag;
use App\Service\Tag\Pass\TagTree;

/** The user's tags as this batch leaves them, keyed by spl_object_id: a tag created here has no id until the flush. */
final class BulkSubscribeTagTree
{
    /** @var array<int, int|null> parent key by tag key */
    private array $parentKeys = [];

    /** @param list<Tag> $storedTags */
    public function __construct(array $storedTags)
    {
        foreach ($storedTags as $tag) {
            $this->parentKeys[spl_object_id($tag)] = self::keyOf($tag->getParent());
        }
    }

    public function add(Tag $tag): void
    {
        $this->parentKeys[spl_object_id($tag)] = null;
    }

    public function nest(Tag $tag, Tag $parent): void
    {
        $this->parentKeys[spl_object_id($tag)] = spl_object_id($parent);
    }

    public function fitsUnder(Tag $tag, Tag $parent): bool
    {
        return $this->tree()->canPlace(spl_object_id($tag), spl_object_id($parent));
    }

    public function fitsUnderANewTag(Tag $tag): bool
    {
        return TagTree::fits(0, $this->tree()->heightOf(spl_object_id($tag)));
    }

    private function tree(): TagTree
    {
        return new TagTree($this->parentKeys);
    }

    private static function keyOf(?Tag $tag): ?int
    {
        return $tag === null ? null : spl_object_id($tag);
    }
}
```

`BulkSubscribeBatch`: add constructor parameter `public readonly BulkSubscribeTagTree $tagTree` (last), delete `$parentVerdicts`, `rememberParent()` and `isParent()`. Add

```php
/** @var list<Tag> */
private array $tagsCreated = [];

public function rememberNewTag(string $name, Tag $tag): void
{
    $this->rememberTag($name, $tag);
    $this->tagTree->add($tag);
    $this->tagsCreated[] = $tag;
}
```

`recordSubscribed(string $url, Subscription $subscription): void` loses its `$tagsCreated` parameter and its `tagsCreated:` argument; `result()` returns `$this->result->with(tagsCreated: $this->tagsCreated)`.

- [ ] **Step 6: Implement `BulkTagger`**

```php
<?php

declare(strict_types=1);

namespace App\Service\Subscription;

use App\Entity\Subscription;
use App\Entity\Tag;
use App\Repository\SubscriptionTagRepository;
use App\Repository\TagRepository;
use App\Service\Subscription\Model\BulkSubscribeItemModel;
use App\Service\Subscription\Model\TagStyleModel;
use App\Service\Subscription\Pass\BulkSubscribeBatch;
use App\Service\Subscription\Pass\BulkSubscribePositions;
use App\Service\Tag\Factory\TagFactory;
use App\Service\Tag\Model\TagDetailsModel;
use Doctrine\ORM\EntityManagerInterface;

/** Tags a bulk subscription with its item's path; the placement rule decides silently, so an import never fails on it. */
final readonly class BulkTagger
{
    private const int MAX_TAG_NAME = 100;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private TagRepository $tags,
        private SubscriptionTagRepository $subscriptionTags,
        private TagFactory $tagFactory,
    ) {
    }

    public function tag(BulkSubscribeBatch $batch, Subscription $subscription, BulkSubscribeItemModel $item): void
    {
        $outerNames = $this->keptPath($item->tagPath);
        $innermostName = array_pop($outerNames);
        if ($innermostName === null) {
            return;
        }
        $tag = $this->existingTag($batch, $innermostName) ?? $this->newTag($batch, $innermostName, $item->tagStyle);
        $batch->rememberTag($innermostName, $tag);
        $this->joinTag($batch->positions, $subscription, $tag);
        $this->nestUnder($batch, $tag, array_reverse($outerNames));
    }

    /**
     * @param list<string> $path
     *
     * @return list<string>
     */
    private function keptPath(array $path): array
    {
        return array_map(
            static fn (string $name): string => mb_substr($name, 0, self::MAX_TAG_NAME),
            array_slice($path, 0, Tag::MAX_DEPTH + 1),
        );
    }

    /** @param list<string> $parentNames innermost first */
    private function nestUnder(BulkSubscribeBatch $batch, Tag $tag, array $parentNames): void
    {
        foreach ($parentNames as $parentName) {
            $parent = $this->placedParent($batch, $tag, $parentName);
            if ($parent === null) {
                return;
            }
            $tag = $parent;
        }
    }

    /** The tag $tag now sits under, or null where the user's own arrangement or the placement rule ends the path. */
    private function placedParent(BulkSubscribeBatch $batch, Tag $tag, string $parentName): ?Tag
    {
        $existing = $this->existingTag($batch, $parentName);
        if ($tag->getParent() !== null) {
            return $tag->getParent() === $existing ? $existing : null;
        }
        if ($existing !== null) {
            $batch->rememberTag($parentName, $existing);

            return $batch->tagTree->fitsUnder($tag, $existing) ? $this->nest($batch, $tag, $existing) : null;
        }

        return $batch->tagTree->fitsUnderANewTag($tag)
            ? $this->nest($batch, $tag, $this->newTag($batch, $parentName, null))
            : null;
    }

    private function nest(BulkSubscribeBatch $batch, Tag $tag, Tag $parent): Tag
    {
        $tag->setParent($parent);
        $batch->tagTree->nest($tag, $parent);

        return $parent;
    }

    private function existingTag(BulkSubscribeBatch $batch, string $name): ?Tag
    {
        return $batch->tagNamed($name) ?? $this->tags->findOneByNameForUser($batch->user->requireId(), $name);
    }

    private function newTag(BulkSubscribeBatch $batch, string $name, ?TagStyleModel $style): Tag
    {
        $tag = $this->tagFactory->create(
            $batch->user,
            new TagDetailsModel($name, $style?->color, $style?->icon),
            $batch->positions->takeTagPosition(),
        );
        $this->entityManager->persist($tag);
        $batch->rememberNewTag($name, $tag);

        return $tag;
    }

    private function joinTag(BulkSubscribePositions $positions, Subscription $subscription, Tag $tag): void
    {
        $subscription->addTag($tag, $positions->takeFeedPositionIn(
            $tag,
            fn (): int => null === $tag->getId() ? 0 : $this->subscriptionTags->nextPositionForTag($tag),
        ));
    }
}
```

`newTag` remembers new tags and `placedParent` remembers found ones, so a later item naming them skips the lookup; `testATagTheBatchAlreadyKnowsIsNotLookedUpAgain` must stay green.

- [ ] **Step 7: Slim `BulkSubscriber`**

Remove `MAX_TAG_NAME`, `attachTag`, `nestUnder`, `existingTag`, `clippedTagName`, `isParent`, `persistNewTag`, `joinTag` and the `SubscriptionTagRepository`, `TagFactory` constructor parameters (and now-unused imports). Add `private BulkTagger $bulkTagger`. `open()` passes `new BulkSubscribeTagTree($this->tags->findForUser($userId))` as the batch's last argument. The end of `subscribeOne` becomes:

```php
$subscription = $this->persistSubscription($batch, $feed ?? $this->persistNewFeed($item));
$this->bulkTagger->tag($batch, $subscription, $item);
$batch->recordSubscribed($url, $subscription);
```

- [ ] **Step 8: `OpmlImporter` collects the whole path**

```php
/**
 * Depth-first; a feed carries every named group above it, outermost first.
 *
 * @param list<string> $groupPath
 *
 * @return list<BulkSubscribeItemModel>
 */
private function collectFeeds(\DOMElement $node, array $groupPath): array
{
    $items = [];
    foreach ($node->childNodes as $child) {
        if (!$child instanceof \DOMElement || $child->localName !== 'outline') {
            continue;
        }

        $xmlUrl = trim($child->getAttribute('xmlUrl'));
        if ($xmlUrl !== '') {
            $items[] = new BulkSubscribeItemModel(feedUrl: $xmlUrl, tagPath: $groupPath);
            continue;
        }

        $groupName = $this->groupName($child);
        $childPath = $groupName === null ? $groupPath : [...$groupPath, $groupName];
        foreach ($this->collectFeeds($child, $childPath) as $item) {
            $items[] = $item;
        }
    }

    return $items;
}
```

Call site: `$this->collectFeeds($body, [])`.

- [ ] **Step 9: Run both test files, plus `tests/Service/Catalog` and `tests/Service/Social`, expect PASS**

- [ ] **Step 10: Commit** — `fix(#1800): OPML import keeps up to three tag levels`

---

### Task 3: `BulkSubscriber` holds the tag structure lock

**Files:**
- Modify: `backend/src/Service/Subscription/BulkSubscriber.php`, `backend/src/Service/Opml/OpmlImporter.php`
- Test: move `testAnImportHoldsTheTagStructureLockWhileItNestsTags` from `OpmlImporterTest` to `BulkSubscriberTest` as `testABatchHoldsTheTagStructureLock` (it calls `subscribeAll` with one item `['A', 'B']`; same SQLite skip, same `queriesMatching('for update')` count of 1). Keep one OPML-level check that an import still takes exactly one lock (the old test, unchanged) — it guards against a second, nested hold.

- [ ] **Step 1: Write the moved test; run, expect FAIL on MySQL** (`docker compose exec php bin/phpunit tests/Service/Subscription/BulkSubscriberTest.php --filter Lock`)

- [ ] **Step 2: Implement**

`BulkSubscriber` gains `private TagStructureLock $tagStructureLock` and:

```php
/**
 * @param iterable<BulkSubscribeItemModel> $items
 */
public function subscribeAll(User $user, iterable $items): BulkSubscribeResultModel
{
    return $this->tagStructureLock->hold(
        $user,
        fn (): BulkSubscribeResultModel => $this->subscribeAllHeld($user, $items),
    );
}

/** @param iterable<BulkSubscribeItemModel> $items */
private function subscribeAllHeld(User $user, iterable $items): BulkSubscribeResultModel
{
    $batch = $this->open($user);
    foreach ($items as $item) {
        $this->subscribeOne($batch, $item);
    }
    $this->entityManager->flush();

    return $batch->result();
}
```

The stored tags are read in `open()`, inside the hold — that is what makes the tree trustworthy. `OpmlImporter` drops `TagStructureLock` from its constructor and calls `$this->subscriber->subscribeAll($user, $this->collectFeeds($body, []))` directly.

- [ ] **Step 3: Run `BulkSubscriberTest`, `OpmlImporterTest`, `tests/Service/Catalog`, `tests/Service/Social` on both legs, expect PASS**

- [ ] **Step 4: Commit** — `fix(#1800): every bulk subscriber holds the tag structure lock`

---

### Task 4: Gates

- [ ] `composer check`, `composer md` (BulkSubscriber, BulkTagger, OpmlImporter, BulkSubscribeBatch, BulkSubscribeTagTree, TagTree, TagEditor PHPMD-clean), PhpStorm `lint_files` on the changed PHP.
- [ ] `composer test:parallel` natively and `docker compose exec php composer test`.
- [ ] `composer infection:diff` — kill any escaped mutant in the touched lines with a test, not a lowered `minMsi`.
- [ ] Scan today's dev log.
- [ ] Live check on the Docker stack: export OPML from an account with A › B › C, import into a fresh test account, see A › B › C in the sidebar.
