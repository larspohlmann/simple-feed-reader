# Social sidebar "Feeds" row (#1659) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** In each social network's sidebar section, replace the plain "People" heading with a tag-style **Feeds** row (fixed glyph, unread count, own chevron, remembered collapse) whose click opens one combined list of that network's profile feeds.

**Architecture:** The backend's bare `?SocialNetwork $network` scope (entry list, mark-read, refresh) becomes a `NetworkScope` value — a network plus an optional `NetworkFeedKind::Profile` — parsed once from `network` + `feedKind` and applied by one DQL helper (`NetworkScopeDql`). A profile feed is a networked feed whose `sourceFormat` is not one of `SourceFormat::SOCIAL`, exactly `SubscriptionJson::social()`'s rule. The frontend carries `feedKind` on the existing `network` selection kind, and the social section renders the profiles as a virtual tag using the sidebar's shared row mixins.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine DQL; Angular 20 standalone components + signals, Transloco, Jest.

**Spec:** GitHub issue #1659 (the design was settled in a grilling session, 2026-10-16). Read it before starting.

## Global Constraints

- Branch `feature/1659-social-feeds-row` off `develop`. **Create it only once `develop` is clean and the concurrent session on the other branch is done**. Check `git status` and ask before any `checkout`/`stash`.
- Commit messages: `type(#1659): …`. No Claude attribution anywhere.
- URL / API: `?network=<bluesky|mastodon>&feedKind=profile`. `feedKind` accepts only `profile`. An unknown value, or a `feedKind` without a `network`, answers **422 `validation_error`**, as an unknown network does. Mark-read body: `{"scope":"network","network":…,"feedKind":"profile","until":…}`. Refresh: `POST /api/refresh?network=…&feedKind=profile`.
- The row is virtual: nothing is stored, and nothing is added to the RSS tag tree.
- Glyph: `app-tag-glyph name="group"`, no colour (renders `--text-muted`), not configurable.
- No "⋯" menu on the row. Clicking the row navigates. Only the chevron toggles.
- Collapse key `sfr.${network}.profiles.collapsed`, **collapsed by default**.
- List heading: `"<Network> · Feeds"` (e.g. "Mastodon · Feeds"), the same in DE.
- i18n: new `reader.socialFeeds` = "Feeds" / "Feeds", new `reader.toggleSocialFeeds`. Remove `reader.socialPeople`. `reader.feeds` stays the untagged RSS section's label.
- Clean Code rules of `CLAUDE.md` apply (no boolean flag params, no comments that restate code, PHPMD-clean touched files).
- Frontend tests run in the container: `docker compose exec -T frontend npx jest <path>`. Backend: `php bin/phpunit --filter <Name>` from `backend/`.

---

## File Structure

Backend (new):
- `backend/src/Enum/NetworkFeedKind.php`: the `feedKind` vocabulary (`profile`).
- `backend/src/Repository/NetworkScope.php`: a network plus an optional feed kind, parsed from request values.
- `backend/src/Repository/NetworkScopeDql.php`: the one DQL restriction for a `NetworkScope` on a feed alias.

Backend (modified): `EntryController`, `EntryQuery`, `EntryScopePredicates`, `MarkReadRequest`, `ReadScopeModel`, `SubscriptionScopeRepository`, `RefreshController`, `UserRefreshScope`, `RefreshRequestModel`, `RefreshRunStore`, `DueFeedCriteria`, `FeedRepository`, `RefreshRunner` (passes the scope through), plus their tests.

Frontend (modified): `core/social-network.ts`, `reader/models.ts`, `reader/query/query.ts`, `reader/reader-api.ts`, `reader/shell/refresh-actions.service.ts`, `reader/state/social-sections.ts`, `reader/shell/list-heading.service.ts`, `core/preferences/persisted-toggle.ts`, `reader/shell/sidebar/sidebar-social-section.component.{ts,html,scss}`, `reader/shell/sidebar/_sidebar-row.scss`, `reader/shell/sidebar/sidebar.component.scss`, `public/i18n/{en,de}.json`, plus specs.

---

### Task 0: Branch and plan

- [ ] **Step 1: Confirm develop is clean and free**

Run: `git status --short && git branch --show-current && git fetch origin develop`
Expected: no changes on your own checkout. If another session's branch is checked out here, **stop and ask the user**. Do not stash or switch.

- [ ] **Step 2: Branch and commit the plan**

```bash
git checkout -b feature/1659-social-feeds-row origin/develop
cp <scratchpad>/2026-10-16-1659-social-feeds-row.md docs/superpowers/plans/
git add docs/superpowers/plans/2026-10-16-1659-social-feeds-row.md
git commit -m "docs(#1659): plan the social Feeds row"
```

---

### Task 1: `NetworkScope` and the profile-only entry list

**Files:**
- Create: `backend/src/Enum/NetworkFeedKind.php`, `backend/src/Repository/NetworkScope.php`, `backend/src/Repository/NetworkScopeDql.php`
- Modify: `backend/src/Repository/EntryQuery.php`, `backend/src/Repository/EntryScopePredicates.php`, `backend/src/Controller/Api/EntryController.php`
- Test: create `backend/tests/Repository/NetworkScopeTest.php`, modify `backend/tests/Controller/Api/EntryNetworkScopeTest.php`, `backend/tests/Repository/EntryQueryTest.php`, and every other test that builds an `EntryQuery` with `network:` (find them with `grep -rn "network: SocialNetwork" backend/tests`).

**Interfaces:**
- Produces: `NetworkFeedKind::Profile`, `NetworkFeedKind::fromRequestValue(?string): ?self`; `NetworkScope::whole(SocialNetwork): self`, `NetworkScope::profiles(SocialNetwork): self`, `NetworkScope::fromRequestValues(?string $network, ?string $feedKind): ?self`, `NetworkScope::toRunKey(): string`, public `$network` (`SocialNetwork`) and `$feedKind` (`?NetworkFeedKind`); `NetworkScopeDql::restrict(QueryBuilder $qb, string $feedAlias, NetworkScope $scope): void`; `EntryQuery::$network` is now `?NetworkScope`.

- [ ] **Step 1: Write the failing unit test**

`backend/tests/Repository/NetworkScopeTest.php`:

