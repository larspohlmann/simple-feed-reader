# Sidebar group lists (Tags / Feeds) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The sidebar's **Tags** and **Feeds** section headers behave like the Bluesky/Mastodon headers: they show an unread count and open a combined list on click.

**Architecture:** One new backend scope, `FeedGroup` (`tags` | `feeds`), with one DQL definition (`FeedGroupDql`). That definition is applied in four places, each mirroring how `NetworkScope` is applied today: the entry list, the unread counts, mark-read and refresh. The frontend gets a new `Selection` kind `'group'` that carries `group: 'tags' | 'feeds'`, which reaches every switch that handles `'network'`. The sidebar's Tags/Feeds headers change from a collapse button to a link with a count, plus the existing chevron.

**Tech Stack:** Symfony 7.4 / Doctrine ORM (PHP 8.4), Angular 20 (signals, standalone), Jest, PHPUnit 12.

**Spec:** GitHub issue #1735 (decided design, from the grilling session).

## Global Constraints

- **Feeds** = subscriptions with no tag **and** `feed.network IS NULL`. That is exactly what the sidebar shows under "Feeds" (`untaggedSubscriptions` minus `isSocialFeed`; backend `SubscriptionJson::social()` is non-null exactly when `feed.network` is non-null).
- **Tags** = subscriptions carrying at least one tag, social ones included. Each entry is listed once and counted once; never sum the per-tag counts.
- Both lists are **narrowed**: they ignore `includeInAllItems`.
- Wire vocabulary, everywhere: `group=tags|feeds`. That covers `GET /api/entries?group=`, `POST /api/refresh?group=`, the mark-read body `{"scope":"group","group":"tags","until":…}`, `/api/subscriptions/counts` → `unreadTotals.groups: {"tags": n, "feeds": n}`, and the frontend URL `/?group=tags`.
- An unknown `group` value is a 422 `validation_error` with `{"group": ["Unknown group. Use one of: tags, feeds."]}`. That message comes from `RequestValueParsing`.
- Header UX: the label is a link and the count shows only when > 0. Only the chevron collapses. The header is highlighted while its list is open. The list title is "Tags" / "Feeds" (`reader.tags` / `reader.feeds`), with no heading icon.
- Keep a native iOS client viable: plain query params / JSON only.
- House rules from `CLAUDE.md` apply: `final readonly`, no abbreviations, no comment unless a reader would get the code wrong without it, `Response::HTTP_*` constants, PHPMD-clean touched files, `type(#1735): …` commit messages, and no attribution lines.

---

## File map

**Backend, new**
- `backend/src/Enum/FeedGroup.php`: the enum plus request parsing.
- `backend/src/Repository/FeedGroupDql.php`: the one predicate definition.
- `backend/tests/Controller/Api/EntryFeedGroupScopeTest.php`: functional tests for list, mark-read and validation.
- `backend/tests/Repository/FeedRepositoryFeedGroupScopeTest.php`: the refresh due-feed scope.

**Backend, modified**
- `src/Repository/EntryAliases.php` (+`groupFeed` alias), `EntryQuery.php` (+`feedGroup`), `EntryScopePredicates.php` (+`inFeedGroup`), `src/Controller/Api/EntryController.php` (+`group` param)
- `src/Repository/UnreadCountRepository.php` (+`perFeedGroup`), `src/Service/Subscription/Model/UnreadTotalsModel.php` (+`feedGroups`), `src/Service/Subscription/SubscriptionTallyReader.php`, `src/Http/UnreadTotalsJson.php` (+`groups`)
- `src/Service/Reading/Model/ReadScopeKind.php` (+`Group`), `ReadScopeModel.php`, `src/Dto/Entry/MarkReadRequest.php`, `src/Repository/SubscriptionScopeRepository.php` (+`findForUserInFeedGroup`), `src/Service/Reading/MarkReadService.php`
- `src/Repository/DueFeedCriteria.php`, `src/Repository/FeedRepository.php`, `src/Service/Refresh/Model/RefreshRequestModel.php`, `src/Service/Refresh/RefreshRunner/RefreshRunner.php`, `src/Service/Refresh/RefreshRunStore.php`, `src/Service/Refresh/UserRefreshScope.php`, `src/Controller/Api/RefreshController.php`
- Tests: `EntryQueryTest`, `UnreadCountRepositoryTest`, `UnreadTotalsJsonTest`, `SubscriptionCountsJsonTest`, `SubscriptionJsonListTest`, `SubscriptionScopeRepositoryTest`, `RefreshRunStoreTest`, `UserRefreshScopeTest`, `RefreshControllerTest`

**Frontend, new**
- `frontend/src/app/core/feed-group.ts`: `FeedGroupName` plus its guard.

**Frontend, modified**
- `reader/models.ts`, `reader/query/query.ts`, `reader/reader-api.ts`, `reader/state/unread-totals.ts`, `reader/state/subscriptions.store.ts`, `reader/state/subscription-derivations.ts`, `reader/scroll/list-scroll-memory.ts`, `reader/shell/refresh-actions.service.ts`, `reader/shell/mark-read-actions.service.ts`, `reader/shell/list-heading.service.ts`
- `reader/shell/sidebar/sidebar.component.{ts,html,scss}`, `sidebar-social-section.component.scss`, `_sidebar-row.scss`, `reader/shell/reader-shell.component.html`
- `frontend/e2e/support/reader.ts` (`unreadTotalsOf` gains `groups`)
- Specs: `query.spec.ts`, `unread-totals.spec.ts`, `list-scroll-memory.spec.ts`, `list-heading.service.spec.ts`, `mark-read-actions.service.spec.ts`, `sidebar.component.spec.ts`, and any spec or fixture that builds an `UnreadTotals`/`UnreadTotalsDto` (tsc names them)

---

### Task 1: Backend — `FeedGroup` scope on the entry list

**Files:**
- Create: `backend/src/Enum/FeedGroup.php`, `backend/src/Repository/FeedGroupDql.php`, `backend/tests/Controller/Api/EntryFeedGroupScopeTest.php`
- Modify: `backend/src/Repository/EntryAliases.php`, `backend/src/Repository/EntryQuery.php`, `backend/src/Repository/EntryScopePredicates.php`, `backend/src/Controller/Api/EntryController.php`, `backend/tests/Repository/EntryQueryTest.php`

**Interfaces:**
- Produces: `enum App\Enum\FeedGroup: string { Tags = 'tags'; Feeds = 'feeds'; static fromRequestValue(?string): ?self }`
- Produces: `App\Repository\FeedGroupDql::restrict(QueryBuilder $qb, string $subscriptionAlias, string $feedAlias, FeedGroup $group): void`. The caller must have `$feedAlias` bound to the subscription's feed, either joined or in an outer query.
- Produces: `EntryAliases::$groupFeed` (`'gf'` primary, `'gf2'` collapse), `EntryQuery::$feedGroup` (`?FeedGroup`, last ctor param, default null), `EntryScopePredicates::inFeedGroup(QueryBuilder, EntryAliases, FeedGroup): void`

