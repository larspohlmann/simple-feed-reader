# Mastodon "open original" on the reader's own instance — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** "Open original" on a Mastodon post opens it on the reader's own Mastodon instance, where they are signed in, instead of on the author's server (#2048).

**Architecture:** The backend already loads the reader's social accounts for every page of posts (`EntryListRowEnricher::socialStatesOf`). It adds one value there: for a Mastodon post, when the reader has a Mastodon account on another host, `https://<instance>/authorize_interaction?uri=<post address>`. Mastodon resolves that address and redirects to its local copy of the post. `EntryJson` sends it as `social.openUrl`. The frontend gets a new `openUrl` signal on `ArticlePageSource`, and both "open original" links use it. `url` stays as it is, because sharing must keep the canonical address.

**Tech Stack:** Symfony 7.4 / PHP 8.4 (PHPUnit 12), Angular 20 (Jest).

**Spec:** GitHub issue #2048 (`gh issue view 2048`).

## Global Constraints

- The account is the network's acting account: the reader's default Mastodon account, else the first one in the list (`isDefault` DESC, the same choice likes make).
- A post whose host equals the instance's host keeps its plain link (`openUrl` is `null`).
- No Mastodon account, a Bluesky post, or an article: `openUrl` is `null` and nothing changes.
- Sharing (share button, selection share) keeps `source.url()`, the canonical address.
- Clean Code rules in `CLAUDE.md` apply: no abbreviations, no single-letter names, `final` classes, a static-only helper lives in `Support/`, and no comment unless it clears the bar.
- Commit format: `type(#2048): lower-case summary`, with no attribution lines.
- Backend gates: `composer cs`, `composer stan`, `composer md` (PHPMD must be clean on every touched `src` file), `composer tramp`. Frontend gate: `npm run check` (from `frontend/`, Jest inside Docker: `docker compose exec -T frontend npm test`).

---

### Task 1: `OwnInstancePostUrl` helper

**Files:**
- Create: `backend/src/Service/Social/Support/OwnInstancePostUrl.php`
- Test: `backend/tests/Service/Social/Support/OwnInstancePostUrlTest.php`

