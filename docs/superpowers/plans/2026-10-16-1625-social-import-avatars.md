# Social Import Avatars Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Each account row of the "Import follows" dialog shows the account's real picture, keeping the initials when there is none or it fails to load (#1625).

**Architecture:** `FollowedAccountModel` gains a nullable `avatarUrl`. The two candidate sources read it from the network answer (Bluesky `avatar`, Mastodon `avatar_static` then `avatar`), passed through the existing `AbsoluteHttpUrl::orNull` guard. A no-longer-followed row has no network answer, so it takes the picture already stored on its profile feed (`Feed::$imageUrl`, which `ProfileImageSweep` fills with the person's avatar) — `SubscriptionFeedUrls` selects it and `ProfileFeedSubscriptionModel` carries it. `SocialImportJson` emits `avatarUrl` on every follow. The SPA carries it into `ImportRow` and renders it through `appProxiedImage`, exactly as `QuotedPostComponent` renders an author avatar: the browser loads the URL directly, the directive retries through the backend image proxy, and only a proxy failure falls back to the initials.

**Tech Stack:** Symfony 7.4 / PHP 8.4, PHPUnit 12; Angular 20 signals, Jest.

**Spec:** GitHub issue #1625 (`gh issue view 1625`).

## Global Constraints

- CLAUDE.md applies in full: Clean Code names, no new comments unless they clear the bar, `final readonly`, PHPMD-clean touched files, PHPStan max.
- HTTP statuses as `Response::HTTP_*`, never literals.
- Third-party pictures go through `appProxiedImage` (`frontend/src/app/shared/proxied-image/`); no new proxy route.
- No hex colours or ad-hoc `px` in `.scss`; component styles stay in the sibling `.scss`.
- Commit format `type(#1625): …`; no Claude attribution.
- Frontend tests run inside the Docker frontend container, or with `./node_modules/.bin/jest` (never `npx jest`) in this worktree.

---

### Task 1: Backend — candidates carry an avatar URL

**Files:**
- Modify: `backend/src/Service/Social/Model/FollowedAccountModel.php`
- Modify: `backend/src/Service/Social/CandidateSource/BlueskyCandidateSource.php` (`follows()`)
- Modify: `backend/src/Service/Social/Support/MastodonAccount.php` (`follow()`)
- Modify: `backend/src/Service/Social/Model/ProfileFeedSubscriptionModel.php`
- Modify: `backend/src/Service/Social/Model/ImportCandidateModel.php` (`noLongerFollowed()`)
- Modify: `backend/src/Repository/SubscriptionFeedUrls.php`
- Modify: `backend/src/Service/Social/ImportCandidates.php` (`profileFeedsOf()`)
- Modify: `backend/src/Http/SocialImportJson.php` (`follow()`)
- Test: `backend/tests/Service/Social/CandidateSource/BlueskyCandidateSourceTest.php`, `MastodonCandidateSourceTest.php`, `backend/tests/Repository/SubscriptionFeedUrlsTest.php`, `backend/tests/Service/Social/ImportCandidatesTest.php`, `backend/tests/Controller/Api/SocialImportControllerTest.php`, and every other `new FollowedAccountModel(` / `new ProfileFeedSubscriptionModel(` in `backend/tests` (`FollowMatcherTest.php`).

**Interfaces:**
- Produces: `FollowedAccountModel::__construct(string $identity, string $handle, ?string $displayName, string $feedUrl, ?string $avatarUrl)`; `ProfileFeedSubscriptionModel::__construct(int $subscriptionId, string $identity, string $feedUrl, string $title, ?string $imageUrl)`; `SubscriptionFeedUrls::forUser()` rows gain `feedImageUrl: ?string`; each follow in the candidates JSON gains `avatarUrl: string|null`, placed after `displayName`.

- [ ] **Step 1: Write the failing tests**

`BlueskyCandidateSourceTest::testFollowsArePagedByCursorAndReadAsDidKeyedProfileFeeds` — give Bob `'avatar' => 'https://cdn.bsky.app/img/avatar/bob.jpg'` and Carol `'avatar' => 'not a url'`; expect Bob's model with that URL as the fifth argument and Carol's with `null`.

`MastodonCandidateSourceTest` — extend `account()` with `avatar`/`avatar_static` keys via a new test:

```php
public function testAFollowCarriesItsStillAvatarAndFallsBackToTheAnimatedOne(): void
{
    $http = new RoutedHttpClient($this->routes([
        self::FOLLOWING => self::json([
            self::account('bob', 'https://mastodon.example/@bob', 'Bob') + [
                'avatar' => 'https://files.example/bob.gif',
                'avatar_static' => 'https://files.example/bob.png',
            ],
            self::account('carol', 'https://mastodon.example/@carol', 'Carol') + [
                'avatar' => 'https://files.example/carol.png',
            ],
            self::account('dave', 'https://mastodon.example/@dave', 'Dave') + ['avatar' => 'missing.png'],
        ]),
    ]));

    $follows = $this->source($http)->candidates(SocialAccounts::mastodon())->follows;

    self::assertSame(
        ['https://files.example/bob.png', 'https://files.example/carol.png', null],
        array_map(static fn (FollowedAccountModel $follow): ?string => $follow->avatarUrl, $follows),
    );
}
```

`SubscriptionFeedUrlsTest::testReadsTheOwnersSubscriptionsWithTheirFeedsInCreationOrder` — set `$titled->getFeed()->setImageUrl('https://cdn.test/titled.png')`; expect `'feedImageUrl' => 'https://cdn.test/titled.png'` on that row and `'feedImageUrl' => null` on the other.

`ImportCandidatesTest::testFollowsAreListedNewThenSubscribedThenNoLongerFollowedEachByHandle` — give the `gone` subscription's feed an image (`$gone->getFeed()->setImageUrl('https://cdn.test/gone.png')` then flush) and the `bob` follow an avatar (the test helper becomes `follow(string $did, string $handle, ?string $avatarUrl = null)`, Bob built with `'https://cdn.test/bob.jpg'`). Assert `$follows[3]->account->avatarUrl === 'https://cdn.test/gone.png'` and Bob's (`$follows[2]`) equals the URL the network gave.

`SocialImportControllerTest` — `row()` gains `'avatarUrl' => null` after `'displayName'`; in `testTheCandidatesListFollowsByStateThenHandleBesideListsAndFeeds` give `amy` an avatar through the stub follow and expect `'avatarUrl' => 'https://cdn.test/amy.jpg'` in her row override.

Update every remaining `new FollowedAccountModel(` and `new ProfileFeedSubscriptionModel(` in `backend/tests` to pass `null` for the new last argument.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd backend && php bin/phpunit tests/Service/Social tests/Repository/SubscriptionFeedUrlsTest.php tests/Controller/Api/SocialImportControllerTest.php`
Expected: FAIL — unknown named/too many constructor arguments and missing `avatarUrl`/`feedImageUrl` keys.

- [ ] **Step 3: Implement**

`FollowedAccountModel` — add `public ?string $avatarUrl,` as the last promoted parameter.

`BlueskyCandidateSource::follows()` — fifth argument `AbsoluteHttpUrl::orNull($node->string('avatar'))` (import `App\Service\Url\Support\AbsoluteHttpUrl`).

`MastodonAccount::follow()` — fifth argument `self::avatar($account)`:

```php
private static function avatar(JsonNodeModel $account): ?string
{
    return AbsoluteHttpUrl::orNull($account->string('avatar_static'))
        ?? AbsoluteHttpUrl::orNull($account->string('avatar'));
}
```

`SubscriptionFeedUrls::forUser()` — add `'f.imageUrl AS feedImageUrl'` to the select and `feedImageUrl: ?string` to both array shapes.

`ProfileFeedSubscriptionModel` — add `public ?string $imageUrl,` last. `ImportCandidates::profileFeedsOf()` passes `$subscription['feedImageUrl']`. `ImportCandidateModel::noLongerFollowed()` passes `$profileFeed->imageUrl` as the follow's avatar.

`SocialImportJson::follow()` — add `'avatarUrl' => $candidate->account->avatarUrl,` right after `'displayName'`.

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd backend && php bin/phpunit tests/Service/Social tests/Repository/SubscriptionFeedUrlsTest.php tests/Controller/Api/SocialImportControllerTest.php`
Expected: PASS.

- [ ] **Step 5: Gates on the touched files**

Run: `cd backend && composer cs && composer stan && composer md && composer tramp`
Expected: clean. `vendor/bin/infection` over the diff (`composer infection:diff`) keeps every new line killed — the `avatar_static` → `avatar` fallback and the `orNull` guards each have a test above.

- [ ] **Step 6: Commit**

```bash
git add backend
git commit -m "feat(#1625): import candidates carry the account's avatar"
```

### Task 2: Frontend — the dialog shows the avatar, initials as fallback

**Files:**
- Modify: `frontend/src/app/settings/social/social-import.service.ts` (`FollowCandidate`)
- Modify: `frontend/src/app/settings/social/social-import-rows.ts` (`ImportRow`, `toFollowRow`, `toCollectionRow`)
- Modify: `frontend/src/app/settings/social/social-import-dialog.component.ts` / `.html` / `.scss`
- Test: `frontend/src/app/settings/social/social-import-rows.spec.ts`, `social-import-dialog.component.spec.ts`, plus any other spec or fixture building a `FollowCandidate` (`grep -rn "FollowCandidate" frontend/src`).

**Interfaces:**
- Consumes: `avatarUrl: string | null` on each follow of `GET /api/me/social-accounts/{id}/import-candidates` (Task 1).
- Produces: `FollowCandidate.avatarUrl: string | null`; `ImportRow.avatarUrl: string | null` (always `null` on collection rows).

- [ ] **Step 1: Write the failing tests**

In `social-import-dialog.component.spec.ts`, `follow()` defaults gain `avatarUrl: null`; provide `{ provide: ImageProxyService, useValue: failingProxy }` with `const failingProxy = { recover: () => Promise.resolve('failed' as const) };` (import from `../../shared/proxied-image/image-proxy.service`). Add:

```ts
it('shows an account picture and falls back to the initials when it fails to load', async () => {
  const pictured = follow({ avatarUrl: 'https://cdn.test/bob.jpg' });
  const fixture = loaded(candidates({ follows: [pictured] }));
  const bob = () => byTestId(fixture, 'social-import-follow-did:plc:bob') as HTMLElement;

  const image = bob().querySelector<HTMLImageElement>('img.avatar')!;
  expect(image.getAttribute('src')).toBe('https://cdn.test/bob.jpg');
  expect(bob().querySelector('span.avatar')).toBeNull();

  image.dispatchEvent(new Event('error'));
  await Promise.resolve();
  fixture.detectChanges();

  expect(bob().querySelector('img.avatar')).toBeNull();
  expect(textOf(bob(), '.avatar')).toBe('B');
});
```

The existing "leads each account with its display name and initials" test keeps covering `avatarUrl: null` → initials, with no `<img>`: add `expect(bob.querySelector('img')).toBeNull();`.

In `social-import-rows.spec.ts`, the `row()` helper gains `avatarUrl: null`.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `docker compose exec -T frontend npm test -- social-import`
Expected: FAIL — type error on `avatarUrl`, and no `img.avatar`.

- [ ] **Step 3: Implement**

`FollowCandidate` — `readonly avatarUrl: string | null;` after `displayName`.
`ImportRow` — `readonly avatarUrl: string | null;` after `initials`. `toFollowRow` sets `avatarUrl: follow.avatarUrl`; `toCollectionRow` sets `avatarUrl: null`.

Component — import `ProxiedImageDirective` into `imports`, and add:

```ts
protected readonly failedAvatars = signal<ReadonlySet<string>>(new Set());

protected forgetAvatar(row: ImportRow): void {
  this.failedAvatars.update((keys) => new Set(keys).add(row.key));
}
```

Template — replace the `@else` initials branch:

```html
} @else if (row.avatarUrl && !failedAvatars().has(row.key)) {
  <img
    class="avatar"
    appProxiedImage
    [src]="row.avatarUrl"
    alt=""
    loading="lazy"
    decoding="async"
    referrerpolicy="no-referrer"
    (imageFailed)="forgetAvatar(row)"
  />
} @else {
  <span class="avatar" aria-hidden="true">{{ row.initials }}</span>
}
```

SCSS — add `object-fit: cover;` to the existing `.avatar` rule.

- [ ] **Step 4: Run the tests and the gate**

Run: `docker compose exec -T frontend npm test -- social-import`, then `cd frontend && npm run check` (inside the container if `node_modules` is not installed in the worktree).
Expected: PASS, gate clean.

- [ ] **Step 5: Look at it**

On the Docker stack, open Settings → Social → Import follows for a connected account and confirm pictures render, a row without one keeps its initials, and a broken URL falls back after the proxy retry. Screenshot for the PR.

- [ ] **Step 6: Commit**

```bash
git add frontend
git commit -m "feat(#1625): the import dialog shows each account's picture"
```

### Task 3: Verify and open the PR

- [ ] Run the MySQL leg: `docker compose exec php composer test` (from the checkout that owns the stack, or skip and rely on CI if that checkout is elsewhere — say so in the PR).
- [ ] Push `feature/1625-social-import-avatars`, open a PR into `develop` with `Closes #1625`, noting the no-longer-followed rows use the stored profile-feed picture.