- [ ] **Step 1: Write the failing functional test**

Create `backend/tests/Controller/Api/EntryFeedGroupScopeTest.php`. Copy the `auth()`, `body()`, `guidsOfResponse()`, `fixtures()` and `entityManager()` helpers verbatim from `tests/Controller/Api/EntryNetworkScopeTest.php`, then add:

```php
public function testTheTagsGroupListsEveryTaggedFeedOnceAndNothingUntagged(): void
{
    [$headers, $user] = $this->auth('group-tags@example.com');
    $this->seedGroups($user);

    $this->client->request('GET', '/api/entries?group=tags', server: $headers);

    self::assertResponseIsSuccessful();
    self::assertSame(['tagged-social-post', 'hidden-tagged-post', 'twice-tagged-post'], $this->guidsOfResponse());
}

public function testTheFeedsGroupListsOnlyUntaggedFeedsOnNoNetwork(): void
{
    [$headers, $user] = $this->auth('group-feeds@example.com');
    $this->seedGroups($user);

    $this->client->request('GET', '/api/entries?group=feeds', server: $headers);

    self::assertResponseIsSuccessful();
    self::assertSame(['plain-post'], $this->guidsOfResponse());
}

public function testAGroupNeverListsAnotherUsersFeeds(): void
{
    [$headers, $user] = $this->auth('group-own@example.com');
    $this->seedGroups($user);
    [, $stranger] = $this->auth('group-stranger@example.com');
    $foreign = $this->fixtures()->subscribe($stranger, 'https://example.org/foreign.xml');
    $this->fixtures()->post($foreign, 'foreign-post', '2026-10-09T00:00:00Z');

    $this->client->request('GET', '/api/entries?group=feeds', server: $headers);

    self::assertSame(['plain-post'], $this->guidsOfResponse());
}

public function testAnUnknownGroupIsAValidationError(): void
{
    [$headers] = $this->auth('group-unknown@example.com');

    $this->client->request('GET', '/api/entries?group=folders', server: $headers);

    self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    self::assertSame(['group' => ['Unknown group. Use one of: tags, feeds.']], $this->body()['errors']);
}

/**
 * Tagged: a feed with two tags (listed once), one hidden from All items (still listed), a tagged Bluesky profile.
 * Untagged: a plain feed (the Feeds group) and a Bluesky home timeline (social, so in neither group).
 */
private function seedGroups(User $user): void
{
    $fixtures = $this->fixtures();
    $news = $this->tag($user, 'news');
    $tech = $this->tag($user, 'tech');

    $twiceTagged = $fixtures->subscribe($user, 'https://example.com/twice.xml');
    $twiceTagged->addTag($news);
    $twiceTagged->addTag($tech);
    $fixtures->post($twiceTagged, 'twice-tagged-post', '2026-10-04T00:00:00Z');

    $hiddenTagged = $fixtures->subscribe($user, 'https://example.com/hidden.xml');
    $hiddenTagged->addTag($news);
    $hiddenTagged->setIncludeInAllItems(false);
    $fixtures->post($hiddenTagged, 'hidden-tagged-post', '2026-10-05T00:00:00Z');

    $taggedSocial = $fixtures->subscribe($user, NetworkFeedSubscriptions::BLUESKY_PROFILE);
    $taggedSocial->addTag($tech);
    $fixtures->post($taggedSocial, 'tagged-social-post', '2026-10-06T00:00:00Z');

    $plain = $fixtures->subscribe($user, NetworkFeedSubscriptions::PLAIN);
    $fixtures->post($plain, 'plain-post', '2026-10-07T00:00:00Z');

    $home = $fixtures->subscribe($user, NetworkFeedSubscriptions::blueskyHome(1), SourceFormat::BLUESKY_API);
    $fixtures->post($home, 'home-post', '2026-10-08T00:00:00Z');
    $this->entityManager()->flush();
}

private function tag(User $user, string $name): Tag
{
    $tag = new Tag($user, $name);
    $this->entityManager()->persist($tag);
    $this->entityManager()->flush();

    return $tag;
}
```

The list is newest first: social (10-06), hidden (10-05), twice (10-04). The `plain-post` (10-07) and `home-post` (10-08) are untagged, so they are absent from the Tags list.

- [ ] **Step 2: Run it and confirm it fails**

Run: `cd backend && php bin/phpunit tests/Controller/Api/EntryFeedGroupScopeTest.php`
Expected: FAIL. The first three tests return every entry because `group` is ignored, and the validation test gets 200.

- [ ] **Step 3: Add the enum**

`backend/src/Enum/FeedGroup.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

use App\Exception\ValidationException;

enum FeedGroup: string
{
    use RequestValueParsing;

    case Tags = 'tags';
    case Feeds = 'feeds';

    /**
     * @throws ValidationException when the value names no group
     */
    public static function fromRequestValue(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return self::caseOrRefusal($value, 'group');
    }
}
```

- [ ] **Step 4: Add the one predicate definition**

`backend/src/Repository/FeedGroupDql.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\FeedGroup;
use Doctrine\ORM\QueryBuilder;

/** The sidebar's two sections: Tags holds every tagged subscription, Feeds the untagged ones on no network. */
final class FeedGroupDql
{
    public static function restrict(
        QueryBuilder $qb,
        string $subscriptionAlias,
        string $feedAlias,
        FeedGroup $group,
    ): void {
        if ($group === FeedGroup::Tags) {
            $qb->andWhere(\sprintf('%s.subscriptionTags IS NOT EMPTY', $subscriptionAlias));

            return;
        }

        $qb->andWhere(\sprintf('%s.subscriptionTags IS EMPTY', $subscriptionAlias))
            ->andWhere(\sprintf('%s.network IS NULL', $feedAlias));
    }

    private function __construct()
    {
    }
}
```

- [ ] **Step 5: Thread it through aliases, query and predicates**

`EntryAliases`: add a sixth promoted property `public string $groupFeed`. Change `primary()` to `new self('e', 'es', 's', 'st', 'nf', 'gf')` and `collapse()` to `new self('e2', 'es2', 's2', 'st2', 'nf2', 'gf2')`.

`EntryQuery`: add the last constructor parameter `public ?FeedGroup $feedGroup = null,` (import `App\Enum\FeedGroup`). Extend `isNarrowed()`:

```php
private function isNarrowed(): bool
{
    return $this->tagId !== null || $this->networkScope !== null || $this->feedGroup !== null;
}
```

Also update the `isNarrowedFanIn()` docblock to read "narrowed to one tag, a network or a feed group".

`EntryScopePredicates::applyList`: after the `networkScope` block add

```php
if ($query->feedGroup !== null) {
    $this->inFeedGroup($qb, $aliases, $query->feedGroup);
}
```

and add the public method (next to `joinNetworkFeeds`):

```php
public function inFeedGroup(QueryBuilder $qb, EntryAliases $aliases, FeedGroup $group): void
{
    $qb->innerJoin(\sprintf('%s.feed', $aliases->subscription), $aliases->groupFeed);
    FeedGroupDql::restrict($qb, $aliases->subscription, $aliases->groupFeed, $group);
}
```

