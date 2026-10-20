# Network Like State on Open Implementation Plan (#1731)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A Mastodon or Bluesky post the user liked on the network itself shows its thumbs-up on when the post is opened in the reader, and keeps showing it in the list afterwards.

**Architecture:** `entry.social.isLiked` is read only from `social_like`, which only `PUT /api/entries/{id}/like` writes. A new `GET /api/entries/{id}/like` resolves the post through the acting account (the existing `SocialPostFinder`, whose resolvers already read Mastodon's `favourited` and Bluesky's `viewer.like` into `SocialPostModel::$existingLikeId`) and mirrors the answer into `social_like`: it adds a like made elsewhere, drops one taken back elsewhere, and replaces one whose remote id or account no longer matches. The frontend's `SocialCountsService`, which already loads once per open with a two-minute freshness window, asks for the like state alongside the counts. It lays the answer over the entry through a new `EntriesStore.adoptLike`, and takes it as `likedAtLoad`, so a like found this way is not counted twice on top of public counts that already include it.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine, PHPUnit 12; Angular 20 signals, RxJS, Jest.

**Spec:** GitHub issue #1731 (cause + fix), and the in-chat diagnosis: public counts are cached per post and read anonymously, so they cannot carry a per-user like. That is why this is a separate authenticated call, not a field on `GET /api/entries/{id}/social`.

## Global Constraints

- Follow `CLAUDE.md` in full: `final readonly` services, intent-revealing names, no `get…` that mutates, no comments unless a future reader would get the code wrong without one (one line), PHPMD-clean on every touched `src` file, `Response::HTTP_*` never a literal status.
- Commit format: `type(#1731): lower-case summary`. No Claude attribution anywhere.
- Native iOS client stays viable: GET, bearer auth, JSON in/out, `application/problem+json` on failure (the existing Social problem mappers already cover every exception `SocialPostFinder::find` throws).
- Frontend: the sync is silent. A failed or rate-limited like-state call never shows a toast or touches `EntriesStore.error`, and never fails the counts row.
- Every new test must be seen failing: each task's report pastes the red output (from before the implementation, or from deleting the line it covers) and the green output. See "Deletion checks" in each task.

## File Structure

| File | Change | Responsibility |
|---|---|---|
| `backend/src/Service/Social/SocialLikes.php` | modify | gains `syncFromNetwork(User, Entry): bool` |
| `backend/tests/Support/FakeMastodonNetwork.php` | modify | gains `knowsNoLike()` and `knowsTheLikeAs(string)` so tests can flip and vary the network's answer |
| `backend/tests/Service/Social/SocialLikesTest.php` | modify | unit tests for the sync |
| `backend/config/packages/rate_limiter.yaml` | modify | new `social_like_state` limiter |
| `backend/src/Controller/Api/EntryLikeController.php` | modify | new `GET /{id}/like` action |
| `backend/tests/Controller/Api/EntryLikeControllerTest.php` | modify | functional tests for the GET |
| `frontend/src/app/reader/reader-api.ts` | modify | `likeState(id)` |
| `frontend/src/app/reader/entry-social.ts` | modify | doc line on `EntryLikeDto` names the GET too |
| `frontend/src/app/reader/state/entries.store.ts` (+ spec) | modify | `adoptLike(entryId, isLiked)` |
| `frontend/src/app/reader/article/social-counts/social-counts.service.ts` (+ spec) | modify | fetch counts and like state together; adopt and baseline |

---

### Task 1: `SocialLikes::syncFromNetwork`

**Files:**
- Modify: `backend/src/Service/Social/SocialLikes.php`
- Modify: `backend/tests/Support/FakeMastodonNetwork.php`
- Test: `backend/tests/Service/Social/SocialLikesTest.php`