**Interfaces:**
- Produces: `OwnInstancePostUrl::among(array $accounts, string $postAddress): ?string`, where `$accounts` is a `non-empty-list<SocialAccount>` (the reader's Mastodon accounts).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Support;

use App\Service\Social\Support\OwnInstancePostUrl;
use App\Tests\Support\SocialAccounts;
use PHPUnit\Framework\TestCase;

final class OwnInstancePostUrlTest extends TestCase
{
    private const string POST = 'https://mastodon.social/@Gargron/109';

    public function testAForeignPostOpensThroughTheReadersInstance(): void
    {
        self::assertSame(
            'https://home.example/authorize_interaction?uri=https%3A%2F%2Fmastodon.social%2F%40Gargron%2F109',
            OwnInstancePostUrl::among([SocialAccounts::mastodonOn('https://home.example')], self::POST),
        );
    }

    public function testATrailingSlashOnTheInstanceIsNotDoubled(): void
    {
        self::assertStringStartsWith(
            'https://home.example/authorize_interaction?uri=',
            (string) OwnInstancePostUrl::among([SocialAccounts::mastodonOn('https://home.example/')], self::POST),
        );
    }

    public function testAPostOnTheReadersOwnInstanceKeepsItsPlainLink(): void
    {
        self::assertNull(
            OwnInstancePostUrl::among([SocialAccounts::mastodonOn('https://Mastodon.Social')], self::POST),
        );
    }

    public function testTheDefaultAccountsInstanceWins(): void
    {
        $default = SocialAccounts::mastodonOn('https://second.example');
        $default->becomeDefault();

        self::assertStringStartsWith(
            'https://second.example/',
            (string) OwnInstancePostUrl::among([SocialAccounts::mastodonOn('https://first.example'), $default], self::POST),
        );
    }

    public function testWithoutADefaultTheFirstAccountActs(): void
    {
        self::assertStringStartsWith(
            'https://first.example/',
            (string) OwnInstancePostUrl::among(
                [SocialAccounts::mastodonOn('https://first.example'), SocialAccounts::mastodonOn('https://second.example')],
                self::POST,
            ),
        );
    }
}
```

- [ ] **Step 2: Run the test to watch it fail**

Run (from `backend/`): `php bin/phpunit tests/Service/Social/Support/OwnInstancePostUrlTest.php`
Expected: FAIL with `Class "App\Service\Social\Support\OwnInstancePostUrl" not found`.

- [ ] **Step 3: Write the minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Entity\SocialAccount;

/** A Mastodon post on the reader's own instance, which resolves the post's address and redirects to its local copy. */
final class OwnInstancePostUrl
{
    private const string AUTHORIZE_INTERACTION = '%s/authorize_interaction?uri=%s';

    /**
     * @param non-empty-list<SocialAccount> $accounts the reader's Mastodon accounts
     *
     * @return string|null null when the post already lives on that instance
     */
    public static function among(array $accounts, string $postAddress): ?string
    {
        $instance = rtrim(self::actingAmong($accounts)->getServiceUrl(), '/');
        if (self::hostOf($instance) === self::hostOf($postAddress)) {
            return null;
        }

        return sprintf(self::AUTHORIZE_INTERACTION, $instance, rawurlencode($postAddress));
    }

    /** @param non-empty-list<SocialAccount> $accounts */
    private static function actingAmong(array $accounts): SocialAccount
    {
        return array_find($accounts, static fn (SocialAccount $account): bool => $account->isDefault()) ?? $accounts[0];
    }

    private static function hostOf(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) ? strtolower($host) : null;
    }

    private function __construct()
    {
    }
}
```

- [ ] **Step 4: Run the test to watch it pass**

Run: `php bin/phpunit tests/Service/Social/Support/OwnInstancePostUrlTest.php`
Expected: PASS (5 tests).

- [ ] **Step 5: Run the gates on the new file**

Run: `composer cs && composer stan && composer md`
Expected: clean. `ServiceRoleRule` accepts a static-only helper in `Support/`.

- [ ] **Step 6: Commit**

```bash
git add backend/src/Service/Social/Support/OwnInstancePostUrl.php backend/tests/Service/Social/Support/OwnInstancePostUrlTest.php
git commit -m "feat(#2048): a mastodon post's address on the reader's own instance"
```

---

### Task 2: The enricher and `EntryJson` carry `openUrl`

**Files:**
- Modify: `backend/src/Repository/EntryListRowSocial.php`
- Modify: `backend/src/Repository/EntryListRowEnricher.php` (`socialStatesOf`, `postNetworksOf`)
- Modify: `backend/src/Http/EntryJson.php` (the `social` shape at line 51, `socialOf` at ~160)
- Test: `backend/tests/Repository/EntryListRowEnricherTest.php`, `backend/tests/Http/EntryJsonTest.php`
- Update expectations: `backend/tests/Controller/Api/EntryLikeControllerTest.php` (lines 66, 107, 154, 298, 308), `EntrySocialViewerControllerTest.php` (86, 304, 314), `EntrySocialPostControllerTest.php` (488, 495)

**Interfaces:**
- Consumes: `OwnInstancePostUrl::among(array $accounts, string $postAddress): ?string` (Task 1).
- Produces: `EntryListRowSocial::$openUrl` (`?string`, last constructor parameter, default `null`). The JSON `social` object gets `openUrl: string|null` as its last key.

- [ ] **Step 1: Write the failing enricher tests**

Add to `EntryListRowEnricherTest`. `SocialAccounts::mastodonOf($this->user)` sits on `https://mastodon.example`, and `self::POST` is `https://mastodon.social/@Gargron/`:

```php
    public function testAMastodonPostOpensOnTheReadersInstance(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->user));
        $post = $this->postOn($this->mastodonFeed(), self::POST . '8');

        self::assertSame(
            'https://mastodon.example/authorize_interaction?uri=' . rawurlencode(self::POST . '8'),
            $this->enrich([$this->row($post)])[0]->enrichment->social?->openUrl,
        );
    }

    public function testAMastodonPostOnTheReadersInstanceKeepsItsPlainLink(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::mastodonOf($this->user));
        $feed = new Feed('https://mastodon.example/@bob.rss');
        $this->entityManager->persist($feed);
        $post = $this->postOn($feed, 'https://mastodon.example/@bob/1');

        self::assertNull($this->enrich([$this->row($post)])[0]->enrichment->social?->openUrl);
    }

    public function testWithoutAMastodonAccountAPostKeepsItsPlainLink(): void
    {
        SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($this->user));
        $post = $this->postOn($this->mastodonFeed(), self::POST . '9');

        self::assertNull($this->enrich([$this->row($post)])[0]->enrichment->social?->openUrl);
    }
```

The second test's feed is built the same way as `testMyOwnPostSaysSoAndSomeoneElsesDoesNot`'s `https://mastodon.example/@alice.rss`, which that test already reads as a Mastodon post.

- [ ] **Step 2: Run them to watch them fail**

Run: `php bin/phpunit --filter 'OpensOnTheReadersInstance|KeepsItsPlainLink' tests/Repository/EntryListRowEnricherTest.php`
Expected: FAIL with an undefined property `$openUrl`.

- [ ] **Step 3: Add the property**

In `EntryListRowSocial`, change the docblock and the constructor:

```php
/** A row's post on its network: whether the reader can like it there, did, wrote it, and where they open it signed in. */
final readonly class EntryListRowSocial
{
    public function __construct(
        public SocialNetwork $network,
        public bool $isLiked,
        public bool $canLike,
        public bool $isOwn = false,
        public ?string $openUrl = null,
    ) {
    }
}
```

- [ ] **Step 4: Compute it in the enricher**

The loop needs the post address as well as the network. Rename `postNetworksOf` to `postAddressesOf` and have it return `array<int, SocialPostAddressModel>`:

```php
    /**
     * @param array<int, Entry> $entries
     *
     * @return array<int, SocialPostAddressModel> the address of each entry that is a social post, decided without a query
     */
    private static function postAddressesOf(array $entries): array
    {
        $addresses = [];
        foreach ($entries as $entryId => $entry) {
            $address = SocialPostAddress::of($entry);
            if (null !== $address) {
                $addresses[$entryId] = $address;
            }
        }

        return $addresses;
    }
```

`socialStatesOf` becomes:

```php
    private function socialStatesOf(array $entries, int $userId): array
    {
        $posts = self::postAddressesOf($entries);
        if ([] === $posts) {
            return [];
        }
        $accountsByNetwork = self::accountsByNetwork($this->socialAccounts->findAllForUserId($userId));
        $likeable = array_filter(
            $posts,
            static fn (SocialPostAddressModel $post): bool => isset($accountsByNetwork[$post->network->value]),
        );
        $likedIds = $this->socialLikes->likedEntryIdsAmong($userId, array_keys($likeable));

        $states = [];
        foreach ($posts as $entryId => $post) {
            $networkAccounts = $accountsByNetwork[$post->network->value] ?? [];
            $canLike = [] !== $networkAccounts;
            $states[$entryId] = new EntryListRowSocial(
                $post->network,
                isLiked: isset($likedIds[$entryId]),
                canLike: $canLike,
                isOwn: $canLike && null !== PostAuthor::accountAmong($entries[$entryId], $networkAccounts),
                openUrl: self::openUrlOf($post, $networkAccounts),
            );
        }

        return $states;
    }

    /** @param list<SocialAccount> $accounts the reader's accounts on the post's network */
    private static function openUrlOf(SocialPostAddressModel $post, array $accounts): ?string
    {
        if (SocialNetwork::Mastodon !== $post->network || [] === $accounts) {
            return null;
        }

        return OwnInstancePostUrl::among($accounts, $post->address);
    }
```

Add `use App\Service\Social\Model\SocialPostAddressModel;` and `use App\Service\Social\Support\OwnInstancePostUrl;`. `PersistenceKnowsNoServiceRule` allows a repository to name a Service model and a helper.

- [ ] **Step 5: Run the enricher test file**

Run: `php bin/phpunit tests/Repository/EntryListRowEnricherTest.php`
Expected: the three new tests PASS. Existing tests that `assertEquals` a whole `EntryListRowSocial` for a `self::POST` post now fail, because those posts get an `openUrl`. These are at lines 255, 257, 309 and 338. Add `openUrl: 'https://mastodon.example/authorize_interaction?uri=' . rawurlencode(<that post's url>)` to each. Lines 305 and 323 (own post on `mastodon.example`) and 347 (no account) keep `null`. Re-run until the file is green.

- [ ] **Step 6: Write the failing `EntryJson` test**

Add to `EntryJsonTest`:

```php
    public function testAMastodonPostCarriesWhereTheReaderOpensItSignedIn(): void
    {
        $row = $this->row(
            $this->entryTitled('Post'),
            new EntryListRowEnrichment(social: new EntryListRowSocial(
                SocialNetwork::Mastodon,
                false,
                true,
                openUrl: 'https://home.example/authorize_interaction?uri=x',
            )),
        );

        self::assertSame(
            'https://home.example/authorize_interaction?uri=x',
            JsonMappers::entryJson()->listRow($row)['social']['openUrl'] ?? null,
        );
    }
```

Run: `php bin/phpunit --filter testAMastodonPostCarriesWhereTheReaderOpensItSignedIn tests/Http/EntryJsonTest.php`
Expected: FAIL (the key is missing).

- [ ] **Step 7: Emit it**

In `EntryJson`, extend both array shapes (the class docblock at line 51 and the `@return` of `socialOf`) with `openUrl: string|null`, and add `'openUrl' => $social->openUrl,` as the last entry of the array `socialOf` returns.

- [ ] **Step 8: Update the exact-shape expectations**

Add `'openUrl' => null` (or the expected URL, wherever the test's post is on a host other than its account's instance) to every `assertSame` of a whole `social` array, and `openUrl: string|null` to the matching `@return` / `@var` shapes:
- `tests/Http/EntryJsonTest.php`, the three tests at lines 337–373
- `tests/Controller/Api/EntryLikeControllerTest.php` lines 66, 107, 154, 298, 308
- `tests/Controller/Api/EntrySocialViewerControllerTest.php` lines 86, 304, 314
- `tests/Controller/Api/EntrySocialPostControllerTest.php` lines 488, 495

Run each file once and read the actual `openUrl` from the failure diff. Confirm it matches the host rule before you paste it in.

- [ ] **Step 9: Run the backend suite and gates**

Run: `php bin/phpunit` and, at the same time, `docker compose exec php composer test:parallel`; then `composer check && composer md`.
Expected: all green, and PHPMD clean on `EntryListRowEnricher.php` and `EntryJson.php`.

- [ ] **Step 10: Commit**

```bash
git add backend/src backend/tests
git commit -m "feat(#2048): a mastodon post's json carries its address on the reader's instance"
```

---

### Task 3: The frontend opens `openUrl`

**Files:**
- Modify: `frontend/src/app/reader/entry-social.ts` (`EntrySocialDto`)
- Modify: `frontend/src/app/reader/article/article-page/article-page-source.ts` (`ArticlePageSource`)
- Modify: `frontend/src/app/reader/article/reader-view/entry-article-page.ts`
- Modify: `frontend/src/app/reader/article/link-reader/link-article-source.service.ts`
- Modify: `frontend/src/app/reader/article/article-page/article-page.component.html` (lines 57–61 and 116–120)
- Modify: `frontend/src/testing/article-page-source-testing.ts`
- Test: `frontend/src/app/reader/article/article-page/article-page.component.spec.ts`, `frontend/src/app/reader/article/reader-view/reader-view.component.spec.ts`
- Update literals: every spec that builds an `EntrySocialDto` (`grep -rln "canLike:" frontend/src`, 11 spec files)

**Interfaces:**
- Consumes: JSON `social.openUrl: string | null` (Task 2).
- Produces: `ArticlePageSource.openUrl: Signal<string | null>`, which is where "open original" goes. `url` stays the canonical address used for sharing.

- [ ] **Step 1: Write the failing article-page test**

In `article-page.component.spec.ts`, next to `'reports the open-original click'`:

```ts
  it('opens the original at the source’s open address but shares its own', () => {
    const element = mount((stub) => stub.openUrl.set('https://home.test/authorize_interaction?uri=a'));

    const original = element.querySelector<HTMLAnchorElement>('.meta a[href^="https://home.test/"]');
    expect(original?.textContent).toContain('Open original');
    expect(element.querySelector('.meta a[href="https://news.test/a"]')).toBeNull();
  });
```

In `StubArticlePageSource`, add `readonly openUrl = signal<string | null>(null);`, and in the constructor `this.openUrl.set(overrides.url ?? null);` so existing tests keep their `https://news.test/a` links.

- [ ] **Step 2: Write the failing reader-view tests**

In `reader-view.component.spec.ts`, using the file's `entry()` helper and `mount()`:

```ts
  it('opens a Mastodon post on the reader’s own instance', () => {
    const fixture = mount(
      entry({
        url: 'https://mastodon.social/@Gargron/1',
        social: {
          network: 'mastodon',
          isLiked: false,
          canLike: true,
          isOwn: false,
          openUrl: 'https://home.test/authorize_interaction?uri=x',
        },
      }),
    );

    const element = fixture.nativeElement as HTMLElement;
    expect(element.querySelector('.meta a[href="https://home.test/authorize_interaction?uri=x"]')).not.toBeNull();
  });

  it('opens an article at its own address', () => {
    const element = mount(entry()).nativeElement as HTMLElement;

    expect(element.querySelector('.meta a[href="https://x/1"]')).not.toBeNull();
  });
```

If the reader view needs more setup before it renders its meta line (see `'renders title, meta, content and decorates external links'`), copy that setup instead of inventing any.

Run (inside Docker): `docker compose exec -T frontend npx jest src/app/reader/article/article-page src/app/reader/article/reader-view`
Expected: the new tests FAIL (TypeScript: `openUrl` does not exist).

- [ ] **Step 3: Add the field and the signal**

`entry-social.ts`, in `EntrySocialDto`:

```ts
  /** Where "open original" goes: the post on the reader's own Mastodon instance, else null for the post's own URL. */
  openUrl: string | null;
```

`article-page-source.ts`, in `ArticlePageSource` after `url`:

```ts
  /** Where "open original" goes; `url` stays the address a share names. */
  readonly openUrl: Signal<string | null>;
```

`entry-article-page.ts`, after `url`:

```ts
  readonly openUrl = computed(() => this.entry().social?.openUrl ?? this.entry().url);
```

`link-article-source.service.ts`, after `url`:

```ts
  readonly openUrl = this.url;
```

- [ ] **Step 4: Point both links at it**

In `article-page.component.html`, change line 57 to `@if (source.openUrl(); as url) {` and line 116 to `@if (source.failure() && !body() && source.openUrl(); as url) {`. Leave the share uses of `source.url()` (the `.ts` line 79 and the `app-selection-share` at line 144) alone.

- [ ] **Step 5: Add `openUrl: null` to every `EntrySocialDto` literal**

Run `npx tsc -p tsconfig.spec.json --noEmit` from `frontend/` (or `npm run check`). Every spec that builds a `social: { … canLike … }` literal fails to compile. Add `openUrl: null` after `isOwn` in each one; these come from `grep -rln "canLike:" frontend/src`. Don't touch `EntryCommentDto` literals (`entry-comment.ts` has its own `isOwn`).

- [ ] **Step 6: Run the frontend gate**

Run (from the repo root): `docker compose exec -T frontend npm run check`
Expected: ESLint, Prettier, Stylelint, tsc and Jest all green.

- [ ] **Step 7: Commit**

```bash
git add frontend/src
git commit -m "feat(#2048): open original goes to a mastodon post on the reader's own instance"
```

---

### Task 4: Live check

- [ ] **Step 1:** With the Docker stack up (`docker compose up -d`) and the frontend on :4200, open a Mastodon post from a server other than the connected account's instance. Click "open original". The browser lands on `https://<your instance>/@author@their.server/<local id>`, signed in.
- [ ] **Step 2:** Open a post from your own instance. The link is the post's plain URL.
- [ ] **Step 3:** On the same post, the share button still shares the author-server URL.
- [ ] **Step 4:** Scan today's dev log: `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 50 | jq .`