- [ ] **Step 6: Accept `group` in the list endpoint**

`EntryController::list`: add `#[MapQueryParameter] ?string $group = null,` after `$feedKind`, and pass `feedGroup: FeedGroup::fromRequestValue($group),` as the last named argument of `new EntryQuery(...)`.

- [ ] **Step 7: Pin "narrowed" in `EntryQueryTest`**

Add the following next to `testANetworkScopedViewNeverHides`:

```php
public function testAFeedGroupViewNeverHides(): void
{
    self::assertFalse((new EntryQuery(1, EntryView::All, feedGroup: FeedGroup::Tags))->hidesExcludedFeeds());
    self::assertFalse((new EntryQuery(1, EntryView::Unread, feedGroup: FeedGroup::Feeds))->hidesExcludedFeeds());
}
```

- [ ] **Step 8: Run the tests and confirm they pass**

Run: `cd backend && php bin/phpunit tests/Controller/Api/EntryFeedGroupScopeTest.php tests/Repository/EntryQueryTest.php tests/Repository/EntryListTest.php tests/Repository/DuplicateCollapseTest.php`
Expected: PASS. The last two prove the extra alias left the existing lists alone.

- [ ] **Step 9: Run the gates on the touched files, then commit**

Run: `cd backend && composer cs && composer stan && vendor/bin/phpmd src/Enum/FeedGroup.php,src/Repository/FeedGroupDql.php,src/Repository/EntryAliases.php,src/Repository/EntryQuery.php,src/Repository/EntryScopePredicates.php,src/Controller/Api/EntryController.php text phpmd.xml.dist`. If the ruleset file is named differently, use whatever `composer md` uses.

```bash
git add backend/src/Enum/FeedGroup.php backend/src/Repository/FeedGroupDql.php backend/src/Repository/EntryAliases.php backend/src/Repository/EntryQuery.php backend/src/Repository/EntryScopePredicates.php backend/src/Controller/Api/EntryController.php backend/tests/Controller/Api/EntryFeedGroupScopeTest.php backend/tests/Repository/EntryQueryTest.php
git commit -m "feat(#1735): list entries by feed group (tags, feeds)"
```

---

### Task 2: Backend — group unread counts in `/api/subscriptions/counts`

**Files:**
- Modify: `backend/src/Repository/UnreadCountRepository.php`, `backend/src/Service/Subscription/Model/UnreadTotalsModel.php`, `backend/src/Service/Subscription/SubscriptionTallyReader.php`, `backend/src/Http/UnreadTotalsJson.php`
- Test: `backend/tests/Repository/UnreadCountRepositoryTest.php`, `backend/tests/Http/UnreadTotalsJsonTest.php`, `backend/tests/Http/SubscriptionCountsJsonTest.php`, `backend/tests/Http/SubscriptionJsonListTest.php`

**Interfaces:**
- Consumes: `EntryScopePredicates::inFeedGroup`, `FeedGroup` (Task 1)
- Produces: `UnreadCountRepository::perFeedGroup(int $userId): array<string, int>` (every `FeedGroup` value present, 0 included), `UnreadTotalsModel::$feedGroups` (last ctor param, required), and JSON `unreadTotals.groups: {tags: int, feeds: int}`

- [ ] **Step 1: Write the failing repository tests**

Add these to `UnreadCountRepositoryTest`; they use its existing `user()`, `tag()`, `subscribedFeed()` and `unreadEntry()` helpers:

```php
public function testTheTagsGroupCountsATwiceTaggedFeedOnce(): void
{
    $user = $this->user();
    $news = $this->tag($user, 'news');
    $tech = $this->tag($user, 'tech');
    [$subscription, $feed] = $this->subscribedFeed($user, 'https://example.com/a.xml');
    $subscription->addTag($news);
    $subscription->addTag($tech);
    [, $untaggedFeed] = $this->subscribedFeed($user, 'https://example.com/untagged.xml');
    $this->unreadEntry($feed, 'tagged', null);
    $this->unreadEntry($untaggedFeed, 'untagged', null);

    self::assertSame(
        [FeedGroup::Tags->value => 1, FeedGroup::Feeds->value => 1],
        $this->repository()->perFeedGroup($user->requireId()),
    );
}

public function testTheFeedsGroupSkipsSocialFeedsAndCountsFeedsHiddenFromAllItems(): void
{
    $user = $this->user();
    [$hidden, $hiddenFeed] = $this->subscribedFeed($user, 'https://example.com/hidden.xml');
    $hidden->setIncludeInAllItems(false);
    [, $homeFeed] = $this->subscribedFeed($user, 'social:bluesky:1:home', SourceFormat::BLUESKY_API);
    $this->unreadEntry($hiddenFeed, 'hidden', null);
    $this->unreadEntry($homeFeed, 'home', null);

    self::assertSame(
        [FeedGroup::Tags->value => 0, FeedGroup::Feeds->value => 1],
        $this->repository()->perFeedGroup($user->requireId()),
    );
}
```

(`setIncludeInAllItems` must be flushed. If `unreadEntry()` already flushes, nothing to add; otherwise add `$this->entityManager->flush();` before the assertion.)

- [ ] **Step 2: Run them and confirm they fail**

Run: `cd backend && php bin/phpunit tests/Repository/UnreadCountRepositoryTest.php`
Expected: FAIL with "Call to undefined method …perFeedGroup()".

- [ ] **Step 3: Implement the count**

In `UnreadCountRepository`, after `perNetworkProfiles`:

```php
/** @return array<string, int> feed group => unread entries, every group present */
public function perFeedGroup(int $userId): array
{
    $counts = [];
    foreach (FeedGroup::cases() as $group) {
        $qb = $this->unreadEntries($userId)->select('COUNT(e.id)');
        $this->scope->inFeedGroup($qb, EntryAliases::primary(), $group);
        $counts[$group->value] = (int) $qb->getQuery()->getSingleScalarResult();
    }

    return $counts;
}
```

- [ ] **Step 4: Carry the counts through the model, the reader and the JSON**

`UnreadTotalsModel`: add a last parameter `public array $feedGroups,` and add `@param array<string, int> $feedGroups feed group => unread entries, every group present` to the docblock.

`SubscriptionTallyReader::forUser`: pass `$this->unreadCounts->perFeedGroup($userId),` as the last `UnreadTotalsModel` argument.

`UnreadTotalsJson::from`: build

```php
$groups = [];
foreach (FeedGroup::cases() as $group) {
    $groups[$group->value] = $totals->feedGroups[$group->value] ?? 0;
}
```

then add `'groups' => $groups` to the returned array, and add `groups: array<string, int>` to the `@return` shape.

- [ ] **Step 5: Update the JSON tests**

In `UnreadTotalsJsonTest`, add `feedGroups: ['tags' => 3],` to the named-argument constructor call. Add `'groups' => ['tags' => 3, 'feeds' => 0],` to the expected array; the `?? 0` default is what this asserts.

