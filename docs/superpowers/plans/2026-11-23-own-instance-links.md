# Own-instance links Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every link to a Mastodon or Lemmy page opens on the reader's own instance. Lemmy joins
Mastodon, blog links with a safe shape are covered, and posts from the reader's Lemmy account link
straight to their home-instance page.

**Architecture:** A pure frontend function (`ownInstanceUrl`) decides where a URL opens, from the
URL, what the caller knows about it (network, link text, stored home address) and the reader's
instances. One directive applies it to every outbound reader anchor, and the article-body decorator
applies it to body links. The backend stores each Lemmy account post's home address on the entry,
serves it on the entry JSON, and serves the reader's known Lemmy hosts with the subscriptions list.

**Tech Stack:** Symfony 8.1 / Doctrine (PHP 8.5), Angular 20 standalone + signals, Jest, PHPUnit 12.

**Spec:** `docs/superpowers/specs/2026-11-23-own-instance-links-design.md`

## Global Constraints

- The Mastodon instance is the origin of the first `mastodon` account in acting order, and the Lemmy
  instance is the origin of the first `lemmy` account. That's `SubscriptionsStore.socialAccounts`,
  already in acting order.
- Never rewrite when the reader has no account on that network, or when the target is already on the
  reader's instance.
- Lemmy shapes on hosts that are neither hinted nor known stay untouched. A bare `/@name` without
  `@` link text stays untouched. A wrong rewrite is worse than a missed one.
- The thread address (`discussionUrl`), canonical keys and cross-post keys keep the origin `ap_id`.
  The home address is a separate nullable field and never lands on `canonical_item` or `lemmy_post`.
- The stored home address is used only when its origin equals the Lemmy instance the rule targets.
- iOS checklist (docs/architecture.md §6) for the new JSON fields: plain JSON, no browser-only inputs.
- Worktree: the Docker stack serves the main checkout. In this worktree, run PHPUnit natively
  (`php bin/phpunit`, SQLite), and run Jest as `./node_modules/.bin/jest <path>` after `npm ci` in
  `frontend/`. The MySQL leg, the Docker `npm run check`, e2e and the live checks run once the branch
  is in the main checkout (Task 7).

## Review Focus

1. A Medium-style `https://medium.com/@author/some-slug-1a2b` body link stays untouched. Pinned in the Task 4 table.
2. A `/post/123` link on an unknown host in a blog article stays untouched. Pinned in Task 4.
3. A Lemmy post from the reader's account, seen through a community RSS duplicate row, still gets the home address. Pinned in Task 2.
4. A reader with two Lemmy accounts, whose entry was fetched by the second one, gets the search page and not the foreign home address. Pinned in Task 4.
5. The accounts or hosts arrive after the article body has rendered, and body links still get rewritten. Pinned in Task 6, through the existing effect.

---

### Task 1: Store the home address of a Lemmy account post