**Interfaces:**
- Consumes: `SocialPostFinder::find(User, Entry): SocialPostModel` (throws `NotASocialPostException`, `NoSocialAccountForNetworkException`, `SocialAccountNeedsReconnectException`, `SocialPostNotFoundException`, `SocialCredentialsRejectedException`, `SocialNetworkUnreachableException`); `SocialPostModel::$existingLikeId` (`?string`) and `::$account`; `SocialLikeRepository::findOneFor(User, Entry): ?SocialLike`.
- Produces: `public function syncFromNetwork(User $user, Entry $entry): bool` on `App\Service\Social\SocialLikes`. It returns whether the network reports the user's like and leaves `social_like` matching that answer.

- [ ] **Step 1: Extend the fake network**

In `backend/tests/Support/FakeMastodonNetwork.php`, replace the `bool $alreadyLiked` field with the like id the network reports, so a test can make the network answer "liked as X" or "not liked":

```php
    private ?string $existingLikeId = null;
```

```php
    public function knowsTheLike(): void
    {
        $this->existingLikeId = self::STATUS_ID;
    }

    public function knowsTheLikeAs(string $likeId): void
    {
        $this->existingLikeId = $likeId;
    }

    public function knowsNoLike(): void
    {
        $this->existingLikeId = null;
    }
```

and in `resolve()`:

```php
        return null === $this->existingLikeId ? $post : $post->alreadyLikedAs($this->existingLikeId);
```

(`knowsTheLike()` keeps its existing callers working; grep `knowsTheLike(` under `tests/` to confirm they still pass.)

- [ ] **Step 2: Write the failing tests**

Add these to `SocialLikesTest` (the clock in `likes()` reads `2026-10-13 06:30:00` UTC; the stored likes below use `2026-10-13 10:00:00`, so a kept row and a re-created row are told apart by `likedAt`):

```php
    public function testALikeMadeOnTheNetworkIsStoredWithTheIdItReports(): void
    {
        $account = $this->mastodonAccount();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $this->mastodon->knowsTheLikeAs('2041');

        self::assertTrue($this->likes()->syncFromNetwork($this->owner, $entry));

        self::assertSame(['resolve ' . self::STATUS_URL], $this->mastodon->calls);
        $like = $this->storedLikeOf($entry);
        self::assertSame($account->requireId(), $like->getSocialAccount()->requireId());
        self::assertSame('2041', $like->getRemoteId());
        self::assertEquals(new \DateTimeImmutable('2026-10-13 06:30:00'), $like->getLikedAt());
    }

    public function testALikeTakenBackOnTheNetworkIsForgottenWithoutAnUnlike(): void
    {
        $account = $this->mastodonAccount();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $this->storeLike($entry, $account, '110');

        self::assertFalse($this->likes()->syncFromNetwork($this->owner, $entry));

        self::assertSame(['resolve ' . self::STATUS_URL], $this->mastodon->calls);
        self::assertNull($this->likeRepository()->findOneFor($this->owner, $entry));
    }

    public function testAPostLikedNowhereStaysUnliked(): void
    {
        $this->mastodonAccount();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);

        self::assertFalse($this->likes()->syncFromNetwork($this->owner, $entry));

        self::assertSame(0, $this->likeRepository()->count([]));
    }

    public function testALikeTheNetworkConfirmsIsKeptAsItIs(): void
    {
        $account = $this->mastodonAccount();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $this->storeLike($entry, $account, '110');
        $this->mastodon->knowsTheLike();

        self::assertTrue($this->likes()->syncFromNetwork($this->owner, $entry));

        self::assertEquals(new \DateTimeImmutable('2026-10-13 10:00:00'), $this->storedLikeOf($entry)->getLikedAt());
    }

    public function testALikeStoredUnderAnotherIdIsReplacedByTheOneTheNetworkReports(): void
    {
        $account = $this->mastodonAccount();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $this->storeLike($entry, $account, '97');
        $this->mastodon->knowsTheLikeAs('2041');

        self::assertTrue($this->likes()->syncFromNetwork($this->owner, $entry));

        $like = $this->storedLikeOf($entry);
        self::assertSame('2041', $like->getRemoteId());
        self::assertEquals(new \DateTimeImmutable('2026-10-13 06:30:00'), $like->getLikedAt());
        self::assertSame(1, $this->likeRepository()->count([]));
    }

    public function testALikeStoredUnderAnotherAccountMovesToTheActingOne(): void
    {
        $acting = $this->mastodonAccount();
        $other = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::mastodonAs($this->owner, 'alice2@mastodon.example'),
        );
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $this->storeLike($entry, $other, '110');
        $this->mastodon->knowsTheLike();

        self::assertTrue($this->likes()->syncFromNetwork($this->owner, $entry));

        self::assertSame($acting->requireId(), $this->storedLikeOf($entry)->getSocialAccount()->requireId());
    }

    public function testAnUnreachableNetworkKeepsTheStoredLike(): void
    {
        $account = $this->mastodonAccount();
        $entry = $this->entryOn(self::FEED_URL, self::STATUS_URL);
        $this->storeLike($entry, $account, '110');
        $this->mastodon->failWith(new SocialNetworkUnreachableException('down'));

        $this->assertRefused(
            fn () => $this->likes()->syncFromNetwork($this->owner, $entry),
            SocialNetworkUnreachableException::class,
            'An unreachable network must reach the caller.',
        );

        $this->entityManager->clear();
        self::assertSame(1, $this->likeRepository()->count([]));
    }
```