In `SubscriptionCountsJsonTest` and `SubscriptionJsonListTest`, every positional `new UnreadTotalsModel(…)` gains a trailing `[]` argument (5 call sites: SubscriptionCountsJsonTest:20,47; SubscriptionJsonListTest:29,65,105). If one of them asserts the full `unreadTotals` payload, add `'groups' => ['tags' => 0, 'feeds' => 0]` to it.

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `cd backend && php bin/phpunit tests/Repository/UnreadCountRepositoryTest.php tests/Http tests/Controller/Api/SubscriptionControllerTest.php`
Expected: PASS.

- [ ] **Step 7: Run the gates on the touched files, then commit**

```bash
git add backend/src/Repository/UnreadCountRepository.php backend/src/Service/Subscription backend/src/Http/UnreadTotalsJson.php backend/tests/Repository/UnreadCountRepositoryTest.php backend/tests/Http
git commit -m "feat(#1735): count each feed group's unread entries once"
```

---

### Task 3: Backend — mark a feed group read

**Files:**
- Modify: `backend/src/Service/Reading/Model/ReadScopeKind.php`, `backend/src/Service/Reading/Model/ReadScopeModel.php`, `backend/src/Dto/Entry/MarkReadRequest.php`, `backend/src/Repository/SubscriptionScopeRepository.php`, `backend/src/Service/Reading/MarkReadService.php`
- Test: `backend/tests/Repository/SubscriptionScopeRepositoryTest.php`, `backend/tests/Controller/Api/EntryFeedGroupScopeTest.php`

**Interfaces:**
- Consumes: `FeedGroup`, `FeedGroupDql::restrict` (Task 1)
- Produces: `ReadScopeKind::Group = 'group'`, `ReadScopeModel::group(FeedGroup): self`, `ReadScopeModel::targetFeedGroup(): FeedGroup`, `SubscriptionScopeRepository::findForUserInFeedGroup(int $userId, FeedGroup $group): list<Subscription>`, and the body `{"scope":"group","group":"tags"|"feeds","until":…}`

- [ ] **Step 1: Write the failing tests**

Add these to `EntryFeedGroupScopeTest`:

```php
public function testMarkingTheFeedsGroupReadLeavesTaggedFeedsUnread(): void
{
    [$headers, $user] = $this->auth('group-mark@example.com');
    $this->seedGroups($user);

    $this->markRead($headers, ['scope' => 'group', 'group' => 'feeds']);
    self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

    $this->client->request('GET', '/api/entries?group=feeds&view=unread', server: $headers);
    self::assertSame([], $this->guidsOfResponse());
    $this->client->request('GET', '/api/entries?group=tags&view=unread', server: $headers);
    self::assertSame(['tagged-social-post', 'hidden-tagged-post', 'twice-tagged-post'], $this->guidsOfResponse());
}

public function testMarkingTheTagsGroupReadReachesFeedsHiddenFromAllItems(): void
{
    [$headers, $user] = $this->auth('group-mark-tags@example.com');
    $this->seedGroups($user);

    $this->markRead($headers, ['scope' => 'group', 'group' => 'tags']);

    $this->client->request('GET', '/api/entries?group=tags&view=unread', server: $headers);
    self::assertSame([], $this->guidsOfResponse());
    $this->client->request('GET', '/api/entries?group=feeds&view=unread', server: $headers);
    self::assertSame(['plain-post'], $this->guidsOfResponse());
}

public function testMarkingAGroupReadWithoutAGroupIsAValidationError(): void
{
    [$headers] = $this->auth('group-mark-missing@example.com');

    $this->markRead($headers, ['scope' => 'group']);

    self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
    self::assertSame(['group' => ['A group is required when scope is "group".']], $this->body()['errors']);
}

/** @param array<string, string> $scope */
private function markRead(array $headers, array $scope): void
{
    $this->client->request(
        'POST',
        '/api/entries/mark-read',
        server: $headers + ['CONTENT_TYPE' => 'application/json'],
        content: json_encode($scope + ['until' => '2026-10-12T00:00:00Z'], \JSON_THROW_ON_ERROR),
    );
}
```

(`$headers` is `array<string, string>`; add that `@param` too if PHPStan asks.)

Add this to `SubscriptionScopeRepositoryTest`, following its existing seeding style. Read its first test and reuse its helpers:

```php
public function testAFeedGroupHoldsExactlyItsSidebarSection(): void
```

Seed one tagged plain feed, one untagged plain feed and one untagged Bluesky home feed. Assert that `findForUserInFeedGroup($userId, FeedGroup::Tags)` returns only the tagged one, and `FeedGroup::Feeds` returns only the untagged plain one (compare `requireId()` lists).

- [ ] **Step 2: Run them and confirm they fail**

Run: `cd backend && php bin/phpunit tests/Controller/Api/EntryFeedGroupScopeTest.php tests/Repository/SubscriptionScopeRepositoryTest.php`
Expected: FAIL. The mark-read requests get 422 for an unknown `scope`, and the repository method is undefined.

- [ ] **Step 3: Implement**

`ReadScopeKind`: add `case Group = 'group';`.

`ReadScopeModel`: add a fourth ctor param `private ?FeedGroup $feedGroup = null`, then:

```php
public static function group(FeedGroup $feedGroup): self
{
    return new self(ReadScopeKind::Group, null, feedGroup: $feedGroup);
}

public function targetFeedGroup(): FeedGroup
{
    return $this->feedGroup
        ?? throw new \LogicException(sprintf('A "%s" scope names no feed group.', $this->kind->value));
}
```

`MarkReadRequest`: add `ReadScopeKind::Group->value` to the `Assert\Choice` list, add a ctor param `public ?string $group = null,`, add the match arm `ReadScopeKind::Group => ReadScopeModel::group($this->requiredGroupFor($kind)),`, and:

```php
private function requiredGroupFor(ReadScopeKind $kind): FeedGroup
{
    return FeedGroup::fromRequestValue($this->group) ?? throw new ValidationException([
        'group' => [sprintf('A group is required when scope is "%s".', $kind->value)],
    ]);
}
```

`SubscriptionScopeRepository`:

```php
/** @return list<Subscription> */
public function findForUserInFeedGroup(int $userId, FeedGroup $group): array
{
    $qb = $this->createQueryBuilder('s')
        ->join('s.feed', 'f')->addSelect('f')
        ->andWhere('s.user = :userId')->setParameter('userId', $userId)
        ->orderBy('s.id', 'ASC');
    FeedGroupDql::restrict($qb, 's', 'f', $group);

    /** @var list<Subscription> $rows */
    $rows = $qb->getQuery()->getResult();

    return $rows;
}
```

`MarkReadService::subscriptionsIn`: add the arm

```php
ReadScopeKind::Group => $this->subscriptionScope->findForUserInFeedGroup($userId, $scope->targetFeedGroup()),
```

- [ ] **Step 4: Run the tests and confirm they pass**