**Files:**
- Modify: `backend/src/Service/Ingestion/Parser/Model/ParsedEntryModel.php`: add `public ?string $homeThreadUrl` (initialised null like `linkedUrl`) and `#[\NoDiscard] withHomeThreadUrl(?string $homeThreadUrl): self`.
- Modify: `backend/src/Service/Social/Lemmy/LemmyPostEntries.php` `parseEntry()`: chain `->withHomeThreadUrl($homeInstance . '/post/' . $postId)`.
- Modify: `backend/src/Entity/EntryDiscussion.php`: column `discussion_home_url` (length 2048, nullable); `recordHomeUrl(?string $homeUrl): void` keeps the stored value when given null, and drops a URL longer than 2048 (as `bounded()` does); plus `getHomeUrl(): ?string`.
- Modify: `backend/src/Entity/Entry.php`: `recordDiscussionHomeUrl(?string $homeUrl): void` and `getDiscussionHomeUrl(): ?string`, delegating to the embeddable.
- Modify: `backend/src/Service/Ingestion/Ingest/Factory/IngestedEntryFactory.php`: record it next to `setDiscussion()`.
- Modify: `backend/src/Service/Ingestion/Ingest/StoredEntryRefresher.php`: record it next to `recordCounts()` from `$applied`. This is how existing account posts gain it on their next timeline refresh, with no backfill. First confirm that the Lemmy timeline ingest reaches `refreshStoredEntries()` (through `Service/Ingestion/Refresh/IncomingPageStore.php`), and state the finding in the PR. If it does not, stop and report it, because the spec's existing-entries rule rests on it.
- Not in the backup (`Service/Operations/Backup/Dto/EntryLine.php`): the address belongs to the account's instance, and a refresh refills it.
- Create: `backend/migrations/Version20261123120000.php`: `ALTER TABLE entry ADD discussion_home_url VARCHAR(2048) DEFAULT NULL`, one branch per platform as the neighbouring migrations do. Plain `ADD COLUMN` does not rebuild the SQLite table, so the `entry_search` triggers stay. Assert that in the migration's own test if neighbouring migrations have one.
- Test: `backend/tests/Service/Social/Lemmy/LemmyPostEntriesTest.php`, `backend/tests/Service/Ingestion/Ingest/StoredEntryRefresherTest.php`, `backend/tests/Service/Ingestion/Ingest/Factory/IngestedEntryFactoryTest.php` (each where it exists; extend, don't duplicate).

**Interfaces:**
- Produces: `Entry::getDiscussionHomeUrl(): ?string` (Task 2), and `ParsedEntryModel::$homeThreadUrl`.

- [ ] **Step 1: Write failing tests**
  - `testAccountPostCarriesItsHomeAddress`: a post view with `post.id = 42`, `ap_id = https://lemmy.world/post/9`, account service URL `https://home.example/`. Assert `$entry->homeThreadUrl === 'https://home.example/post/42'` and `$entry->discussion->url === 'https://lemmy.world/post/9'`.
  - `testRefreshFillsTheHomeAddressOfAStoredEntry`: a stored entry with a null home URL, refreshed by a parsed entry with the same guid and `homeThreadUrl = 'https://home.example/post/42'`. Assert `getDiscussionHomeUrl()` returns it.
  - `testRefreshWithoutHomeAddressKeepsTheStoredOne`: the stored URL survives a parsed entry whose `homeThreadUrl` is null.
  - `testNewEntryStoresTheHomeAddress` in the factory test.
- [ ] **Step 2: Run them and see them fail.** Run `cd backend && php bin/phpunit --filter 'HomeAddress'`. Expected: FAIL (unknown property or method).
- [ ] **Step 3: Implement** the model field, the embeddable column and methods, the Entry delegates, the factory and refresher writes, and the migration.
- [ ] **Step 4: Verify.** `php bin/phpunit --filter 'HomeAddress'` passes. `bin/console doctrine:migrations:migrate --no-interaction` runs on a scratch SQLite database, and `doctrine:schema:validate` is clean. Point `DATABASE_URL` at a file in the scratchpad, and never touch the dev database.
- [ ] **Step 5: Gates.** Run `composer check` and `composer md` on the touched files, and PhpStorm `lint_files` (it cannot see `.claude/worktrees`, so note it for Task 7).
- [ ] **Step 6: Commit** `feat(#2496): a Lemmy account post stores its home-instance address`

### Task 2: Serve the home address on the entry JSON

**Files:**
- Modify: `backend/src/Http/EntryJson.php` `buildDiscussionRow()`: add `'discussionHomeUrl' => …`. It takes the row entry's `getDiscussionHomeUrl()`, else the first of `$row->duplicates` whose entry has one, else null. Update the row's phpstan array shape.
- Test: `backend/tests/Http/EntryJsonTest.php` (or the entry-list functional test that already asserts `discussionUrl`).

**Interfaces:**
- Consumes: `Entry::getDiscussionHomeUrl()` (Task 1).
- Produces: entry JSON field `discussionHomeUrl: string|null` (Task 5).

- [ ] **Step 1: Failing tests:**
  - `testDiscussionHomeUrlComesFromTheEntry`
  - `testDiscussionHomeUrlComesFromADuplicateWhenTheShownCopyHasNone`: the row entry has none, and its duplicate has `https://home.example/post/42`. Assert the JSON value is that URL.
  - `testDiscussionHomeUrlIsNullWithoutOne`
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement**, with a private static `findHomeUrl(EntryListRow $row): ?string` beside `buildDiscussionRow`.
- [ ] **Step 4: Run and see them pass.** Then run `composer check` and `composer md`.
- [ ] **Step 5: Commit** `feat(#2496): the entry JSON carries the reader's home address of a Lemmy post`

### Task 3: Serve the reader's known Lemmy hosts

**Files:**
- Create: `backend/src/Service/Social/Lemmy/LemmyHosts.php`, `final readonly`, with `collectForUser(User $user): list<string>`. It returns distinct lower-case hosts, sorted, from:
  1. the service URLs of the user's Lemmy accounts (`SocialAccountRepository::findAllOnNetwork($user, SocialNetwork::Lemmy)`),
  2. the URLs of feeds with `network = lemmy` that the user subscribes to, and
  3. the `lemmy_post.community_url` values on items that the user's subscribed feeds hold.
- Modify: `backend/src/Repository/FeedRepository.php`: add `findUrlsOnNetworkForUser(int $userId, SocialNetwork $network): list<string>`.
- Modify: `backend/src/Repository/LemmyPostRepository.php`: add `findCommunityUrlsForUser(int $userId): list<string>` (`DISTINCT community_url`, through `entry.canonical_item_id` and the subscription's feed).
- Modify: `backend/src/Http/SubscriptionJson.php` `list()`: add a fourth parameter `list<string> $lemmyHosts`, emitted as `'lemmyHosts'`.
- Modify: `backend/src/Controller/Api/SubscriptionController.php` `list()`: inject `LemmyHosts` and pass `collectForUser($user)`.
- Test: `backend/tests/Service/Social/Lemmy/LemmyHostsTest.php` (integration, SQLite), plus the subscriptions-list functional test.

**Interfaces:**
- Produces: subscriptions list JSON field `lemmyHosts: string[]` (Task 5).

- [ ] **Step 1: Failing tests:**
  - `testCollectsAccountFeedAndCommunityHostsOnce`: a Lemmy account `https://Home.Example/`, a Lemmy feed `https://lemmy.world/feeds/c/rust.xml`, and an entry whose item has community URL `https://programming.dev/c/php` plus a second item on `lemmy.world`. Expect `['home.example', 'lemmy.world', 'programming.dev']`.
  - `testIgnoresOtherUsersFeedsAndCommunities`
  - `testIgnoresNonLemmyFeeds`: a Mastodon feed's host is not in the list.
  - In the functional test, `lemmyHosts` is present and is `[]` for a user with no Lemmy data.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement** the repository queries (DQL or QueryBuilder in `src/Repository` only), the service and the JSON field. Reduce URLs to hosts with `parse_url(..., PHP_URL_HOST)`, dropping unparsable ones.
- [ ] **Step 4: Measure the real SQL.** This query runs on every subscriptions list load. Capture the two statements Doctrine emits (from the dev log or profiler), then run `EXPLAIN` on them against the dev MySQL, read-only: `docker compose exec mysql mysql … -e 'EXPLAIN …'`, run from the main checkout path, reading only. Expected: index access on `subscription.user_id`, `entry.feed_id`, and `lemmy_post.canonical_item_id`, with no full scan of `entry`. If it scans, fix the query before going on, and put the EXPLAIN in the PR.
- [ ] **Step 5: Run the tests and see them pass.** Then run `composer check` and `composer md`.
- [ ] **Step 6: Commit** `feat(#2496): the subscriptions list names the reader's known Lemmy hosts`

### Task 4: The link rule

**Files:**
- Modify: `frontend/src/app/reader/own-instance/own-instance-url.ts`. It replaces `ownInstancePostUrl` and `ownInstanceBodyUrl` with one export, and keeps `instanceOrigin`.
- Test: `frontend/src/app/reader/own-instance/own-instance-url.spec.ts`. Rewrite it around the new function, and carry over every existing case.

**Interfaces:**
- Produces:

```ts
export interface OwnInstances {
  mastodon: string | null; // origin, e.g. https://home.example
  lemmy: string | null;
  lemmyHosts: ReadonlySet<string>; // lower-case hosts
}
export interface LinkFacts {
  network?: SocialNetworkName | null; // the caller knows the URL belongs to this network
  text?: string;                      // the anchor's trimmed text (mentions, hashtags)
  homeUrl?: string | null;            // the stored home address of a Lemmy post
}
export function ownInstanceUrl(address: string, facts: LinkFacts, instances: OwnInstances): string;
export function instanceOrigin(serviceUrl: string): string | null; // unchanged
```

**Rule, in order** (anything non-http(s) or unparsable is returned unchanged):

1. `homeUrl` is set and its origin equals `instances.lemmy`: return `homeUrl`.
2. `network === 'mastodon'`: today's `ownInstancePostUrl` behaviour (any foreign address goes through `authorize_interaction`).
3. `network === 'lemmy'`, or the host is in `lemmyHosts`: apply the Lemmy shapes. When no shape matches, return the address unchanged.
4. No hint: apply the Mastodon safe shapes, then mention and hashtag by `text` (today's body rule).

**Shapes:**
- **Mastodon status:** `^/@[^/]+/\d+$` or `^/users/[^/]+/statuses/\d+$` → `home/authorize_interaction?uri=<encodeURIComponent(address)>`.
- **Lemmy post** `^/post/\d+$` and **Lemmy comment** `^/comment/\d+$` → `${lemmy}/search?q=${encodeURIComponent(address)}&type=All`.
- **Lemmy community** `^/c/([^/@]+)(?:@([^/]+))?$` → `${lemmy}/c/${name}@${domain ?? host}`.
- **Lemmy user:** the same with `/u/` → `${lemmy}/u/…`.
- An address whose origin is the targeted instance is returned unchanged in every branch.

- [ ] **Step 1: Write the failing table test** with `HOME_M = 'https://m.home'`, `HOME_L = 'https://l.home'`, and `lemmyHosts = new Set(['lemmy.world'])`. Each row gives the address, the facts and the expected result:

| address | facts | expected |
|---|---|---|
| `https://lemmy.world/post/9` | `{ homeUrl: 'https://l.home/post/42' }` | `https://l.home/post/42` |
| `https://lemmy.world/post/9` | `{ homeUrl: 'https://other.home/post/42' }` | `https://l.home/search?q=https%3A%2F%2Flemmy.world%2Fpost%2F9&type=All` |
| `https://feddit.org/post/9` | `{ network: 'lemmy' }` | the same search form for that address |
| `https://feddit.org/post/9` | `{}` | unchanged |
| `https://lemmy.world/comment/5` | `{}` | the search form |
| `https://lemmy.world/c/rust` | `{}` | `https://l.home/c/rust@lemmy.world` |
| `https://lemmy.world/c/rust@programming.dev` | `{}` | `https://l.home/c/rust@programming.dev` |
| `https://lemmy.world/u/alice` | `{}` | `https://l.home/u/alice@lemmy.world` |
| `https://l.home/post/3` | `{ network: 'lemmy' }` | unchanged |
| `https://lemmy.world/some/article` | `{ network: 'lemmy' }` | unchanged |
| `https://mastodon.social/@alice/1234` | `{}` | `https://m.home/authorize_interaction?uri=…` |
| `https://pixel.example/users/bob/statuses/77` | `{}` | authorize_interaction |
| `https://medium.com/@author/some-slug-1a2b` | `{}` | unchanged |
| `https://medium.com/@author` | `{}` | unchanged |
| `https://mastodon.social/@alice` | `{ text: '@alice' }` | authorize_interaction (today's mention rule) |
| `https://mastodon.social/tags/rust` | `{ text: '#rust' }` | `https://m.home/tags/rust` |
| `https://mastodon.social/@Gargron` | `{ network: 'mastodon' }` | authorize_interaction |

  Add a `describe` block with both instances null, where every row above returns the address unchanged. Add one more case: the Mastodon instance is null, the Lemmy one is set, and `https://mastodon.social/@alice/1234` comes back unchanged.
- [ ] **Step 2: Run and see it fail.** `cd frontend && ./node_modules/.bin/jest src/app/reader/own-instance/own-instance-url.spec.ts`
- [ ] **Step 3: Implement** `ownInstanceUrl` as small named helpers, one per network (`mastodonTarget`, `lemmyTarget`), each returning `string | null`.
- [ ] **Step 4: Run and see it pass.**
- [ ] **Step 5: Commit** `feat(#2496): one link rule moves Mastodon and Lemmy links to the own instance`

### Task 5: The reader's instances and an entry's link facts

**Files:**
- Modify: `frontend/src/app/reader/state/subscriptions.store.ts`. Add `readonly lemmyHosts = signal<readonly string[]>([])`, set it in `applyCounts()` from `response.lemmyHosts`, and clear it where `socialAccounts` is cleared. Add `lemmyHosts: string[]` to the `SubscriptionsResponse` type.
- Modify: `frontend/src/app/reader/models.ts`: `EntryDto.discussionHomeUrl: string | null`. Update the test fixtures and builders that construct an `EntryDto`.
- Modify: `frontend/src/app/reader/own-instance/own-instance-links.service.ts`:
  - `readonly instances: Signal<OwnInstances>` (the first Mastodon account, the first Lemmy account, and the hosts as a Set)
  - `open(address: string, facts: LinkFacts = {}): string`
  - drop `instance` and `instanceFor`, and update their callers (Task 6)
- Create: `frontend/src/app/reader/own-instance/entry-link-facts.ts`:
  - `entryLinkNetwork(entry: Pick<EntryDto, 'social' | 'community' | 'discussionHomeUrl'>): SocialNetworkName | null` returns `social?.network`, else `'lemmy'` when `community` or `discussionHomeUrl` is set, else null.
  - `entryOpenUrl(entry: Pick<EntryDto, 'url' | 'social' | 'community' | 'discussionUrl' | 'discussionHomeUrl'>, links: OwnInstanceLinks): string | null` is the "Open original" address: `url` through the rule with the entry's network when `url` is set, else `discussionUrl` through the rule with `{ network, homeUrl: discussionHomeUrl }`, else null.
- Test: `own-instance-links.service.spec.ts` (extend) and `entry-link-facts.spec.ts`.

**Interfaces:**
- Consumes: `ownInstanceUrl`, `OwnInstances`, `LinkFacts` (Task 4), and the JSON fields from Tasks 2 and 3.
- Produces: `OwnInstanceLinks.instances`, `OwnInstanceLinks.open(address, facts)`, `entryLinkNetwork`, `entryOpenUrl` (Task 6).

- [ ] **Step 1: Failing tests:**
  - The service uses the first Lemmy account's origin and the store's hosts: `instances().lemmy === 'https://l.home'`, and `instances().lemmyHosts.has('lemmy.world')`.
  - Its `open()` forwards facts: with `{ homeUrl: 'https://l.home/post/42' }` it returns that URL.
  - `entryOpenUrl` for a Lemmy account post (`url: null`, `discussionUrl: 'https://lemmy.world/post/9'`, `discussionHomeUrl: 'https://l.home/post/42'`) returns `https://l.home/post/42`.
  - For a Lemmy RSS entry (no community, no home URL, host known) it returns the search form.
  - For a Mastodon post it matches today's result.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run and see them pass**, together with the existing `own-instance-links.service.spec.ts`.
- [ ] **Step 5: Commit** `feat(#2496): the reader knows its Lemmy instance, hosts and each entry's link facts`

### Task 6: Every outbound reader link goes through the rule

**Files:**
- Create: `frontend/src/app/reader/own-instance/own-instance-href.directive.ts`. It's a standalone directive with selector `a[appOwnInstanceHref]`:
  - inputs `appOwnInstanceHref: string | null` (required), `linkNetwork: SocialNetworkName | null` (default null) and `linkHomeUrl: string | null` (default null)
  - host binding `[attr.href]` set to `links.open(address, { network, homeUrl })` as a `computed`. It passes no `text`: mentions and hashtags only occur in body links, which the decorator handles.
- Modify: `frontend/src/app/reader/article/decorators/own-instance-body-links.ts`. Rename it to `openLinksOnOwnInstance(host: HTMLElement, rewrite: (href: string, text: string) => string): void`. Update its spec.
- Modify: `frontend/src/app/reader/article/article-content/article-body.service.ts` `openLinksOnOwnInstance()`. Read `this.links.instances()` (so a late account or late hosts re-run it), and run for every entry. Pass `(href, text) => this.links.open(href, { network: parts.network(), text })`.
- Modify: `frontend/src/app/reader/article/reader-view/entry-article-page.ts` `openUrl`, to `entryOpenUrl(this.entry(), this.links)`.
- Modify each anchor below to `[appOwnInstanceHref]="…"` with the hint shown. Drop its `[href]`/`[attr.href]`, and import the directive in the component:

| Template | Anchor | Hint |
|---|---|---|
| `reader/quoted-post/quoted-post.component.html` | both post links | `[linkNetwork]="network()"` |
| `reader/entry/entry-source/entry-source.component.html` | profile link | `[linkNetwork]="current.social?.network ?? null"` |
| `reader/entry/entry-source/entry-source.component.html` | community link | `linkNetwork="lemmy"` |
| `reader/article/entry-comments/entry-comments.component.html` | author and comment links, `.all`, `.discussion` | `[linkNetwork]="linkNetwork()"`, where `linkNetwork = computed(() => entryLinkNetwork(this.entry()))` replaces `socialNetwork` for links; `.all` and `.discussion` also get `[linkHomeUrl]="entry().discussionHomeUrl"`. Add `'community' \| 'discussionHomeUrl'` to the component's entry `Pick`. |
| `reader/article/entry-comments/entry-discussion-link.component.html` | the external discussion link | the entry's network and `[linkHomeUrl]="e.discussionHomeUrl"` |
| `reader/notifications/notification-row/notification-row.component.html` | the `_blank` row | none |
| `reader/list/magazine/story-sources/story-sources.component.html` | `.face` | none |
| `reader/link-card/link-card.component.html` | the card | none |
| `reader/feeds/social-picker/social-search-form.component.html` | `.post` | the search's network, if the component holds it, else none |
| `reader/shell/feed-intro/feed-intro.component.html`, `reader/feeds/manage/edit-subscription-dialog.component.html` | site link | none |
| `reader/article/article-page/article-page.component.html` | both `openUrl` anchors | none. They already carry the resolved `openUrl`, so leave them on `[href]` and allow-list them in the guard. |
| `reader/article/paywall-notice/paywall-notice.component.html` | paywall link | none |

- Create: `frontend/src/app/reader/own-instance/outbound-links.spec.ts`, a guard. It reads every `.html` under `src/app/reader` with `fs`, finds each `<a …>` start tag containing `target="_blank"`, and fails, naming the file, if one lacks `appOwnInstanceHref`. Allow-list exactly `shell/sidebar/sidebar-foot.component.html` (release notes and GitHub links) and the two `article-page.component.html` anchors (matched by `openUrl`), each with a one-line reason.
- Unchanged, and say so in the PR: `shared/open-external.ts`. Its callers open the reader's own just-sent post and share targets, never a foreign post.

**Interfaces:**
- Consumes: `OwnInstanceLinks.open`, `entryLinkNetwork`, `entryOpenUrl` (Task 5).

- [ ] **Step 1: Failing tests:**
  - A directive spec with a host component: an anchor with `appOwnInstanceHref="https://lemmy.world/c/rust"` and a Lemmy account `https://l.home` renders `href="https://l.home/c/rust@lemmy.world"`. After the accounts change, the href updates.
  - The guard spec fails on the current templates. Run it first and paste the named files into the task log.
  - The body-links spec: a blog body link `https://mastodon.social/@alice/1234` is rewritten, and `https://example.com/post/3` is not.
  - `entry-article-page` "Open original" for a Lemmy account post is the home address.
  - `article-body.service` spec: with the body rendered before a Lemmy account arrives, a `https://lemmy.world/c/rust` body link (host known) is rewritten once the accounts and hosts are set. This pins Review Focus 5.
- [ ] **Step 2: Run and see them fail.**
- [ ] **Step 3: Implement** the directive, then migrate the anchors table row by row, then the body decorator and `openUrl`.
- [ ] **Step 4: Run and see them pass.** Run every spec under `src/app/reader/own-instance`, `article`, `entry`, `quoted-post`, `notifications`, `list/magazine`, `link-card`, `feeds` and `shell` with `./node_modules/.bin/jest`. Then run `npx tsc -p tsconfig.app.json --noEmit`, `npx tsc -p tsconfig.spec.json --noEmit`, `npx eslint`, `npx prettier --check` and `npx stylelint` on the touched files.
- [ ] **Step 5: Commit** `feat(#2496): every outbound reader link opens on the own instance`

### Task 7: Verification in the main checkout

Run this only once Lars has OK'd moving the branch into the main checkout. Another session may hold it, so check `git status` there first.

- [ ] **Step 1: MySQL leg and migration.** Run `docker compose exec php composer test:parallel` and `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction`, then `doctrine:schema:validate`.
- [ ] **Step 2: Frontend gate.** `docker compose exec -T frontend npm run check`, alone, never alongside the MySQL leg.
- [ ] **Step 3: Infection.** `composer infection:diff` on the backend change.
- [ ] **Step 4: Live check on :4200** (built-in browser), with Lars's Lemmy and Mastodon accounts:
  - Open original on a Lemmy account post lands on `l.home/post/<id>`.
  - Open original on a Lemmy community RSS post lands on the search page. Confirm Lemmy's search page shows the resolved post for a logged-in user; this is the spec's one unverified assumption. If it does not resolve, stop and report to Lars before going on.
  - The community pill and comment authors open on the home instance.
  - A blog article with a `/@name/123` link opens through `authorize_interaction`.
  - A notification row for a Mastodon status opens through `authorize_interaction`.
- [ ] **Step 5: Scan the dev log.** `ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq 'select(.level_name != "DEBUG" and .level_name != "INFO")'`