and these helpers next to `entryOn()`:

```php
    private function storeLike(Entry $entry, SocialAccount $account, string $remoteId): void
    {
        $likedAt = new \DateTimeImmutable('2026-10-13 10:00:00');
        $this->entityManager->persist(new SocialLike($this->owner, $entry, $account, $remoteId, $likedAt));
        $this->entityManager->flush();
    }

    private function storedLikeOf(Entry $entry): SocialLike
    {
        $entryId = $entry->requireId();
        $this->entityManager->clear();
        $like = $this->likeRepository()->findOneBy(['entry' => $entryId]);
        self::assertNotNull($like);

        return $like;
    }
```

Check `SocialAccountRepository::ACTING_ORDER` before relying on `testALikeStoredUnderAnotherAccountMovesToTheActingOne`: the first-persisted account (`$acting`) must be the one `findActingOnNetwork` returns. If the order picks the other one, swap which account each variable names, not the assertion.

- [ ] **Step 3: Run the tests and see them fail**

Run: `cd backend && php bin/phpunit tests/Service/Social/SocialLikesTest.php`
Expected: the new tests FAIL with `Call to undefined method App\Service\Social\SocialLikes::syncFromNetwork()`; the existing ones pass.

- [ ] **Step 4: Implement**

In `SocialLikes`:

```php
    /** The user's like as the network reports it now, mirrored into the stored likes. */
    public function syncFromNetwork(User $user, Entry $entry): bool
    {
        $post = $this->posts->find($user, $entry);
        $stored = $this->likes->findOneFor($user, $entry);
        if (null !== $stored && !self::reportsTheSameLike($stored, $post)) {
            $this->entityManager->remove($stored);
            $this->entityManager->flush();
            $stored = null;
        }
        if (null === $post->existingLikeId) {
            return false;
        }
        if (null === $stored) {
            $this->store($user, $entry, $post->account, $post->existingLikeId);
        }

        return true;
    }

    private static function reportsTheSameLike(SocialLike $stored, SocialPostModel $post): bool
    {
        return $stored->getRemoteId() === $post->existingLikeId
            && $stored->getSocialAccount()->requireId() === $post->account->requireId();
    }
```

Extract the persist in `like()` into a shared private method and call it from both:

```php
    private function store(User $user, Entry $entry, SocialAccount $account, string $remoteId): void
    {
        $this->entityManager->persist(new SocialLike($user, $entry, $account, $remoteId, $this->clock->now()));
        $this->entityManager->flush();
    }
```

`like()` becomes `$this->store($user, $entry, $post->account, $result->likeId); $this->forgetCountsOf($entry);`. Add the `use` lines for `App\Entity\SocialAccount` and `App\Service\Social\Model\SocialPostModel`.