Run: `cd backend && php bin/phpunit tests/Controller/Api/EntryFeedGroupScopeTest.php tests/Repository/SubscriptionScopeRepositoryTest.php tests/Service/Reading`
Expected: PASS.

- [ ] **Step 5: Run the gates, then commit**

```bash
git add backend/src/Service/Reading backend/src/Dto/Entry/MarkReadRequest.php backend/src/Repository/SubscriptionScopeRepository.php backend/tests/Controller/Api/EntryFeedGroupScopeTest.php backend/tests/Repository/SubscriptionScopeRepositoryTest.php
git commit -m "feat(#1735): mark a feed group read"
```

---

### Task 4: Backend — refresh a feed group

**Files:**
- Create: `backend/tests/Repository/FeedRepositoryFeedGroupScopeTest.php`
- Modify: `backend/src/Repository/DueFeedCriteria.php`, `backend/src/Repository/FeedRepository.php`, `backend/src/Service/Refresh/Model/RefreshRequestModel.php`, `backend/src/Service/Refresh/RefreshRunner/RefreshRunner.php`, `backend/src/Service/Refresh/RefreshRunStore.php`, `backend/src/Service/Refresh/UserRefreshScope.php`, `backend/src/Controller/Api/RefreshController.php`
- Test: `backend/tests/Service/Refresh/RefreshRunStoreTest.php`, `backend/tests/Service/Refresh/UserRefreshScopeTest.php`, `backend/tests/Controller/Api/RefreshControllerTest.php`

**Interfaces:**
- Consumes: `FeedGroup`, `FeedGroupDql::restrict` (Task 1)
- Produces: `DueFeedCriteria::$feedGroup` (last ctor param, default null), `RefreshRequestModel::$feedGroup` + `RefreshRequestModel::forUserFeedGroup(int $userId, FeedGroup $group, int $budgetSeconds): self`, `UserRefreshScope::requestForFeedGroup(int $userId, FeedGroup $group): RefreshRequestModel`, the run key `group-<value>`, and `POST /api/refresh?group=tags|feeds`

- [ ] **Step 1: Write the failing tests**

`FeedRepositoryFeedGroupScopeTest`: copy the class skeleton, `use SeedsUsers`, `NOW`, `idsOf()`, `feeds()` and `fixtures()` helpers from `FeedRepositoryNetworkScopeTest`, with this `criteria()`:

```php
private function criteria(User $owner, FeedGroup $group): DueFeedCriteria
{
    return new DueFeedCriteria(new \DateTimeImmutable(self::NOW), $owner->requireId(), force: true, feedGroup: $group);
}
```

Tests:

```php
public function testTheTagsGroupSelectsEveryTaggedFeedOfTheUserSocialIncluded(): void
// Seed owner subscriptions: tagged plain (A), tagged Bluesky profile (B), untagged plain (C),
// untagged blueskyHome (D, SourceFormat::BLUESKY_API). Tag via `new Tag($owner, 'news')` + addTag + flush.
// Assert findDue(criteria(owner, Tags)) ids === [A, B] and countDue === 2.

public function testTheFeedsGroupSelectsOnlyUntaggedFeedsOnNoNetwork(): void
// Same seed; assert ids === [C].

public function testAGroupNeverReachesAFeedTagOnlyAnotherUserCarries(): void
// The stranger subscribes to the same URL as the owner's untagged plain feed and tags it.
// Assert the owner's Tags group selects nothing.
```

The last test pins the `gs.user = :userId` correlation: without it, the stranger's tag would pull the shared feed into the owner's Tags group. Read `NetworkFeedSubscriptions::subscribe()` first. It creates a new `Feed` per call, so for this test persist one `Feed` and two `Subscription`s by hand, the way `UserRefreshScopeTest::setUp` does.

`RefreshRunStoreTest`: mirror `testNetworkRunsAreKeptApartFromEachOtherAndFromTheSweep`:

```php
public function testFeedGroupRunsAreKeptApartFromEachOtherAndFromTheSweep(): void
{
    $tags = RefreshRequestModel::forUserFeedGroup(1, FeedGroup::Tags, self::BUDGET);
    $this->store->save($tags, RefreshRunProgressModel::start()->advancedBy(1, 2));

    self::assertSame(0, $this->store->open(RefreshRequestModel::forUserFeedGroup(1, FeedGroup::Feeds, self::BUDGET))->done);
    self::assertSame(0, $this->store->open(RefreshRequestModel::forUser(1, self::BUDGET))->done);
    self::assertSame(1, $this->store->open($tags)->done);
}
```

`UserRefreshScopeTest`: mirror `testANetworkRefreshIsAForcedRunOverThatNetworksFeeds`. Call `requestForFeedGroup($userId, FeedGroup::Feeds)` and assert `feedGroup === FeedGroup::Feeds`, `networkScope`/`feedId`/`tagId` null, `force` true, `budgetSeconds` 25.

`RefreshControllerTest`: mirror `testRefreshOfAnUnknownNetworkIsAValidationError` with `POST /api/refresh?group=folders` → `Response::HTTP_UNPROCESSABLE_ENTITY`, plus

```php
public function testFeedGroupRefreshIsAccepted(): void
```

which posts `/api/refresh?group=feeds` for a fresh user and asserts success, the way `testRefreshWithNoFeedsReportsCompleted` does.

- [ ] **Step 2: Run them and confirm they fail**

Run: `cd backend && php bin/phpunit tests/Repository/FeedRepositoryFeedGroupScopeTest.php tests/Service/Refresh tests/Controller/Api/RefreshControllerTest.php`
Expected: FAIL. The named argument `feedGroup` and `forUserFeedGroup` don't exist yet, and `group=folders` gets 200.

- [ ] **Step 3: Implement the criteria and the due-feed restriction**

`DueFeedCriteria`: add the last ctor param `public ?FeedGroup $feedGroup = null,` and pass `$this->feedGroup` last in `excluding()`.

`FeedRepository::dueQueryBuilder`: call `$this->restrictToFeedGroup($qb, $criteria);` right after `restrictToNetwork`. Then:

```php
private function restrictToFeedGroup(QueryBuilder $qb, DueFeedCriteria $criteria): void
{
    if ($criteria->feedGroup === null) {
        return;
    }

    $subscriptions = $this->getEntityManager()->createQueryBuilder()
        ->select('gs.id')
        ->from(Subscription::class, 'gs')
        ->andWhere('gs.feed = f')
        ->andWhere('gs.user = :userId');
    FeedGroupDql::restrict($subscriptions, 'gs', 'f', $criteria->feedGroup);

    $qb->andWhere(sprintf('EXISTS (%s)', $subscriptions->getDQL()))->setParameter('userId', $criteria->userId);
}
```

If `composer md` then reports FeedRepository over the class-length or method-count threshold, do not tune the threshold. Move the four `restrictTo*` scope methods into a `DueFeedScopeDql` helper in `src/Repository` and call it from `dueQueryBuilder`. Mention this in the commit body.

