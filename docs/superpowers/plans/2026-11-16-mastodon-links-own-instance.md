# Mastodon Links on the Reader's Own Instance Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On a Mastodon entry, every link into Mastodon (poster, comment author and timestamp, quote, nested quote, body mentions and hashtags, Open original) opens on the reader's own instance.

**Architecture:** The sidebar payload's account refs gain `serviceUrl`, in acting order. A root frontend service `OwnInstanceLinks` reads the acting Mastodon account's origin and rewrites addresses with two pure functions. Templates call the service where Mastodon links render. A new article decorator rewrites body mentions and hashtags. The server-side `OwnInstancePostUrl` / `social.openUrl` is deleted, so the rule has one home.

**Tech Stack:** Symfony 8.1 / PHP 8.5 (PHPUnit), Angular 20 standalone + signals (Jest).

**Spec:** `docs/superpowers/specs/2026-11-16-mastodon-links-own-instance-design.md`

## Global Constraints

- Only entries whose `social.network === 'mastodon'` get rewritten links; Bluesky, Lemmy and RSS entries are untouched.
- Post or profile address → `<instance>/authorize_interaction?uri=<encodeURIComponent(address)>`.
- Hashtag address `<host>/tags/<name>` → `<instance>/tags/<name>` (the `<name>` segment copied as it appears).
- No Mastodon account, an unparsable `serviceUrl`, or an address whose origin equals the instance → the address unchanged.
- The instance is the origin of the **first** Mastodon ref in `socialAccounts` (the server sends them in acting order). Its status does not matter.
- **Refinement of the spec, body links only:** a body link counts as a mention when its text starts with `@` and its path is `/@<name>` or `/users/<name>`; as a hashtag when its text starts with `#` and its path is `/tags/<name>`. Status links in the body stay as they are. Reason: a toot can link `medium.com/@author`; only Mastodon's own mention and hashtag anchors start their text with `@`/`#`.
- Clean Code rules of `CLAUDE.md` apply to the PHP; component styles stay in `.scss`; no new comments beyond the CLAUDE.md bar.
- This is a worktree with `+` in its path: run Jest as `./node_modules/.bin/jest --testPathIgnorePatterns "/node_modules/" "/e2e/" <paths>` from `frontend/`, never `npx jest`.

## Review Focus

1. A `serviceUrl` with a path or trailing slash (`https://home.example/`) — the instance must be the bare origin, so the redirect is `https://home.example/authorize_interaction?...` (Task 2 test).
2. An author on the reader's own instance, including a different-case host — `new URL().origin` lowercases, compare origins, not strings (Task 2 test).
3. A body link to `https://medium.com/@author` inside a Mastodon post — stays unchanged (Task 4 test).
4. A body mention whose href carries a query or fragment — still a mention; the whole href is the `uri` (Task 4 test).
5. A Bluesky entry's poster and quote — unchanged even when the reader has a Mastodon account (Task 3 test).

---

### Task 1: Account refs carry their instance, acting account first

**Files:**
- Modify: `backend/src/Http/SubscriptionJson.php` (`socialAccount()`, the `list()` docblock)
- Modify: `backend/src/Controller/Api/SubscriptionController.php:59` (call `findAllForUserIdInActingOrder($user->requireId())` instead of `findAllForUser($user)`)
- Modify: `frontend/src/app/reader/social-feed.ts` (`SocialAccountRefDto`)
- Test: `backend/tests/Http/SubscriptionJsonListTest.php`, `backend/tests/Controller/Api/SubscriptionSocialListTest.php`

**Interfaces:**
- Produces: each `socialAccounts[]` entry is `{id, network, handle, status, serviceUrl}`; within a network the acting account comes first. Frontend `SocialAccountRefDto` gains `serviceUrl: string`.