The remove is flushed before the insert on purpose. Doctrine runs inserts before deletes in one flush, which would hit `uniq_social_like_user_entry`. That is the one comment-worthy invariant here: put `// Flushed apart: one flush inserts before it deletes, which trips uniq_social_like_user_entry.` on the remove's flush line.

The sync does not drop the cached counts. Public counts already include a like made on the network.

- [ ] **Step 5: Run the tests and see them pass**

Run: `cd backend && php bin/phpunit tests/Service/Social/SocialLikesTest.php`
Expected: PASS.

- [ ] **Step 6: Deletion checks**

One at a time, make each change, run the file, paste the failure, and restore by re-applying the code (not `git checkout --`):
1. `reportsTheSameLike` returns only the remote-id half → `…AnotherAccount…` fails.
2. `reportsTheSameLike` returns only the account half → `…AnotherId…` fails.
3. Drop the `remove` block → `…TakenBack…` fails.
4. Replace `$post->existingLikeId` in `store(...)` with `'110'` → `…StoredWithTheIdItReports…` fails.
5. Make the `null === $stored` guard always store → `…ConfirmsIsKeptAsItIs…` fails (unique violation or new `likedAt`).

- [ ] **Step 7: Gates and commit**

Run: `cd backend && vendor/bin/phpcs src/Service/Social/SocialLikes.php tests/Service/Social/SocialLikesTest.php tests/Support/FakeMastodonNetwork.php && composer stan && vendor/bin/phpmd src/Service/Social/SocialLikes.php text phpmd.xml.dist` (use the ruleset `composer md` uses; read `composer.json`).

```bash
git add backend/src/Service/Social/SocialLikes.php backend/tests/Support/FakeMastodonNetwork.php backend/tests/Service/Social/SocialLikesTest.php
git commit -m "feat(#1731): mirror the user's like as the network reports it"
```

---

### Task 2: `GET /api/entries/{id}/like`

**Files:**
- Modify: `backend/config/packages/rate_limiter.yaml`
- Modify: `backend/src/Controller/Api/EntryLikeController.php`
- Test: `backend/tests/Controller/Api/EntryLikeControllerTest.php`