- [ ] **Step 4: Implement the request model, runner, store, scope and controller**

`RefreshRequestModel`: add the last ctor param `public ?FeedGroup $feedGroup = null,`. Pass `$this->feedGroup` last in `withoutPruning()`, `ignoringSchedule()` and `limitedTo()`. Add:

```php
public static function forUserFeedGroup(int $userId, FeedGroup $feedGroup, int $budgetSeconds): self
{
    return new self($userId, null, null, true, $budgetSeconds, false, feedGroup: $feedGroup);
}
```

`RefreshRunner::dueCriteria`: add `feedGroup: $request->feedGroup,`.

`RefreshRunStore::scopeOf`: before `return 'all';` add

```php
if (null !== $request->feedGroup) {
    return 'group-' . $request->feedGroup->value;
}
```

`UserRefreshScope`:

```php
public function requestForFeedGroup(int $userId, FeedGroup $feedGroup): RefreshRequestModel
{
    return RefreshRequestModel::forUserFeedGroup($userId, $feedGroup, self::BUDGET_SECONDS);
}
```

`RefreshController::__invoke`: add `#[MapQueryParameter] ?string $group = null,` and replace the ternary with

```php
$networkScope = NetworkScope::fromRequestValues($network, $feedKind);
$feedGroup = FeedGroup::fromRequestValue($group);
$request = match (true) {
    null !== $networkScope => $this->scope->requestForNetwork($user->requireId(), $networkScope),
    null !== $feedGroup => $this->scope->requestForFeedGroup($user->requireId(), $feedGroup),
    default => $this->scope->requestFor($user->requireId(), $feedId, $tag),
};
```

- [ ] **Step 5: Run the tests and confirm they pass**

Run: `cd backend && php bin/phpunit tests/Repository tests/Service/Refresh tests/Controller/Api/RefreshControllerTest.php`
Expected: PASS.

- [ ] **Step 6: Run the gates (`composer check`, `composer md`), then commit**

```bash
git add backend/src/Repository/DueFeedCriteria.php backend/src/Repository/FeedRepository.php backend/src/Service/Refresh backend/src/Controller/Api/RefreshController.php backend/tests/Repository/FeedRepositoryFeedGroupScopeTest.php backend/tests/Service/Refresh backend/tests/Controller/Api/RefreshControllerTest.php
git commit -m "feat(#1735): refresh only a feed group's feeds"
```

---

### Task 5: Frontend — the `'group'` selection, end to end below the sidebar

**Files:**
- Create: `frontend/src/app/core/feed-group.ts`
- Modify: `reader/models.ts`, `reader/query/query.ts`, `reader/reader-api.ts`, `reader/state/unread-totals.ts`, `reader/state/subscription-derivations.ts`, `reader/state/subscriptions.store.ts`, `reader/scroll/list-scroll-memory.ts`, `reader/shell/refresh-actions.service.ts`, `reader/shell/mark-read-actions.service.ts`, `reader/shell/list-heading.service.ts`, `frontend/e2e/support/reader.ts`
- Test: `reader/query/query.spec.ts`, `reader/state/unread-totals.spec.ts`, `reader/scroll/list-scroll-memory.spec.ts`, `reader/shell/list-heading.service.spec.ts`, `reader/shell/mark-read-actions.service.spec.ts`

**Interfaces:**
- Consumes: the wire contract from Tasks 1–4
- Produces: `FeedGroupName = 'tags' | 'feeds'`, `isFeedGroupName(value: string | null): value is FeedGroupName`, `Selection.kind` gains `'group'` and `Selection.group?: FeedGroupName`, `UnreadTotals.groups: Readonly<Record<FeedGroupName, number>>`, `SubscriptionsStore.feedGroupCounts: Signal<Record<FeedGroupName, { unreadCount: number; entryCount: number }>>`, and `selectionQueryParams({ group: 'tags' })`

- [ ] **Step 1: Write the failing specs**

`query.spec.ts`. Add these next to the network cases:

```ts
it("reads a feed group's combined view", () => {
  expect(selectionFromParams(pm({ group: 'tags' })).selection).toEqual({
    kind: 'group',
    id: null,
    unread: false,
    group: 'tags',
  });
});

it('ignores a feed group it does not know', () => {
  expect(selectionFromParams(pm({ group: 'folders' })).selection.kind).toBe('all');
});

it("maps a feed group's view through the unread toggle", () => {
  expect(queryFromSelection({ kind: 'group', id: null, unread: true, group: 'feeds' })).toEqual({
    view: 'unread',
    group: 'feeds',
  });
});

it('marks a feed group read by its name', () => {
  expect(markReadTarget({ kind: 'group', id: null, unread: false, group: 'tags' })).toEqual({
    scope: 'group',
    group: 'tags',
  });
});

it('tells the two feed groups apart', () => {
  const tags: Selection = { kind: 'group', id: null, unread: false, group: 'tags' };
  expect(sameSelection(tags, { ...tags, group: 'feeds' })).toBe(false);
  expect(listOrderKey(tags)).toBe('group:tags');
  expect(canScopedRefresh(tags)).toBe(true);
});
```

Then update the existing vocabulary expectations. The `SELECTION_PARAM_NAMES` list asserted around line 159 gains `'group'`, and every `selectionQueryParams` expectation object (lines ~166–262) gains `group: null`.

`list-scroll-memory.spec.ts`: add `it('keeps the two feed groups apart', …)` asserting `scrollKey` differs between `group: 'tags'` and `group: 'feeds'`.

`unread-totals.spec.ts`: add `groups: { tags: 2, feeds: 5 }` to the DTO in "maps the DTO lists into maps" and `expect(result.groups).toEqual({ tags: 2, feeds: 5 })`. Add a `sameUnreadTotals` case where only `groups.feeds` differs → `false`. Add `groups: { tags: 0, feeds: 0 }` to the shared `totals` fixture.

`list-heading.service.spec.ts`: follow how the file already tests the network title and count. Add `title` is `'Feeds'` for `{ kind: 'group', group: 'feeds' }`. Also check the count: with stubbed totals `groups.tags = 4` and unread on, the count is 4; with unread off, it is the summed `entryCount` of the tagged subscriptions.

`mark-read-actions.service.spec.ts`: follow the existing network case; a group selection posts `{ scope: 'group', group: 'tags', until }` to `/api/entries/mark-read`.

- [ ] **Step 2: Run them and confirm they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/query src/app/reader/state/unread-totals.spec.ts src/app/reader/scroll/list-scroll-memory.spec.ts src/app/reader/shell/list-heading.service.spec.ts src/app/reader/shell/mark-read-actions.service.spec.ts`
Expected: FAIL. These are type errors and wrong kinds.

- [ ] **Step 3: Implement the types and the query vocabulary**

`frontend/src/app/core/feed-group.ts`:

```ts
export const FEED_GROUPS = ['tags', 'feeds'] as const;

export type FeedGroupName = (typeof FEED_GROUPS)[number];