- [ ] **Step 1:** In `SubscriptionJsonListTest`, add `'serviceUrl' => <the account's getServiceUrl()>` to every expected ref (both existing tests). In `SubscriptionSocialListTest`, add `testTheDefaultMastodonAccountComesFirst`: two Mastodon accounts, the later-connected one `isDefault`; assert the first Mastodon ref in the response is the default one.
- [ ] **Step 2:** Run `php bin/phpunit tests/Http/SubscriptionJsonListTest.php tests/Controller/Api/SubscriptionSocialListTest.php` — expect FAIL (missing key, wrong order).
- [ ] **Step 3:** Add `'serviceUrl' => $account->getServiceUrl()` to `SubscriptionJson::socialAccount()` and switch the controller to the acting-order query. Update the `@param` text to say the list is in acting order.
- [ ] **Step 4:** Re-run — expect PASS. Add `serviceUrl` to `SocialAccountRefDto` and fix every frontend spec fixture that builds one (`grep -rn "status: 'connected'" frontend/src/app` finds them) so `tsc` passes.
- [ ] **Step 5:** Commit `feat(#2261): account refs carry their instance, acting account first`.

### Task 2: `OwnInstanceLinks` — the one rule

**Files:**
- Create: `frontend/src/app/reader/own-instance/own-instance-url.ts` (pure functions)
- Create: `frontend/src/app/reader/own-instance/own-instance-links.service.ts`
- Test: `frontend/src/app/reader/own-instance/own-instance-url.spec.ts`, `own-instance-links.service.spec.ts`

**Interfaces:**
- Consumes: `SubscriptionsStore.socialAccounts` (Task 1 shape).
- Produces:
  - `instanceOrigin(serviceUrl: string): string | null`
  - `ownInstancePostUrl(address: string, instance: string | null): string` — post or profile
  - `ownInstanceBodyUrl(href: string, text: string, instance: string | null): string` — mention/hashtag rule from Global Constraints, anything else unchanged
  - `@Injectable({ providedIn: 'root' }) class OwnInstanceLinks` with `readonly instance: Signal<string | null>` and `open(address: string, network: SocialNetworkName | null): string` (rewrites only for `'mastodon'`, else returns `address`)

- [ ] **Step 1: Write failing specs.** `own-instance-url.spec.ts`:
  - `ownInstancePostUrl('https://mastodon.social/@Gargron', 'https://home.example')` → `'https://home.example/authorize_interaction?uri=https%3A%2F%2Fmastodon.social%2F%40Gargron'`
  - same address with instance `null` → unchanged; address `https://HOME.example/@me/1` with instance `https://home.example` → unchanged
  - `instanceOrigin('https://home.example/')` → `'https://home.example'`; `instanceOrigin('not a url')` → `null`
  - `ownInstanceBodyUrl('https://mastodon.social/tags/rust', '#rust', 'https://home.example')` → `'https://home.example/tags/rust'`
  - `ownInstanceBodyUrl('https://mastodon.social/@alice', '@alice', …)` and `('https://pixel.example/users/bob', '@bob@pixel.example', …)` → the `authorize_interaction` form
  - `ownInstanceBodyUrl('https://medium.com/@author', 'medium.com/@author', …)` → unchanged; `('https://mastodon.social/@alice/123', 'https://mastodon.social/@alice/123', …)` → unchanged
  - `ownInstanceBodyUrl('https://mastodon.social/@alice?x=1#y', '@alice', …)` → `uri=` carries the whole href, encoded
  
  `own-instance-links.service.spec.ts`: with `socialAccounts` set to `[bluesky, mastodon(serviceUrl 'https://home.example/'), mastodon2]`, `open(addr, 'mastodon')` uses `https://home.example`; `open(addr, 'bluesky')` and `open(addr, null)` return `addr`; with no Mastodon ref, `open(addr, 'mastodon')` returns `addr`.