**Interfaces:**
- Consumes: `SocialLikes::syncFromNetwork(User, Entry): bool` (Task 1); `SocialLikeJson::of(bool)`.
- Produces: `GET /api/entries/{id}/like` → `200 {"isLiked": bool}`; route name `api_entries_like_state`. Failures are the same problem types as `PUT` (`social_account_missing` 409, `social_post_unsupported` 409, `social_post_not_found` 404, `social_credentials_rejected` 422, 404 for another user's entry, 429 over budget).

- [ ] **Step 1: Add the limiter**

In `rate_limiter.yaml`, after `social_counts`:

```yaml
        # Per user: opening a social post asks the user's own account whether they liked it there, one or two calls.
        social_like_state:
            policy: 'sliding_window'
            limit: 120
            interval: '5 minutes'
            cache_pool: cache.rate_limiter
```

- [ ] **Step 2: Write the failing tests**

In `EntryLikeControllerTest`, add a constant and a responder for a liked status:

```php
    /** Must match framework.rate_limiter.social_like_state.limit in rate_limiter.yaml. */
    private const int LIKE_STATE_BUDGET = 120;
```

```php
    private static function foundLiked(): MockResponse
    {
        return new MockResponse('{"statuses":[{"id":"110","favourited":true}]}');
    }
```

Tests:

```php
    public function testOpeningAPostLikedOnTheNetworkStoresTheLikeForTheList(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [self::SEARCH => self::foundLiked()]);
        $user = $this->signIn($client, 'like-state-liked@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->mastodonPostFor($user);

        $client->request('GET', sprintf('/api/entries/%d/like', $entryId));

        self::assertResponseIsSuccessful();
        self::assertSame(['isLiked' => true], $this->payload($client));
        self::assertSame([self::SEARCH], $this->routesAsked());
        self::assertSame(
            ['network' => 'mastodon', 'isLiked' => true, 'canLike' => true],
            $this->socialOf($client, $entryId),
        );
    }

    public function testOpeningAPostUnlikedOnTheNetworkForgetsTheStoredLike(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, [
            self::SEARCH => self::found(),
            self::FAVOURITE => self::accepted(),
        ]);
        $user = $this->signIn($client, 'like-state-unliked@example.test');
        $this->mastodonAccountOf($user);
        $entryId = $this->mastodonPostFor($user);
        $client->request('PUT', sprintf('/api/entries/%d/like', $entryId));

        $client->request('GET', sprintf('/api/entries/%d/like', $entryId));

        self::assertSame(['isLiked' => false], $this->payload($client));
        self::assertSame(0, $this->likeRows());
    }

    public function testTheLikeStateOfAPostWithoutAnAccountIsRefused(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'like-state-no-account@example.test');

        $client->request('GET', sprintf('/api/entries/%d/like', $this->mastodonPostFor($user)));

        $this->assertRejected($client, Response::HTTP_CONFLICT);
        self::assertSame('social_account_missing', $this->payload($client)['type']);
    }

    public function testTheLikeStateOfAnotherUsersEntryReadsAsMissing(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $entryId = $this->mastodonPostFor($this->factory()->create('like-state-stranger@example.test'));
        $user = $this->signIn($client, 'like-state-own@example.test');
        $this->mastodonAccountOf($user);

        $client->request('GET', sprintf('/api/entries/%d/like', $entryId));

        $this->assertRejected($client, Response::HTTP_NOT_FOUND);
        self::assertSame([], $this->http->requests);
    }

    public function testTheLikeStateHasItsOwnBudget(): void
    {
        $client = $this->clientRouting(self::ROUTED_HOSTS, []);
        $user = $this->signIn($client, 'like-state-budget@example.test');
        $path = sprintf('/api/entries/%d/like', $this->mastodonPostFor($user));
        for ($attempt = 0; $attempt < self::LIKE_STATE_BUDGET; ++$attempt) {
            $client->request('GET', $path);
            $this->assertRejected($client, Response::HTTP_CONFLICT);
        }

        $client->request('GET', $path);

        self::assertResponseStatusCodeSame(Response::HTTP_TOO_MANY_REQUESTS);
        $client->request('DELETE', $path);
        self::assertResponseIsSuccessful();
    }
```

The budget test spends its budget on refused calls (no account, so no network route is needed). The trailing `DELETE` proves the GET did not draw on `social_like`. Read `RateLimitGuard::enforceForUser` and confirm that the limit is consumed before the service throws. The controller below enforces before calling the service, so it is. If `assertRejected` cannot be called 120 times cheaply, assert the status only.

- [ ] **Step 3: Run and see them fail**

Run: `cd backend && php bin/phpunit tests/Controller/Api/EntryLikeControllerTest.php`
Expected: the new tests FAIL with 405 Method Not Allowed.

- [ ] **Step 4: Implement**

In `EntryLikeController`, add the constructor parameter `private RateLimiterFactoryInterface $socialLikeStateLimiter,` and the action:

```php
    #[Route('/{id}/like', name: 'api_entries_like_state', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function state(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $entry = $this->entryList->getOneSubscribedForUser($user->requireId(), $id);
        $this->rateLimitGuard->enforceForUser($this->socialLikeStateLimiter, $user);

        return new JsonResponse(SocialLikeJson::of($this->socialLikes->syncFromNetwork($user, $entry)));
    }
```

- [ ] **Step 5: Run and see them pass**

Run: `cd backend && php bin/phpunit tests/Controller/Api/EntryLikeControllerTest.php`
Expected: PASS.

- [ ] **Step 6: Deletion checks**

1. Bind `state()` to `$this->socialLikeLimiter` → `testTheLikeStateHasItsOwnBudget` fails (the trailing DELETE gets 429).
2. Swap the `enforceForUser` and `getOneSubscribedForUser` lines → no test should fail, because the order matches `like()`'s and is not the subject here. Confirm it doesn't and restore.
3. Return `SocialLikeJson::of(true)` → `…ForgetsTheStoredLike` fails.

- [ ] **Step 7: Gates, dev-log scan, commit**

Run `vendor/bin/phpcs` on both files, `composer stan`, and phpmd on the controller. Run `bin/console lint:container`. Check `bin/console debug:router api_entries_like_state` shows `GET`.

Read `docs/architecture.md` §6 and run its checklist against the new endpoint (bearer, stateless, JSON, problem+json, no browser-only inputs). Note the result in the task report.

```bash
git add backend/config/packages/rate_limiter.yaml backend/src/Controller/Api/EntryLikeController.php backend/tests/Controller/Api/EntryLikeControllerTest.php
git commit -m "feat(#1731): an endpoint that reads the user's like from the network"
```

---

### Task 3: Frontend asks for the like state on open

**Files:**
- Modify: `frontend/src/app/reader/reader-api.ts`
- Modify: `frontend/src/app/reader/entry-social.ts`
- Modify: `frontend/src/app/reader/state/entries.store.ts`
- Test: `frontend/src/app/reader/state/entries.store.spec.ts`
- Modify: `frontend/src/app/reader/article/social-counts/social-counts.service.ts`
- Test: `frontend/src/app/reader/article/social-counts/social-counts.service.spec.ts`

**Interfaces:**
- Consumes: `GET /api/entries/{id}/like` → `EntryLikeDto` (Task 2).
- Produces: `ReaderApi.likeState(id: number): Observable<EntryLikeDto>`; `EntriesStore.adoptLike(entryId: number, isLiked: boolean): void`. `SocialCountsService` keeps its public API (`state`, `load`, `reload`) and its state shape.

Why counts and like state travel together: `SocialCountsComponent.counts` adds `isLiked - likedAtLoad` to the loaded likes. Public counts already include a like made on the network. So if the sync flipped `isLiked` to true while `likedAtLoad` stayed false, the row would show one like too many. Taking the like-state answer as `likedAtLoad` keeps both on the network's word.

- [ ] **Step 1: API method and DTO doc**

`reader-api.ts`, next to `like`/`unlike`:

```ts
  likeState(id: number): Observable<EntryLikeDto> {
    return this.http.get<EntryLikeDto>(`${this.base}/api/entries/${id}/like`);
  }
```

`entry-social.ts`: change the `EntryLikeDto` doc line to ``/** The answer of `GET`/`PUT`/`DELETE /api/entries/{id}/like`. */``.

- [ ] **Step 2: Failing store tests**

In `entries.store.spec.ts`, inside the `describe` that holds the `setLiked` tests (reuse its `loadPost`/`post` helpers; read them first):

```ts
    it('adopts the like the network reports without a request', () => {
      loadPost();

      store.adoptLike(1, true);

      expect(store.entries()[0].social?.isLiked).toBe(true);
      ctrl.expectNone('https://api.test/api/entries/1/like');
    });

    it('adopts a like taken back on the network', () => {
      loadPost(true);

      store.adoptLike(1, false);

      expect(store.entries()[0].social?.isLiked).toBe(false);
    });

    it('lets a like on the wire win over the network report', () => {
      loadPost();
      store.setLiked(store.entries()[0], true, () => undefined);
      const like = ctrl.expectOne('https://api.test/api/entries/1/like');

      store.adoptLike(1, false);

      expect(store.entries()[0].social?.isLiked).toBe(true);
      like.flush({ isLiked: true });
    });

    it('keeps the row identity when the network agrees', () => {
      loadPost(true);
      const before = store.entries();

      store.adoptLike(1, true);

      expect(store.entries()).toBe(before);
    });
```

Also add one to the "copy shown inside another row (duplicates)" block:

```ts
      it('adopts the network like on the copy inside the row that holds it', () => {
        loadRowWithCopy();

        store.adoptLike(2, true);

        expect(store.entries()[0].duplicates?.[0].social?.isLiked).toBe(true);
      });
```

Run: `docker compose exec -T frontend npx jest src/app/reader/state/entries.store.spec.ts`
Expected: FAIL, `store.adoptLike is not a function` (or a TS error).

- [ ] **Step 3: Implement `adoptLike`**

In `EntriesStore`, below `setLiked`:

```ts
  /** The like the network reports, laid over the entry and its copies; a like still on the wire wins. */
  adoptLike(entryId: number, isLiked: boolean): void {
    if (this.likeInFlight(entryId)) return;
    this.patchEntries([entryId], (entry) =>
      entry.social && entry.social.isLiked !== isLiked
        ? { ...entry, social: { ...entry.social, isLiked } }
        : entry,
    );
  }
```

Run the spec again → PASS.

- [ ] **Step 4: Failing service tests**

In `social-counts.service.spec.ts`, extend the API double and provide a store double:

```ts
  let api: { socialCounts: jest.Mock; likeState: jest.Mock };
  let entries: { adoptLike: jest.Mock };
```

```ts
    api = {
      socialCounts: jest.fn(() => of(counts)),
      likeState: jest.fn(() => of({ isLiked: false })),
    };
    entries = { adoptLike: jest.fn() };
    TestBed.configureTestingModule({
      providers: [
        { provide: ReaderApi, useValue: api },
        { provide: EntriesStore, useValue: entries },
      ],
    });
```

New tests:

```ts
  it('asks the network for the like alongside the counts and adopts it', () => {
    api.likeState.mockReturnValue(of({ isLiked: true }));

    service.load(post(1, false));

    expect(api.likeState).toHaveBeenCalledWith(1);
    expect(entries.adoptLike).toHaveBeenCalledWith(1, true);
    expect(service.state(1)()).toMatchObject({ status: 'ok', counts, likedAtLoad: true });
  });

  it('counts from the entry when the like state cannot be had', () => {
    api.likeState.mockReturnValue(throwError(() => new Error('rate limited')));

    service.load(post(1, true));

    expect(entries.adoptLike).not.toHaveBeenCalled();
    expect(service.state(1)()).toMatchObject({ status: 'ok', likedAtLoad: true });
  });

  it('asks no like state for a post the reader cannot like', () => {
    service.load(anEntry({ id: 3, social: { network: 'mastodon', isLiked: false, canLike: false } }));

    expect(api.likeState).not.toHaveBeenCalled();
    expect(service.state(3)()).toMatchObject({ status: 'ok', likedAtLoad: false });
  });

  it('asks the like state again on a reload', () => {
    service.load(post());
    service.reload(post());

    expect(api.likeState).toHaveBeenCalledTimes(2);
  });

  it('adopts no late like once another account signs in', () => {
    const late = new Subject<{ isLiked: boolean }>();
    api.likeState.mockReturnValue(late);
    service.load(post(1));

    tokens.set('account-b.jwt');
    TestBed.tick();
    late.next({ isLiked: true });
    late.complete();

    expect(entries.adoptLike).not.toHaveBeenCalled();
  });
```

The existing tests stay green unchanged. Existing test 'remembers whether the post was liked when its counts were asked for' now uses `post(1, true)` while the like-state double answers `false`, so it would fail. Change that test to make the like-state call fail (`api.likeState.mockReturnValue(throwError(() => new Error('offline')))`) so it still covers the snapshot fallback, and rename it `'falls back to the entry's like when the network cannot say'`. If that duplicates 'counts from the entry when the like state cannot be had', delete the older one instead.

Run: `docker compose exec -T frontend npx jest src/app/reader/article/social-counts/social-counts.service.spec.ts`
Expected: the new tests FAIL.

- [ ] **Step 5: Implement in `SocialCountsService`**

```ts
import { Observable, catchError, forkJoin, map, of } from 'rxjs';
import { EntryLikeDto, SocialCountsDto, likeableSocial } from '../../entry-social';
import { EntriesStore } from '../../state/entries.store';
```

```ts
  private readonly entries = inject(EntriesStore);
```

`load` and `reload` pass the request pair through one helper:

```ts
  load(entry: CountedEntry): void {
    const state = this.stateFor(entry.id);
    if (isStale(state())) this.fetch(entry, state, this.api.socialCounts(entry.id));
  }

  reload(entry: CountedEntry): void {
    const state = this.stateFor(entry.id);
    if (state().status === 'loading') return;
    this.fetch(entry, state, this.api.socialCounts(entry.id, true));
  }
```

(unchanged) and `fetch` joins the like state:

```ts
  private fetch(
    entry: CountedEntry,
    state: WritableSignal<SocialCountsState>,
    counts$: Observable<SocialCountsDto>,
  ): void {
    const generation = this.generation;
    const likedBefore = entry.social?.isLiked ?? false;
    state.set({ status: 'loading' });
    forkJoin([counts$, this.networkLike(entry)]).subscribe({
      next: ([counts, networkLike]) => {
        if (generation !== this.generation) return;
        if (networkLike !== null) this.entries.adoptLike(entry.id, networkLike);
        const likedAtLoad = networkLike ?? likedBefore;
        state.set({ status: 'ok', counts, likedAtLoad, loadedAt: Date.now() });
      },
      error: () => {
        if (generation === this.generation) state.set({ status: 'failed', failedAt: Date.now() });
      },
    });
  }

  /** Whether the network says the reader liked the post; null when it cannot say. */
  private networkLike(entry: CountedEntry): Observable<boolean | null> {
    if (!likeableSocial(entry)) return of(null);
    return this.api.likeState(entry.id).pipe(
      map((like: EntryLikeDto) => like.isLiked),
      catchError(() => of(null)),
    );
  }
```

The generation guard covers the like adoption too, so a late answer from the previous account adopts nothing. Update the `likedAtLoad` doc on `SocialCountsState` to: ``/** `likedAtLoad`: whether the reader's like was in the counts when they loaded: the network's word, else the entry's. */``.

A failing like-state call must not fail the counts: `catchError` handles it. A failing counts call still fails the row, as today.

Run both specs → PASS.

- [ ] **Step 6: Deletion checks**

1. Drop `if (generation !== this.generation) return;` → the account-switch test fails.
2. Replace `networkLike ?? likedBefore` with `likedBefore` → 'asks the network for the like…' fails on `likedAtLoad`.
3. Remove `catchError` → 'counts from the entry when…' fails (state `failed`).
4. Remove `if (this.likeInFlight(entryId)) return;` in the store → 'lets a like on the wire win…' fails.
5. Remove the `isLiked !== isLiked` identity check → 'keeps the row identity…' fails.

Paste each red output.

- [ ] **Step 7: Gate and commit**

Run: `docker compose exec -T frontend npm run check`
Expected: green (ESLint, Prettier at 100 columns, Stylelint, tsc, Jest). Check the social-counts **component** spec still passes. It doubles the service, so it should.

```bash
git add frontend/src/app/reader/reader-api.ts frontend/src/app/reader/entry-social.ts frontend/src/app/reader/state/entries.store.ts frontend/src/app/reader/state/entries.store.spec.ts frontend/src/app/reader/article/social-counts/social-counts.service.ts frontend/src/app/reader/article/social-counts/social-counts.service.spec.ts
git commit -m "fix(#1731): an opened post shows the like its network reports"
```

---

### Task 4: Live check (controller, not a subagent task)

- [ ] Docker stack current (php, worker, frontend). Open `http://green-tara.local:4200/?subscription=1910&entry=628433-…`. The thumbs-up shows filled, the network tab shows `GET /api/entries/628433/like → {"isLiked":true}`, and the likes count matches meow.social's. Reload the list: the row's thumbs-up stays filled without opening it. `select * from social_like where entry_id=628433` holds one row.
- [ ] Scan today's dev log for errors from the new route.
- [ ] Run the full gates: `composer check`, `composer md`, `composer test:parallel`, MySQL leg `docker compose exec php composer test`, `composer infection:diff`, `npm run check` in the container.