export function isFeedGroupName(value: string | null): value is FeedGroupName {
  return FEED_GROUPS.some((group) => group === value);
}
```

`models.ts`:
- `EntryQuery` gains `group?: FeedGroupName;`, under a one-line doc: `/** A sidebar section's combined view: every tagged feed, or every untagged non-social one. */`
- `MarkReadScope` becomes `'all' | 'feed' | 'tag' | 'network' | 'group'`
- `UnreadTotalsDto` gains `groups: Record<FeedGroupName, number>;`

`query.ts`:
- `Selection.kind` gains `| 'group'`. Add the field `/** Only a feed group's combined view carries one. */ group?: FeedGroupName;`
- `RefreshScope` gains `group?: FeedGroupName;`, and its doc gets "group = that sidebar section's feeds".
- `sameSelection` adds `left.group === right.group &&`
- `listOrderKey` adds `if (selection.group) return \`group:${selection.group}\`;` after the network line.
- `canScopedRefresh` adds `selection.kind === 'group' ||`
- `SELECTION_PARAM_NAMES` gains `'group'` (after `'feedKind'`).
- `feedScopedSelection` updates its doc ("one subscription, a tag, a network's feeds, a feed group, or all of them") and, after the network branch, adds:
  ```ts
  const group = selectionParam(paramMap, 'group');
  if (isFeedGroupName(group)) return { kind: 'group', id: null, unread: false, group };
  ```
- `VIEW_QUERY` gains `group: (selection) => ({ view: listView(selection), group: selection.group }),`
- The `MarkReadTarget` union gains `| { scope: Extract<MarkReadScope, 'group'>; group: FeedGroupName }`
- `MARK_READ_TARGET` gains `group: (selection) => (selection.group ? { scope: 'group', group: selection.group } : null),`

`list-scroll-memory.ts`: in `scrollKey`, after `${networkKey(selection)}`, add a segment `:${selection.group ?? ''}`.

`reader-api.ts`:
- list: `if (query.group != null) listParams = listParams.set('group', query.group);`
- refresh: add `else if (scope?.group != null) params = params.set('group', scope.group);` and extend the doc comment with "or by group for a sidebar section's feeds".
- `markRead` needs no change, since it spreads the target.

`refresh-actions.service.ts` `refreshScope()`: `case 'group': return selection.group ? { group: selection.group } : null;`

`mark-read-actions.service.ts` `markReadRequest()`: add `case 'group':` to the `api.markRead` fall-through group.

- [ ] **Step 4: Implement totals, store counts and heading**

`unread-totals.ts`: `UnreadTotals` gains `readonly groups: Readonly<Record<FeedGroupName, number>>;`. `NO_UNREAD_TOTALS.groups = { tags: 0, feeds: 0 }`. `unreadTotalsFrom` sets `groups: { tags: dto.groups.tags, feeds: dto.groups.feeds }`. `sameUnreadTotals` adds `left.groups.tags === right.groups.tags && left.groups.feeds === right.groups.feeds &&`.

`subscription-derivations.ts`: add the helper below and use it in `buildTagTree`'s `entryCount` (replacing the inline reduce):

```ts
export function totalEntryCount(subscriptions: SubscriptionDto[]): number {
  return subscriptions.reduce((total, subscription) => total + subscription.entryCount, 0);
}
```

`subscriptions.store.ts`, after `untagged`:

```ts
/** Each sidebar section's own list: what its header counts and its combined view holds. */
readonly feedGroupCounts = computed<Record<FeedGroupName, FeedGroupCounts>>(() => {
  const unread = this.unreadTotals().groups;
  return {
    tags: {
      unreadCount: unread.tags,
      entryCount: totalEntryCount(this.subscriptions().filter((subscription) => subscription.tags.length > 0)),
    },
    feeds: { unreadCount: unread.feeds, entryCount: totalEntryCount(this.untagged()) },
  };
});
```

with `export interface FeedGroupCounts { readonly unreadCount: number; readonly entryCount: number; }` in `subscription-derivations.ts`.

`list-heading.service.ts`:
- Add `const GROUP_TITLE_KEY: Record<FeedGroupName, string> = { tags: 'reader.tags', feeds: 'reader.feeds' };` at module level.
- `titleByKind.group: () => { const group = this.routeState.selection().group; return group ? this.i18n.translate(GROUP_TITLE_KEY[group]) : ''; }`
- `countByKind.group: (selection) => { const counts = selection.group ? this.subscriptions.feedGroupCounts()[selection.group] : null; return bySwitch(selection, counts?.unreadCount ?? 0, counts?.entryCount ?? 0); }`

`e2e/support/reader.ts` `unreadTotalsOf()`: add

```ts
groups: {
  tags: subscriptions
    .filter((subscription) => subscription.tags.length > 0)
    .reduce((total, subscription) => total + subscription.unreadCount, 0),
  feeds: subscriptions
    .filter((subscription) => subscription.tags.length === 0 && !subscription.social)
    .reduce((total, subscription) => total + subscription.unreadCount, 0),
},
```

- [ ] **Step 5: Typecheck and fix the remaining fixtures**

Run: `docker compose exec -T frontend npx tsc -p tsconfig.spec.json --noEmit`. Every spec or fixture that builds an `UnreadTotals`/`UnreadTotalsDto` literal now fails for the missing `groups`; add `groups: { tags: 0, feeds: 0 }` to each one tsc names. A spec that iterates `Selection['kind']` records may also need a `group` entry.

- [ ] **Step 6: Run the specs and confirm they pass**