```php
<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Enum\NetworkFeedKind;
use App\Enum\SocialNetwork;
use App\Exception\ValidationException;
use App\Repository\NetworkScope;
use PHPUnit\Framework\TestCase;

final class NetworkScopeTest extends TestCase
{
    public function testNoNetworkAndNoFeedKindIsNoScope(): void
    {
        self::assertNull(NetworkScope::fromRequestValues(null, null));
    }

    public function testANetworkAloneScopesTheWholeNetwork(): void
    {
        $scope = NetworkScope::fromRequestValues('bluesky', null);

        self::assertEquals(NetworkScope::whole(SocialNetwork::Bluesky), $scope);
        self::assertNull($scope?->feedKind);
    }

    public function testAProfileFeedKindScopesTheNetworksProfiles(): void
    {
        $scope = NetworkScope::fromRequestValues('mastodon', 'profile');

        self::assertSame(SocialNetwork::Mastodon, $scope?->network);
        self::assertSame(NetworkFeedKind::Profile, $scope->feedKind);
    }

    public function testAnUnknownFeedKindIsAValidationError(): void
    {
        $this->assertRefused(
            ['feedKind' => ['Unknown feedKind. Use one of: profile.']],
            static fn () => NetworkScope::fromRequestValues('bluesky', 'home'),
        );
    }

    public function testAFeedKindWithoutANetworkIsAValidationError(): void
    {
        $this->assertRefused(
            ['feedKind' => ['A feedKind needs a network.']],
            static fn () => NetworkScope::fromRequestValues(null, 'profile'),
        );
    }

    public function testTheRunKeyTellsAWholeNetworkFromItsProfiles(): void
    {
        self::assertSame('bluesky', NetworkScope::whole(SocialNetwork::Bluesky)->toRunKey());
        self::assertSame('bluesky-profile', NetworkScope::profiles(SocialNetwork::Bluesky)->toRunKey());
    }

    /** @param array<string, list<string>> $expected */
    private function assertRefused(array $expected, callable $parse): void
    {
        try {
            $parse();
            self::fail('Expected a ValidationException.');
        } catch (ValidationException $exception) {
            self::assertSame($expected, $exception->getErrors());
        }
    }
}
```

Check how `ValidationException` exposes its errors (`grep -n "function" backend/src/Exception/ValidationException.php`) and use that accessor if it is not `getErrors()`. `MarkReadRequestTest` already asserts on one, so copy its helper style.

- [ ] **Step 2: Run it to verify it fails**

Run: `php bin/phpunit --filter NetworkScopeTest`
Expected: FAIL, class `App\Repository\NetworkScope` not found.

- [ ] **Step 3: Implement the enum, the scope and the DQL helper**

`backend/src/Enum/NetworkFeedKind.php`:

```php
<?php

declare(strict_types=1);

namespace App\Enum;

use App\Exception\ValidationException;

enum NetworkFeedKind: string
{
    use RequestValueParsing;

    case Profile = 'profile';

    /**
     * @throws ValidationException when the value names no feed kind
     */
    public static function fromRequestValue(?string $value): ?self
    {
        if ($value === null) {
            return null;
        }

        return self::caseOrRefusal($value, 'feedKind');
    }
}
```

`backend/src/Repository/NetworkScope.php`:

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\NetworkFeedKind;
use App\Enum\SocialNetwork;
use App\Exception\ValidationException;

/** A network's feeds, or only its profile feeds: the scope a list, a mark-read and a refresh share (#1659). */
final readonly class NetworkScope
{
    private function __construct(public SocialNetwork $network, public ?NetworkFeedKind $feedKind)
    {
    }

    public static function whole(SocialNetwork $network): self
    {
        return new self($network, null);
    }

    public static function profiles(SocialNetwork $network): self
    {
        return new self($network, NetworkFeedKind::Profile);
    }

    /**
     * @throws ValidationException for an unknown network or feed kind, or a feed kind without a network
     */
    public static function fromRequestValues(?string $network, ?string $feedKind): ?self
    {
        $socialNetwork = SocialNetwork::fromRequestValue($network);
        $kind = NetworkFeedKind::fromRequestValue($feedKind);
        if ($socialNetwork === null && $kind !== null) {
            throw new ValidationException(['feedKind' => ['A feedKind needs a network.']]);
        }

        return $socialNetwork === null ? null : new self($socialNetwork, $kind);
    }

    public function toRunKey(): string
    {
        return $this->feedKind === null ? $this->network->value : $this->network->value . '-' . $this->feedKind->value;
    }
}
```

`backend/src/Repository/NetworkScopeDql.php` (static, like `UnreadDql`):

```php
<?php

declare(strict_types=1);

namespace App\Repository;

use App\Enum\NetworkFeedKind;
use App\Enum\SourceFormat;
use Doctrine\ORM\QueryBuilder;

/** A profile feed is a networked feed not read through an account, as SubscriptionJson::social() classifies it. */
final class NetworkScopeDql
{
    public static function restrict(QueryBuilder $qb, string $feedAlias, NetworkScope $scope): void
    {
        $qb->andWhere(\sprintf('%s.network = :network', $feedAlias))->setParameter('network', $scope->network);
        if ($scope->feedKind === NetworkFeedKind::Profile) {
            $qb->andWhere(\sprintf('%s.sourceFormat NOT IN (:privateFeedFormats)', $feedAlias))
                ->setParameter('privateFeedFormats', SourceFormat::SOCIAL);
        }
    }