- [ ] **Step 2:** Run the two specs — expect FAIL (modules missing).
- [ ] **Step 3:** Implement. `instance` is a `computed` over `socialAccounts()`: the first ref with `network === 'mastodon'`, through `instanceOrigin`. Path matching on `new URL(href).pathname` with `^/@[^/]+$`, `^/users/[^/]+$`, `^/tags/[^/]+$`; an unparsable href returns unchanged.
- [ ] **Step 4:** Re-run — expect PASS.
- [ ] **Step 5:** Commit `feat(#2261): one rule for Mastodon links on the reader's instance`.

### Task 3: Structured links go through the rule

**Files:**
- Modify: `frontend/src/app/reader/entry/entry-source/entry-source.component.{ts,html}` (poster link)
- Modify: `frontend/src/app/reader/article/entry-comments/entry-comments.component.{ts,html}` (author link, `.when` link)
- Modify: `frontend/src/app/reader/quoted-post/quoted-post.component.{ts,html}` — new `readonly network = input<SocialNetworkName | null>(null)`; `.open` and `.nested` links
- Modify: callers that pass `[network]="<entry>.social?.network ?? null"`: `reader-view.component.html:113`, `entry-comments.component.html:94` (the entry's network), `entry-thread.component.html:23`, `entry-quoting.component.html:18`
- Modify: `frontend/src/app/reader/article/reader-view/entry-article-page.ts:42` — `openUrl` becomes `links.open(entry.url, entry.social?.network ?? null)`
- Modify: `frontend/src/app/reader/entry-social.ts` — drop `openUrl` from `EntrySocialDto`; fix fixtures that set it
- Test: the specs next to each component (`entry-source.component.spec.ts`, `entry-comments.component.spec.ts`, `quoted-post.component.spec.ts`, `entry-article-page.spec.ts` or the reader-view spec that covers Open original today — `grep -rln "authorize_interaction" frontend/src/app` finds it)

**Interfaces:**
- Consumes: `OwnInstanceLinks.open(address, network)` (Task 2).

- [ ] **Step 1: Failing specs**, each with `socialAccounts` holding a Mastodon ref on `https://home.example`:
  - entry-source: a Mastodon entry's poster link `href` is `https://home.example/authorize_interaction?uri=<encoded poster.url>`; a Bluesky entry's poster link keeps `poster.url` (Review Focus 5)
  - entry-comments: on a Mastodon entry, `.author` and `.when` hrefs are the rewritten `authorUrl` and `url`
  - quoted-post: with `network: 'mastodon'`, `.open` and `.nested` are rewritten; with the default `null`, unchanged
  - Open original: replace the existing `openUrl`-from-server case with one that sets no `openUrl` and expects the rewritten `entry.url`
- [ ] **Step 2:** Run those specs — expect FAIL.
- [ ] **Step 3:** Inject `OwnInstanceLinks` as a `protected readonly links` in each component; templates bind `[href]="links.open(<url>, <network>)"`. Remove `openUrl` from `EntrySocialDto`.
- [ ] **Step 4:** Run the specs, then `npm run check` from `frontend/` with the Jest flags from Global Constraints — expect PASS.
- [ ] **Step 5:** Commit `feat(#2261): poster, comment and quote links open on the reader's instance`.

### Task 4: Body mentions and hashtags

**Files:**
- Create: `frontend/src/app/reader/article/decorators/own-instance-body-links.ts` — `openMastodonLinksOnOwnInstance(host: HTMLElement, instance: string | null): void`
- Modify: `decorators/decorate-article.ts` — `ArticleDecorations` gains `mastodonInstance: string | null`; call the new decorator right after `linkBareUrls` (before the new-tab pass)
- Modify: `article-page/article-page-source.ts` (`ArticlePageBody` gains `network: SocialNetworkName | null`), `reader-view/entry-article-page.ts` (`network: entry.social?.network ?? null`), `link-reader/link-article-source.service.ts` (`network: null`), `article-page/article-page.component.{ts,html}` (pass it and include it in the body equality at line 41), `article-content/article-content.component.ts` (new `network` input into the parts), `article-content/article-body.service.ts` (`ArticleBodyParts.network`; `render()` passes `mastodonInstance: parts.network() === 'mastodon' ? this.links.instance() : null`)
- Test: `decorators/own-instance-body-links.spec.ts`, `decorators/decorate-article.spec.ts` (fixtures gain `mastodonInstance: null`)

**Interfaces:**
- Consumes: `ownInstanceBodyUrl(href, text, instance)` (Task 2), `OwnInstanceLinks.instance` (Task 2).

- [ ] **Step 1: Failing spec.** A host with `<a href="https://mastodon.social/@alice">@<span>alice</span></a>`, `<a href="https://mastodon.social/tags/rust">#<span>rust</span></a>`, `<a href="https://medium.com/@author">medium.com/@author</a>`, `<a href="#note">1</a>`: after `openMastodonLinksOnOwnInstance(host, 'https://home.example')` the first two hrefs are rewritten, the last two unchanged (Review Focus 3); with `null` all four unchanged.
- [ ] **Step 2:** Run — expect FAIL.
- [ ] **Step 3:** Implement: for each `a[href]`, set `href` to `ownInstanceBodyUrl(link.href, link.textContent?.trim() ?? '', instance)` when it differs. Wire the plumbing listed above.
- [ ] **Step 4:** Run the decorator specs and `npm run check` — expect PASS.
- [ ] **Step 5:** Commit `feat(#2261): mentions and hashtags in a Mastodon post open on the reader's instance`.

### Task 5: Delete the server-side post rule

**Files:**
- Delete: `backend/src/Service/Social/Social/Support/OwnInstancePostUrl.php`, `backend/tests/Service/Social/Social/Support/OwnInstancePostUrlTest.php`
- Modify: `backend/src/Repository/EntryListRowSocial.php` (drop `$openUrl`), `backend/src/Repository/EntryListRowEnricher.php:162` (drop the argument and the import), `backend/src/Http/EntryJson.php:52,185` (drop `openUrl` from the shape and the array)
- Modify tests: `tests/Repository/EntryListRowEnricherTest.php` (drop the `openUrl` cases at ~305–341 and `openUrlOf()`), `tests/Http/EntryJsonTest.php:347–411`, `tests/Controller/Api/EntryLikeControllerTest.php`, `EntrySocialViewerControllerTest.php`, `EntrySocialPostControllerTest.php` (drop `openUrl` from expected arrays and phpdoc shapes)

**Interfaces:**
- Produces: `social` JSON is `{network, isLiked, canLike, isOwn}`.

- [ ] **Step 1:** Remove the code and the assertions listed above. `grep -rn "openUrl\|OwnInstancePostUrl" backend` returns nothing.
- [ ] **Step 2:** Run `php bin/phpunit tests/Repository/EntryListRowEnricherTest.php tests/Http/EntryJsonTest.php tests/Controller/Api/EntryLikeControllerTest.php tests/Controller/Api/EntrySocialViewerControllerTest.php tests/Controller/Api/EntrySocialPostControllerTest.php` — expect PASS.
- [ ] **Step 3:** Run `composer check` and `composer md` (after `rm -rf ~/.pdepend`) — expect clean.
- [ ] **Step 4:** Commit `refactor(#2261): drop the server-side Open original rule`.

### Task 6: Full gates

- [ ] `php bin/phpunit` (SQLite) — green. The MySQL leg runs in CI; the Docker stack serves the main checkout, not this worktree.
- [ ] `composer infection:diff` — at or above `minMsi`.
- [ ] `npm run check` from `frontend/` with the Jest flags — green.
- [ ] Live check on :4200 is impossible from this worktree. Hand Lars the checklist in the PR: Mastodon post → poster name, comment author, reply timestamp, quote, a body mention and hashtag, Open original each land on the home instance; a Bluesky post is unchanged.