Run: `docker compose exec -T frontend npx jest src/app/reader`
Expected: PASS.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/core/feed-group.ts frontend/src/app/reader frontend/e2e/support/reader.ts
git commit -m "feat(#1735): a feed group is a list selection with its count, refresh and mark-read"
```

---

### Task 6: Frontend — Tags/Feeds headers link to their list and show a count

**Files:**
- Modify: `reader/shell/sidebar/sidebar.component.{ts,html,scss}`, `reader/shell/sidebar/sidebar-social-section.component.scss`, `reader/shell/sidebar/_sidebar-row.scss`, `reader/shell/reader-shell.component.html`
- Test: `reader/shell/sidebar/sidebar.component.spec.ts`

**Interfaces:**
- Consumes: `Selection.group`, `selectionQueryParams({ group })`, `UnreadTotals.groups` (Task 5)
- Produces: the sidebar input `groupUnread: Readonly<Record<FeedGroupName, number>>` (default `{ tags: 0, feeds: 0 }`)

- [ ] **Step 1: Write the failing specs**

In `sidebar.component.spec.ts`, add `groupUnread: Readonly<Record<FeedGroupName, number>>;` to `mount()`'s `over` type and `groupUnread: { tags: 0, feeds: 0 }` to `inputDefaults()`.

Rewrite the existing "collapsible tag list" / "collapsible feed list" cases that click or read `.section-toggle`, so they use `.section-chevron` instead. Read `aria-expanded` from the chevron, and replace "collapses … when the title is clicked" with the chevron click. Then add:

```ts
describe('section header links', () => {
  beforeEach(() => localStorage.clear());
  afterEach(() => localStorage.clear());

  it('links the Tags title to the combined tags list and shows its count', () => {
    const element = mount({ tagTree: [tagNodeFixture], groupUnread: { tags: 4, feeds: 0 } })
      .nativeElement as HTMLElement;
    const link = element.querySelector('.tags-head a.section-link') as HTMLAnchorElement;
    expect(link.getAttribute('href')).toContain('group=tags');
    expect(link.querySelector('.section-count')?.textContent?.trim()).toBe('4');
  });

  it('links the Feeds title to the combined feeds list and hides a zero count', () => {
    const element = mount({ untagged: [sub(1, 2)] }).nativeElement as HTMLElement;
    const link = element.querySelector('.feeds-head a.section-link') as HTMLAnchorElement;
    expect(link.getAttribute('href')).toContain('group=feeds');
    expect(link.querySelector('.section-count')).toBeNull();
  });

  it('opens the list from the title without collapsing the section', () => {
    const fixture = mount({ untagged: [sub(1, 2)] });
    const element = fixture.nativeElement as HTMLElement;
    (element.querySelector('.feeds-head a.section-link') as HTMLAnchorElement).click();
    fixture.detectChanges();
    expect(element.querySelector('.feedlist .feedrow')).not.toBeNull();
  });

  it("marks only the open group's title active", () => {
    const selection: Selection = { kind: 'group', id: null, unread: true, group: 'feeds' };
    const element = mount({ tagTree: [tagNodeFixture], untagged: [sub(1, 2)], selection })
      .nativeElement as HTMLElement;
    expect(element.querySelector('.feeds-head .section-link.active')).not.toBeNull();
    expect(element.querySelector('.tags-head .section-link.active')).toBeNull();
  });
});
```

(`tagNodeFixture`: hoist the `tagNode` const from "collapsible tag list" to file scope and reuse it.)

- [ ] **Step 2: Run them and confirm they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/shell/sidebar/sidebar.component.spec.ts`
Expected: FAIL, because there is no `.section-link` in either head.

- [ ] **Step 3: Implement the component**

`sidebar.component.ts`:

```ts
readonly groupUnread = input<Readonly<Record<FeedGroupName, number>>>({ tags: 0, feeds: 0 });
protected readonly activeGroup = computed(() =>
  this.selection().kind === 'group' ? (this.selection().group ?? null) : null,
);
```

`sidebar.component.html`: in both heads, replace the `.section-toggle` button with a link. Leave the chevron button exactly as it is. Tags:

```html
<a
  class="section-link"
  [class.active]="activeGroup() === 'tags'"
  [routerLink]="['/']"
  [queryParams]="selectionQueryParams({ group: 'tags' })"
  queryParamsHandling="merge"
>
  <span class="label">{{ 'reader.tags' | transloco }}</span>
  @if (groupUnread().tags > 0) {
    <span class="section-count">{{ groupUnread().tags }}</span>
  }
</a>
```

Feeds is the same with `'feeds'` / `reader.feeds`. Update the two HTML comments above the heads to say that the title opens the section's combined list and the chevron collapses it (#612, #1735). Keep them to a line or two.

`reader-shell.component.html`: on `<app-sidebar …>`, add `[groupUnread]="subs.unreadTotals().groups"`.

- [ ] **Step 4: Move the link styling to the shared partial**

In `_sidebar-row.scss`, replace `@mixin section-toggle { … }` (now unused) with:

```scss
/* A section title that opens the section's combined list: a nav row with hover, active and a tag-row count. */
@mixin section-link {
  @include nav-states('.section-link', '.section-count');

  .section-link {
    flex: 1;
    min-width: 0;
    display: flex;
    align-items: center;
    padding-block: var(--space-1);
    border-radius: var(--radius);
    text-decoration: none;
  }

  .section-link .label {
    margin-block: 0;
  }

  .section-count {
    margin-inline: auto var(--space-2);
  }

  .section-link.active .label {
    color: var(--accent);
  }
}
```

`sidebar-social-section.component.scss`: delete the `nav-states` include and the four `.section-link`/`.section-count` blocks plus their comment, and add `@include row.section-link;`.
`sidebar.component.scss`: replace `@include row.section-toggle;` with `@include row.section-link;`.

- [ ] **Step 5: Run the sidebar specs, then `npm run check`**

Run: `docker compose exec -T frontend npx jest src/app/reader/shell/sidebar` → PASS. Then `docker compose exec -T frontend npm run check` → clean (ESLint, Prettier, Stylelint, tsc, Jest).

- [ ] **Step 6: Commit**

```bash
git add frontend/src/app/reader/shell
git commit -m "feat(#1735): the Tags and Feeds headers open their combined list and show its count"
```

---

### Task 7: Verification and PR

- [ ] **Step 1: Backend, both legs.** From `backend/`, run `composer check`, `composer md`, `composer test:parallel`, then `composer infection:diff` (`git add` new files first, since infection:diff ignores untracked files). Then run `docker compose exec php composer test` for the MySQL leg. All must pass. Paste any failure verbatim instead of summarising it.
- [ ] **Step 2: Frontend gate.** Run `docker compose exec -T frontend npm run check`.
- [ ] **Step 3: Live check on http://localhost:4200** (Docker stack current; reload php/worker if the DI container is stale). The checks:
  - the Tags and Feeds headers show counts;
  - clicking each opens a list titled Tags or Feeds;
  - a feed tagged twice appears once;
  - a social feed never shows in Feeds;
  - the chevron still collapses;
  - the header is highlighted while its list is open;
  - Mark all read empties the list and zeroes its count;
  - the list's refresh button sends `POST /api/refresh?group=…` (network panel);
  - the Mobile viewport shows the same.
- [ ] **Step 4: Dev log.** Run `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`. It should show no new deprecations or errors.
- [ ] **Step 5: Push and open the PR** into `develop`, titled `feat(#1735): Tags and Feeds headers open a combined list with a count`, with a body that ends `Closes #1735`. No attribution lines.

---

## Self-review notes

- Spec coverage:
  - list → T1
  - dedup / narrowed → T1 (`IS NOT EMPTY` never multiplies rows; `isNarrowed`)
  - counts → T2
  - mark-read → T3
  - refresh → T4
  - selection / URL / title / view / scroll / order → T5
  - header UX → T6
  - empty header: already hidden by the existing `@if`, no task needed
- Wire names checked across tasks: `group` param, `scope: 'group'`, `groups: {tags, feeds}`, `FeedGroup::Tags|Feeds`, `FeedGroupName`, `feedGroupCounts`, `groupUnread`.
- Risk: the `IS EMPTY` DQL on an arbitrary-joined `Subscription` alias in the list query. T1 Step 8 (EntryListTest + DuplicateCollapseTest + functional test) exercises both the primary and the collapse alias sets. If Doctrine rejects `s.feed` on the `ON`-joined alias, switch `inFeedGroup` to join `%s.feed` from `$aliases->entry`; both name the same feed.