    private function __construct()
    {
    }
}
```

- [ ] **Step 4: Run the unit test**

Run: `php bin/phpunit --filter NetworkScopeTest`
Expected: PASS.

- [ ] **Step 5: Write the failing functional tests**

Add to `backend/tests/Controller/Api/EntryNetworkScopeTest.php` (its `seedNetworks()` already seeds a Bluesky Home, a Bluesky profile, a Mastodon profile and a plain feed):

```php
    public function testAProfileFeedKindListsOnlyTheNetworksProfileFeeds(): void
    {
        [$headers, $user] = $this->auth('network-profiles@example.com');
        $this->seedNetworks($user);

        $this->client->request('GET', '/api/entries?network=bluesky&feedKind=profile', server: $headers);

        self::assertResponseIsSuccessful();
        self::assertSame(['profile-post'], $this->guidsOfResponse());
    }

    public function testAnUnknownFeedKindIsAValidationError(): void
    {
        [$headers] = $this->auth('network-feedkind-unknown@example.com');

        $this->client->request('GET', '/api/entries?network=bluesky&feedKind=home', server: $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['feedKind' => ['Unknown feedKind. Use one of: profile.']], $this->body()['errors']);
    }

    public function testAFeedKindWithoutANetworkIsAValidationError(): void
    {
        [$headers] = $this->auth('network-feedkind-alone@example.com');

        $this->client->request('GET', '/api/entries?feedKind=profile', server: $headers);

        self::assertResponseStatusCodeSame(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertSame(['feedKind' => ['A feedKind needs a network.']], $this->body()['errors']);
    }
```

- [ ] **Step 6: Run them to verify they fail**

Run: `php bin/phpunit --filter EntryNetworkScopeTest`
Expected: the profile test FAILS because it returns `['profile-post', 'home-post']`. The two validation tests FAIL with 200.

- [ ] **Step 7: Thread the scope through the list**

`EntryQuery`: change `public ?SocialNetwork $network = null` to `public ?NetworkScope $network = null`, and drop the now-unused `SocialNetwork` import. `isNarrowed()` stays as it is.

`EntryScopePredicates::onNetwork()` becomes:

```php
    private function onNetwork(QueryBuilder $qb, EntryAliases $aliases, NetworkScope $network): void
    {
        $qb->innerJoin(\sprintf('%s.feed', $aliases->entry), $aliases->networkFeed);
        NetworkScopeDql::restrict($qb, $aliases->networkFeed, $network);
    }
```

(Swap the `SocialNetwork` import for nothing, since `NetworkScope` is in the same namespace.)

`EntryController::list()`: add `#[MapQueryParameter] ?string $feedKind = null,` after `$network`. Replace `$socialNetwork = SocialNetwork::fromRequestValue($network);` with `$networkScope = NetworkScope::fromRequestValues($network, $feedKind);`, pass `network: $networkScope`, import `App\Repository\NetworkScope`, and drop the `SocialNetwork` import if nothing else uses it.

Update every test that passes `network: SocialNetwork::X` to an `EntryQuery` so it passes `network: NetworkScope::whole(SocialNetwork::X)`.

- [ ] **Step 8: Run the list tests and stan**

Run: `php bin/phpunit --filter 'EntryNetworkScopeTest|EntryQueryTest|EntryListTest|NetworkScopeTest' && composer stan`
Expected: PASS, and stan is clean. Stan will flag every other consumer of `EntryQuery::$network`. Fix each one by passing the `NetworkScope` through unchanged.

- [ ] **Step 9: Commit**

```bash
git add backend/src/Enum/NetworkFeedKind.php backend/src/Repository/NetworkScope.php backend/src/Repository/NetworkScopeDql.php backend/src/Repository/EntryQuery.php backend/src/Repository/EntryScopePredicates.php backend/src/Controller/Api/EntryController.php backend/tests
git commit -m "feat(#1659): list a network's profile feeds alone"
```

---

### Task 2: Mark a network's profile feeds read

**Files:**
- Modify: `backend/src/Dto/Entry/MarkReadRequest.php`, `backend/src/Service/Reading/Model/ReadScopeModel.php`, `backend/src/Repository/SubscriptionScopeRepository.php`
- Test: `backend/tests/Dto/Entry/MarkReadRequestTest.php`, `backend/tests/Service/Reading/Model/ReadScopeModelTest.php`, `backend/tests/Repository/SubscriptionScopeRepositoryTest.php`, `backend/tests/Service/Reading/MarkReadServiceTest.php`, `backend/tests/Controller/Api/EntryNetworkScopeTest.php`

**Interfaces:**
- Consumes: `NetworkScope` (Task 1).
- Produces: `ReadScopeModel::network(NetworkScope): self`, `ReadScopeModel::targetNetwork(): NetworkScope`, `SubscriptionScopeRepository::findForUserOnNetwork(int $userId, NetworkScope $network): list<Subscription>`, and `MarkReadRequest::$feedKind` (`?string`).

- [ ] **Step 1: Write the failing tests**

`SubscriptionScopeRepositoryTest`: add this, reusing the fixtures of `testANetworkHoldsItsPrivateAndProfileFeedsExcludedOnesIncluded`:

```php
    public function testAProfileScopeHoldsOnlyTheNetworksProfileFeeds(): void
    {
        $user = $this->user('network-profiles-owner@example.com');
        $this->fixtures()->subscribe($user, NetworkFeedSubscriptions::blueskyHome(1), SourceFormat::BLUESKY_API);
        $profile = $this->fixtures()->subscribe($user, NetworkFeedSubscriptions::BLUESKY_PROFILE);
        $this->fixtures()->subscribe($user, NetworkFeedSubscriptions::MASTODON_PROFILE);

        self::assertSame(
            [$profile->requireId()],
            $this->idsOf($this->subscriptions()->findForUserOnNetwork(
                $user->requireId(),
                NetworkScope::profiles(SocialNetwork::Bluesky),
            )),
        );
    }
```

`MarkReadRequestTest`: add this. Extend its `networkRequest()` helper with a second `?string $feedKind = null` argument passed to the constructor as `feedKind:`:

```php
    public function testANetworkScopeCarriesItsFeedKind(): void
    {
        self::assertEquals(
            NetworkScope::profiles(SocialNetwork::Bluesky),
            $this->networkRequest('bluesky', 'profile')->toScope()->targetNetwork(),
        );
    }
```

`EntryNetworkScopeTest`:

```php
    public function testMarkingANetworksProfilesReadLeavesItsPrivateFeedsUnread(): void
    {
        [$headers, $user] = $this->auth('network-mark-profiles@example.com');
        $this->seedNetworks($user);

        $this->client->request(
            'POST',
            '/api/entries/mark-read',
            server: $headers + ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['scope' => 'network', 'network' => 'bluesky', 'feedKind' => 'profile', 'until' => '2026-10-12T00:00:00Z'],
                \JSON_THROW_ON_ERROR,
            ),
        );
        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $this->client->request('GET', '/api/entries?network=bluesky&view=unread', server: $headers);
        self::assertSame(['home-post'], $this->guidsOfResponse());
    }
```

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit --filter 'SubscriptionScopeRepositoryTest|MarkReadRequestTest|EntryNetworkScopeTest'`
Expected: FAIL, with type errors (a `NetworkScope` is passed where a `SocialNetwork` is expected) and the mark-read test unread list still holding nothing.

- [ ] **Step 3: Implement**

`ReadScopeModel`: the private `?SocialNetwork $network` becomes `?NetworkScope $network`, `network(NetworkScope $network)`, and `targetNetwork(): NetworkScope`.

`MarkReadRequest`: add the constructor property `public ?string $feedKind = null,` after `$network`. `requiredNetworkFor()` returns `NetworkScope`:

```php
    private function requiredNetworkFor(ReadScopeKind $kind): NetworkScope
    {
        return NetworkScope::fromRequestValues($this->network, $this->feedKind) ?? throw new ValidationException([
            'network' => [sprintf('A network is required when scope is "%s".', $kind->value)],
        ]);
    }
```

`SubscriptionScopeRepository::findForUserOnNetwork(int $userId, NetworkScope $network)`: replace the `->andWhere('f.network = :network')->setParameter(...)` link with a call after the builder chain:

```php
        $qb = $this->createQueryBuilder('s')
            ->join('s.feed', 'f')->addSelect('f')
            ->andWhere('s.user = :userId')->setParameter('userId', $userId)
            ->orderBy('s.id', 'ASC');
        NetworkScopeDql::restrict($qb, 'f', $network);

        /** @var list<Subscription> $rows */
        $rows = $qb->getQuery()->getResult();

        return $rows;
```

Update the existing tests that pass `SocialNetwork::X` (`ReadScopeModelTest`, `MarkReadServiceTest`, `SubscriptionScopeRepositoryTest`, `MarkReadRequestTest`) to pass `NetworkScope::whole(SocialNetwork::X)` or compare against it.

- [ ] **Step 4: Run the tests and stan**

Run: `php bin/phpunit --filter 'SubscriptionScopeRepositoryTest|MarkReadRequestTest|ReadScopeModelTest|MarkReadServiceTest|EntryNetworkScopeTest' && composer stan`
Expected: PASS, and stan is clean.

- [ ] **Step 5: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#1659): mark a network's profile feeds read"
```

---

### Task 3: Refresh a network's profile feeds

**Files:**
- Modify: `backend/src/Controller/Api/RefreshController.php`, `backend/src/Service/Refresh/UserRefreshScope.php`, `backend/src/Service/Refresh/Model/RefreshRequestModel.php`, `backend/src/Service/Refresh/RefreshRunStore.php`, `backend/src/Service/Refresh/RefreshRunner/RefreshRunner.php`, `backend/src/Repository/DueFeedCriteria.php`, `backend/src/Repository/FeedRepository.php`
- Test: `backend/tests/Repository/FeedRepositoryNetworkScopeTest.php`, `backend/tests/Service/Refresh/RefreshRunStoreTest.php`, `backend/tests/Controller/Api/RefreshControllerTest.php`, `backend/tests/Service/Refresh/UserRefreshScopeTest.php`, `backend/tests/Service/Refresh/Model/RefreshRequestModelTest.php`

**Interfaces:**
- Consumes: `NetworkScope`, `NetworkScopeDql` (Task 1).
- Produces: `UserRefreshScope::requestForNetwork(int, NetworkScope)`, `RefreshRequestModel::forUserNetwork(int, NetworkScope, int)`, and `RefreshRequestModel::$network` / `DueFeedCriteria::$network` typed `?NetworkScope`.

- [ ] **Step 1: Write the failing tests**

`FeedRepositoryNetworkScopeTest`: change `criteria()` to take a `NetworkScope` (the existing tests pass `NetworkScope::whole(...)`), then add:

```php
    public function testAProfileScopeSelectsOnlyTheNetworksProfileFeeds(): void
    {
        $owner = $this->user('network-scope-profiles@example.com');
        $this->fixtures()->subscribe($owner, NetworkFeedSubscriptions::blueskyHome(1), SourceFormat::BLUESKY_API);
        $profile = $this->fixtures()->subscribe($owner, NetworkFeedSubscriptions::BLUESKY_PROFILE)->getFeed();

        $criteria = $this->criteria($owner, NetworkScope::profiles(SocialNetwork::Bluesky));

        self::assertSame([$profile->requireId()], $this->idsOf($this->feeds()->findDue($criteria, 50)));
        self::assertSame(1, $this->feeds()->countDue($criteria));
    }
```

`RefreshRunStoreTest`: find the existing test that asserts the `set-network-…` key, and add a sibling asserting that `RefreshRequestModel::forUserNetwork(7, NetworkScope::profiles(SocialNetwork::Bluesky), 30)` keys as `…set-network-bluesky-profile`. Keep the whole-network key `set-network-bluesky` unchanged, so a run in flight across a deploy keeps its key.

`RefreshControllerTest`: next to `testNetworkRefreshSweepsOnlyThatNetworksFeeds`, add a test that `POST /api/refresh?network=mastodon&feedKind=other` answers 422 with `['feedKind' => ['Unknown feedKind. Use one of: profile.']]`.

- [ ] **Step 2: Run them to verify they fail**

Run: `php bin/phpunit --filter 'FeedRepositoryNetworkScopeTest|RefreshRunStoreTest|RefreshControllerTest'`
Expected: FAIL (type errors, a 200 on the bad `feedKind`).

- [ ] **Step 3: Implement**

- `DueFeedCriteria::$network` and `RefreshRequestModel::$network` become `?NetworkScope`. `forUserNetwork(int $userId, NetworkScope $network, int $budgetSeconds)`.
- `FeedRepository::restrictToNetwork()` changes its last line to `NetworkScopeDql::restrict($qb, 'f', $criteria->network);`.
- `RefreshRunStore::scopeOf()` changes to `return 'set-network-' . $request->network->toRunKey();`.
- `UserRefreshScope::requestForNetwork(int $userId, NetworkScope $network)`.
- `RefreshController`: add `#[MapQueryParameter] ?string $feedKind = null,`, then `$networkScope = NetworkScope::fromRequestValues($network, $feedKind);` and branch on `null === $networkScope`.
- `RefreshRunner` line ~98 already passes `$request->network` through. Stan will confirm that, and flag any other consumer.
- Update the existing tests that pass `SocialNetwork::X` into these to pass `NetworkScope::whole(SocialNetwork::X)`. Assertions like `assertSame(SocialNetwork::Bluesky, $request->network)` become `assertEquals(NetworkScope::whole(SocialNetwork::Bluesky), $request->network)`.

- [ ] **Step 4: Run the backend gates**

Run: `php bin/phpunit && composer check && composer md`
Expected: all green. Fix PHPMD findings in every touched `src` file.

- [ ] **Step 5: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#1659): refresh a network's profile feeds"
```

---

### Task 4: Frontend selection, query, mark-read and refresh carry `feedKind`

**Files:**
- Modify: `frontend/src/app/core/social-network.ts`, `frontend/src/app/reader/models.ts`, `frontend/src/app/reader/query/query.ts`, `frontend/src/app/reader/reader-api.ts`, `frontend/src/app/reader/shell/refresh-actions.service.ts`
- Test: `frontend/src/app/reader/query/query.spec.ts`, `frontend/src/app/reader/reader-api.spec.ts`

**Interfaces:**
- Produces: `type NetworkFeedKind = 'profile'` and `isNetworkFeedKind(value: string | null): value is NetworkFeedKind` (in `core/social-network.ts`); `Selection.feedKind?: NetworkFeedKind`; `EntryQuery.feedKind?`; `RefreshScope.feedKind?`; the network `MarkReadTarget` gains `feedKind?`; `SelectionParams` gains `feedKind`; and `feedKindOf(selection: Selection): { feedKind?: NetworkFeedKind }` exported from `query.ts`.

- [ ] **Step 1: Write the failing specs**

In `query.spec.ts`, add a `describe('a network\'s profile feeds')`:

```ts
describe("a network's profile feeds", () => {
  const params = (values: Record<string, string>) => convertToParamMap(values);
  const profiles: Selection = {
    kind: 'network',
    id: null,
    unread: false,
    network: 'mastodon',
    feedKind: 'profile',
  };

  it('reads the feed kind beside the network', () => {
    expect(selectionFromParams(params({ network: 'mastodon', feedKind: 'profile' })).selection).toEqual(
      profiles,
    );
  });

  it('ignores an unknown feed kind', () => {
    expect(
      selectionFromParams(params({ network: 'mastodon', feedKind: 'home' })).selection.feedKind,
    ).toBeUndefined();
  });

  it('is a different list from the whole network', () => {
    expect(sameSelection(profiles, { ...profiles, feedKind: undefined })).toBe(false);
    expect(listOrderKey(profiles)).toBe('network:mastodon:profile');
  });

  it('queries, marks read and links with the feed kind', () => {
    expect(queryFromSelection(profiles)).toEqual({
      view: 'all',
      network: 'mastodon',
      feedKind: 'profile',
    });
    expect(markReadTarget(profiles)).toEqual({
      scope: 'network',
      network: 'mastodon',
      feedKind: 'profile',
    });
    expect(selectionQueryParams({ network: 'mastodon' }).feedKind).toBeNull();
  });
});
```

In `reader-api.spec.ts`, follow the existing network list/refresh tests (`grep -n "network" frontend/src/app/reader/reader-api.spec.ts`) and add two: the list request carries `feedKind=profile` next to `network=mastodon`, and `refresh({ network: 'mastodon', feedKind: 'profile' })` posts to `/api/refresh?network=mastodon&feedKind=profile`.

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/query/query.spec.ts src/app/reader/reader-api.spec.ts`
Expected: FAIL (TS: `feedKind` does not exist on `Selection`).

- [ ] **Step 3: Implement**

`core/social-network.ts`, append:

```ts
export type NetworkFeedKind = 'profile';

export function isNetworkFeedKind(value: string | null): value is NetworkFeedKind {
  return value === 'profile';
}
```

`models.ts`, in `EntryQuery` after `network`:

```ts
  /** Narrows a network's view to its profile feeds, the sidebar's Feeds row (#1659). */
  feedKind?: NetworkFeedKind;
```

`query.ts`:
- `Selection`: after `network`, add `/** Only a network's Feeds row carries one. */ feedKind?: NetworkFeedKind;`
- `RefreshScope`: add `feedKind?: NetworkFeedKind;`
- `sameSelection`: add `left.feedKind === right.feedKind &&`.
- `listOrderKey`: replace the network line with ``if (selection.network) return [`network:${selection.network}`, selection.feedKind].filter(Boolean).join(':');``
- `SELECTION_PARAM_NAMES`: insert `'feedKind'` after `'network'`.
- `feedScopedSelection`: replace the network line with `if (isSocialNetworkName(network)) return networkSelection(network, selectionParam(paramMap, 'feedKind'));` and add:

```ts
function networkSelection(network: SocialNetworkName, feedKind: string | null): Selection {
  const selection: Selection = { kind: 'network', id: null, unread: false, network };
  return isNetworkFeedKind(feedKind) ? { ...selection, feedKind } : selection;
}

/** The feed kind a network selection narrows to, spread into whatever the selection is mapped onto. */
export function feedKindOf(selection: Selection): { feedKind?: NetworkFeedKind } {
  return selection.feedKind ? { feedKind: selection.feedKind } : {};
}
```

- `VIEW_QUERY.network`: `(selection) => ({ view: listView(selection), network: selection.network, ...feedKindOf(selection) }),`
- `MarkReadTarget` network arm: `{ scope: Extract<MarkReadScope, 'network'>; network: SocialNetworkName; feedKind?: NetworkFeedKind }`.
- `MARK_READ_TARGET.network`: `selection.network ? { scope: 'network', network: selection.network, ...feedKindOf(selection) } : null,`

`reader-api.ts`:
- List: after the network line, `if (query.feedKind != null) listParams = listParams.set('feedKind', query.feedKind);`
- `refresh()`: replace the network `else if` with
  ```ts
  else if (scope?.network != null) {
    params = params.set('network', scope.network);
    if (scope.feedKind != null) params = params.set('feedKind', scope.feedKind);
  }
  ```
- `markRead` already spreads the target into the body, so it needs no change.

`refresh-actions.service.ts`, `case 'network'`: `return selection.network ? { network: selection.network, ...feedKindOf(selection) } : null;`

- [ ] **Step 4: Audit the other network readers**

Run: `grep -rn "\.network\b" frontend/src/app --include=*.ts | grep -v spec`
For each hit that keys or compares a list by its network alone (scroll or unread memory, list identity), decide whether the profile list needs `feedKind` too. Fix the ones that do, add a spec for each, and record the decisions in the commit body. The network logo in `list-header` and the entry pill link stay as they are.

- [ ] **Step 5: Run the specs and the typecheck**

Run: `docker compose exec -T frontend npx jest src/app/reader/query src/app/reader/reader-api.spec.ts src/app/reader/shell/refresh-actions && docker compose exec -T frontend npx tsc -p tsconfig.spec.json --noEmit`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src
git commit -m "feat(#1659): a network selection can narrow to its profile feeds"
```

---

### Task 5: Profiles group in the social sections and the list heading

**Files:**
- Modify: `frontend/src/app/reader/state/social-sections.ts`, `frontend/src/app/reader/shell/list-heading.service.ts`
- Test: `frontend/src/app/reader/state/social-sections.spec.ts`, `frontend/src/app/reader/shell/list-heading.service.spec.ts`, plus every fixture with `people:` (`social-sections.spec.ts`, `subscriptions.store.spec.ts`, `list-heading.service.spec.ts`, `sidebar.component.spec.ts`, `sidebar-social-section.component.spec.ts`)

**Interfaces:**
- Consumes: `Selection.feedKind` (Task 4).
- Produces: `interface SocialProfiles { subscriptions: readonly SubscriptionDto[]; unreadCount: number; entryCount: number }`, `socialProfiles(subscriptions: readonly SubscriptionDto[]): SocialProfiles`, and `SocialSection.profiles: SocialProfiles`, which replaces `people`.

- [ ] **Step 1: Write the failing specs**

`social-sections.spec.ts`: rename the existing "…as people" test to "…lists profile feeds alphabetically", read `sections[0].profiles.subscriptions`, and add:

```ts
  it("sums only the profile feeds' counts into the profiles group", () => {
    const sections = buildSocialSections(
      [
        makeSubscription({ id: 1, unreadCount: 5, entryCount: 9, social: { network: 'bluesky', accountId: 3, kind: 'home' } }),
        makeSubscription({ id: 2, unreadCount: 2, entryCount: 4, social: { network: 'bluesky', accountId: null, kind: 'profile' } }),
        makeSubscription({ id: 3, unreadCount: 1, entryCount: 1, social: { network: 'bluesky', accountId: null, kind: 'profile' } }),
      ],
      [{ id: 3, network: 'bluesky', handle: '@a' }],
    );

    expect(sections[0].profiles.unreadCount).toBe(3);
    expect(sections[0].profiles.entryCount).toBe(5);
    expect(sections[0].unreadCount).toBe(8);
  });
```

(Check the `SocialAccountRefDto` shape in `social-feed.ts` and match it.)

`list-heading.service.spec.ts`: next to the network title and count tests (`grep -n "network" …spec.ts`), add one where the selection is `{ kind: 'network', id: null, unread: false, network: 'mastodon', feedKind: 'profile' }`. It expects the title `'Mastodon · Feeds'` and the count taken from `section.profiles`, not from the whole section.

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/state/social-sections.spec.ts src/app/reader/shell/list-heading.service.spec.ts`
Expected: FAIL.

- [ ] **Step 3: Implement**

`social-sections.ts`:

```ts
export interface SocialProfiles {
  /** Alphabetically: the people followed rather than an account's own feeds. */
  readonly subscriptions: readonly SubscriptionDto[];
  readonly unreadCount: number;
  readonly entryCount: number;
}

export function socialProfiles(subscriptions: readonly SubscriptionDto[]): SocialProfiles {
  const sorted = [...subscriptions].sort((left, right) => left.title.localeCompare(right.title));
  return {
    subscriptions: sorted,
    unreadCount: sum(sorted, (subscription) => subscription.unreadCount),
    entryCount: sum(sorted, (subscription) => subscription.entryCount),
  };
}
```

In `SocialSection`, replace `people` with `/** The network's profile feeds, shown as the Feeds row (#1659). */ readonly profiles: SocialProfiles;`. In `buildSocialSections`: `profiles: socialProfiles(ofNetwork.filter((subscription) => !isPrivateFeed(subscription))),` and filter on `section.profiles.subscriptions.length > 0`. Update the builder's doc line from "then the people" to "then the profiles".

`list-heading.service.ts`:

```ts
  private readonly selectedNetworkCounts = computed(() => {
    const section = this.selectedNetworkSection();
    if (section === null) return { unreadCount: 0, entryCount: 0 };
    return this.routeState.selection().feedKind ? section.profiles : section;
  });
```

The `countByKind.network` arm uses it: `const counts = this.selectedNetworkCounts(); return bySwitch(selection, counts.unreadCount, counts.entryCount);`. The `titleByKind.network` arm becomes `() => this.networkTitle(this.routeState.selection())` with:

```ts
  private networkTitle(selection: Selection): string {
    const network = this.i18n.translate(`settings.social.${selection.network}`);
    return selection.feedKind ? `${network} · ${this.i18n.translate('reader.socialFeeds')}` : network;
  }
```

Replace `people: [...]` in every spec fixture with `profiles: socialProfiles([...])`.

Add the heading's label now: `"socialFeeds": "Feeds",` next to `socialHome` in the `reader` block of both `frontend/public/i18n/en.json` and `de.json`.

- [ ] **Step 4: Run the specs**

Run: `docker compose exec -T frontend npx jest src/app/reader/state src/app/reader/shell/list-heading.service.spec.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src frontend/public/i18n
git commit -m "feat(#1659): a network's profile feeds form one counted group"
```

---

### Task 6: `persistedToggle` can start collapsed

**Files:**
- Modify: `frontend/src/app/core/preferences/persisted-toggle.ts`
- Test: `frontend/src/app/core/preferences/persisted-toggle.spec.ts`

**Interfaces:**
- Produces: `type ToggleStart = 'expanded' | 'collapsed'` and `persistedToggle(key: () => string, start: ToggleStart = 'expanded'): PersistedToggle`.

- [ ] **Step 1: Write the failing spec**

Use the setup the spec file already has (injection context, `localStorage.clear()`):

```ts
  it('starts collapsed when asked to, until toggled open', () => {
    const toggle = persistedToggle(() => 'sfr.test.start', 'collapsed');

    expect(toggle.expanded()).toBe(false);
    toggle.toggle();
    expect(toggle.expanded()).toBe(true);
    expect(localStorage.getItem('sfr.test.start')).toBe('false');
    expect(persistedToggle(() => 'sfr.test.start', 'collapsed').expanded()).toBe(true);
  });
```

- [ ] **Step 2: Run it to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/core/preferences/persisted-toggle.spec.ts`
Expected: FAIL (it starts expanded).

- [ ] **Step 3: Implement**

```ts
export type ToggleStart = 'expanded' | 'collapsed';

/** Starts as `start` until toggled, then follows what is stored; follows a key that changes, and keeps working in memory without storage. */
export function persistedToggle(key: () => string, start: ToggleStart = 'expanded'): PersistedToggle {
  const expanded = linkedSignal({
    source: key,
    computation: (current) => isExpanded(storedValue(current), start),
  });
  // …toggle unchanged…
}

function isExpanded(stored: string | null, start: ToggleStart): boolean {
  return stored === null ? start === 'expanded' : stored !== 'true';
}
```

- [ ] **Step 4: Run the spec**

Run: `docker compose exec -T frontend npx jest src/app/core/preferences`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/core/preferences
git commit -m "feat(#1659): a remembered toggle can start collapsed"
```

---

### Task 7: The Feeds row in the social section

**Files:**
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar-social-section.component.{ts,html,scss}`, `frontend/src/app/reader/shell/sidebar/_sidebar-row.scss`, `frontend/src/app/reader/shell/sidebar/sidebar.component.scss`, `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Test: `frontend/src/app/reader/shell/sidebar/sidebar-social-section.component.spec.ts`

**Interfaces:**
- Consumes: `SocialSection.profiles`, `socialProfiles()` (Task 5), `persistedToggle(…, 'collapsed')` (Task 6), `selectionQueryParams({ network, feedKind })` and `Selection.feedKind` (Task 4).

- [ ] **Step 1: Rewrite the People specs as Feeds specs (failing)**

In `sidebar-social-section.component.spec.ts`:
- Replace every `people: [...]` with `profiles: socialProfiles([...])`.
- Delete `'lists profile feeds under a People heading…'` and `'shows no People heading…'`, and add:

```ts
  const WITH_PROFILES: SocialSection = {
    ...ONE_ACCOUNT,
    profiles: socialProfiles([
      { ...person(7, 'Amy'), unreadCount: 2 },
      { ...person(8, 'Zed'), unreadCount: 3 },
    ]),
  };
  const feedsRow = (root: HTMLElement) =>
    root.querySelector('[data-testid="sidebar-social-feeds"]') as HTMLElement | null;

  it('shows the profile feeds as a collapsed Feeds row with their summed count', () => {
    const root = element(mount(WITH_PROFILES));

    expect(feedsRow(root)?.textContent).toContain('Feeds');
    expect(feedsRow(root)?.querySelector('app-tag-glyph')).not.toBeNull();
    expect(feedsRow(root)?.querySelector('.count')?.textContent?.trim()).toBe('5');
    expect(root.querySelectorAll('app-sidebar-feed-row').length).toBe(2);
  });

  it('lists the profiles under the row once its chevron opens it, and remembers that', () => {
    const fixture = mount(WITH_PROFILES);

    (feedsRow(element(fixture))?.querySelector('.chevzone') as HTMLButtonElement).click();
    fixture.detectChanges();

    const names = [...element(fixture).querySelectorAll('.tagfeeds app-sidebar-feed-row .nav > span:not(.count)')].map(
      (name) => name.textContent,
    );
    expect(names).toEqual(['Amy', 'Zed']);
    expect(localStorage.getItem('sfr.bluesky.profiles.collapsed')).toBe('false');
    expect(element(mount(WITH_PROFILES)).querySelectorAll('.tagfeeds app-sidebar-feed-row').length).toBe(2);
  });

  it("links the row to the network's combined profile list without expanding it", () => {
    const fixture = mount(WITH_PROFILES);
    const link = feedsRow(element(fixture))?.querySelector('a.nav') as HTMLAnchorElement;

    expect(link.getAttribute('href')).toContain('network=bluesky');
    expect(link.getAttribute('href')).toContain('feedKind=profile');
    link.click();
    fixture.detectChanges();
    expect(element(fixture).querySelector('.tagfeeds')).toBeNull();
  });

  it('marks the row, not the network title, active while the profile list is open', () => {
    const profiles: Selection = { kind: 'network', id: null, unread: true, network: 'bluesky', feedKind: 'profile' };
    const root = element(mount(WITH_PROFILES, profiles));

    expect(feedsRow(root)?.querySelector('a.nav.active')).not.toBeNull();
    expect(root.querySelector('.section-link.active')).toBeNull();
  });

  it('shows no Feeds row without profile feeds', () => {
    expect(feedsRow(element(mount(ONE_ACCOUNT)))).toBeNull();
  });
```

- Update `'shows a network that has only people…'`, `'keeps the exclusion marker on an excluded profile feed…'` and the `row avatars` block, which render profile rows: they now need the row expanded first. Seed `localStorage.setItem('sfr.bluesky.profiles.collapsed', 'false')` (or `sfr.mastodon…`) before `mount`.

- [ ] **Step 2: Run them to verify they fail**

Run: `docker compose exec -T frontend npx jest src/app/reader/shell/sidebar/sidebar-social-section.component.spec.ts`
Expected: FAIL.

- [ ] **Step 3: Implement the component**

`sidebar-social-section.component.ts`: import `TagGlyphComponent` from `../../../shared/tag-glyph/tag-glyph.component` into `imports`, and add:

```ts
const profilesCollapsedKey = (network: SocialNetworkName): string => `sfr.${network}.profiles.collapsed`;
```

In the class, replace `active` and add the profiles toggle:

```ts
  private readonly profilesCollapsible = persistedToggle(
    () => profilesCollapsedKey(this.section().network),
    'collapsed',
  );
  protected readonly profilesExpanded = this.profilesCollapsible.expanded;
  private readonly onThisNetwork = computed(() => {
    const selection = this.selection();
    return selection.kind === 'network' && selection.network === this.section().network;
  });
  protected readonly active = computed(() => this.onThisNetwork() && !this.selection().feedKind);
  protected readonly profilesActive = computed(
    () => this.onThisNetwork() && this.selection().feedKind === 'profile',
  );

  protected toggleProfiles(): void {
    this.profilesCollapsible.toggle();
  }
```

Update the class doc line to "…a handle heads each of several accounts, and the profile feeds sit under a tag-style Feeds row."

`sidebar-social-section.component.html`: replace the whole `@if (current.people.length) { … }` block with:

```html
  @if (current.profiles.subscriptions.length) {
    <div class="tag" data-testid="sidebar-social-feeds">
      <a
        class="nav grow"
        [class.active]="profilesActive()"
        [routerLink]="['/']"
        [queryParams]="selectionQueryParams({ network: current.network, feedKind: 'profile' })"
        queryParamsHandling="merge"
      >
        <span class="lead"><app-tag-glyph name="group" size="sm" /></span>
        <span>{{ 'reader.socialFeeds' | transloco }}</span>
        @if (current.profiles.unreadCount > 0) {
          <span class="count">{{ current.profiles.unreadCount }}</span>
        }
      </a>
      <button
        class="chevzone"
        type="button"
        [attr.aria-expanded]="profilesExpanded()"
        [attr.aria-label]="
          'reader.toggleSocialFeeds'
            | transloco: { network: ('settings.social.' + current.network | transloco) }
        "
        (click)="toggleProfiles()"
      >
        <app-icon [name]="profilesExpanded() ? 'expand_more' : 'chevron_right'" size="sm" />
      </button>
    </div>
    @if (profilesExpanded()) {
      <div class="tagfeeds">
        @for (subscription of current.profiles.subscriptions; track subscription.id) {
          <div class="feedrow">
            <app-sidebar-feed-row
              [subscription]="subscription"
              [selection]="selection()"
              [organising]="organising()"
            />
          </div>
        }
      </div>
    }
  }
```

- [ ] **Step 4: Share the tag styles**

`_sidebar-row.scss`: move the `.tagfeeds` band (the `background`/`margin-inline`/`padding-inline`/`border-radius: 0` rule and its #1114 comment) out of `sidebar.component.scss` into a new mixin:

```scss
/* An expanded tag's feeds sit on a recessed surface, so the group reads as
   nested under its header (#1114). The negative inline margin with matching
   padding bleeds the band to the sidebar edges. */
@mixin tag-feeds {
  .tagfeeds {
    background: var(--surface-sunken);
    margin-inline: calc(var(--space-2) * -1);
    padding-inline: var(--space-2);
    border-radius: 0;
  }
}
```

`sidebar.component.scss`: delete the moved rule and add `@include row.tag-feeds;` after `@include row.chevzone;`. Then remove `.tagfeeds` from the later `.taghead, .tagfeeds, .feedlist { border-radius: var(--radius) }` list. That rule now comes after the mixin and would round the band.

`sidebar-social-section.component.scss`: after the existing includes, add:

```scss
@include row.nav-row;
@include row.tag-head;
@include row.chevzone;
@include row.tag-feeds;
```

Then delete `.people-heading` from the `.account-handle, .people-heading` rule.

- [ ] **Step 5: i18n**

`en.json` (`reader` block): delete `"socialPeople": "People",` and add `"toggleSocialFeeds": "Toggle {{network}} feeds",`. (`socialFeeds` came in Task 5.)
`de.json`: delete `"socialPeople": "Personen",` and add `"toggleSocialFeeds": "{{network}}-Feeds ein-/ausklappen",`.
`importSectionCaption`: EN "…arrive here as feeds under the Feeds row of the Bluesky or Mastodon section…"; DE "…damit ihre Beiträge hier als Feeds unter „Feeds“ im Bluesky- oder Mastodon-Bereich ankommen…". Keep the rest of each sentence as it is.

Run: `grep -rn "socialPeople\|people-heading" frontend/src frontend/public frontend/e2e`
Expected: no hits.

- [ ] **Step 6: Run the frontend gate**

Run: `docker compose exec -T frontend npm run check`
Expected: ESLint, Prettier, Stylelint, tsc and Jest are all green.

- [ ] **Step 7: Commit**

```bash
git add frontend
git commit -m "feat(#1659): the social People heading becomes a tag-style Feeds row"
```

---

### Task 8: Verify in the real app and open the PR

- [ ] **Step 1: Both backend legs**

Run (from `backend/`): `composer test:parallel`, then `docker compose exec php composer test` (MySQL). Then `composer infection:diff`.
Expected: green, with MSI at or above `minMsi`. Kill any escaped mutant on the `NetworkScopeDql` profile branch or `toRunKey()` with a test.

- [ ] **Step 2: Real browser**

Make sure the Docker containers run this branch's code. Then, in the built-in browser at the dev stack, check each of these:
- The Bluesky and Mastodon sections each show a collapsed **Feeds** row with the group glyph and a count.
- The chevron expands the row, and the state survives a reload.
- Clicking the row opens "Mastodon · Feeds", listing only profile posts, and the row is active while the network title is not.
- "Mark all read" and refresh on that list touch only the profiles. Home's count stays the same.
- The narrow (mobile) viewport behaves the same, with a 44px chevron target.

Take one screenshot per network for the PR.

- [ ] **Step 3: Scan the dev log**

Run: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq -c 'select(.level_name != "DEBUG" and .level_name != "INFO")'`
Expected: no new deprecations or errors.

- [ ] **Step 4: PR into develop**

`gh pr create --base develop` with the title `feat(#1659): social sidebar Feeds row with a combined profile list` and a body ending `Closes #1659`, carrying the screenshots and the decisions from Task 4 Step 4.
