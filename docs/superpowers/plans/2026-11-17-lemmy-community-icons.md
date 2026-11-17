# Lemmy Community and User Icons Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A subscribed Lemmy community feed shows the community's icon, a Lemmy user feed the user's avatar — or, for a user without one, Lemmy's default avatar (the mouse) — instead of the instance icon (#2316).

**Architecture:** `FaviconResolver` fetches one page per site today, the origin's homepage (#2298). For a base URL that names a Lemmy community or user (`/c/<name>`, `/u/<name>`, or their feeds `/feeds/c/<name>.xml`, `/feeds/u/<name>.xml`, any query), it fetches that actor's page instead. lemmy-ui embeds the actor in `window.isoData` beside the instance's `"site_view":{"site":{…}}`: `"community_view":{"community":{…"icon":…}}` on a community page, `"person_view":{"person":{…"avatar":…}}` on a user page. For an avatar-less person lemmy-ui draws `/static/<build>/assets/icons/icon-96x96.png`, whose build segment differs per instance (lemmy.world `9acd6610`, lemmy.ml `7cb4cd8c`), so it is read from the page's own `/static/<build>/assets/` references. A migration clears the stored icon of existing community and user feeds so the next refresh resolves them again.

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine Migrations, PHPUnit 12.

**Spec:** GitHub issue #2316 and the session that opened it (Lars: users get their avatar; a user without one gets the mouse `https://lemmy.world/static/9acd6610/assets/icons/icon-96x96.png`, not the instance favicon).

## Global Constraints

- Every `src` file touched is PHPMD-clean; `composer check` (cs + stan + tramp) passes.
- No new comments unless a future reader would get the code wrong without one (CLAUDE.md).
- Commit messages: `type(#2316): lower-case summary`; no Claude attribution.
- The resolver never throws (best-effort contract of `FaviconResolverInterface`).
- Icon URLs go through `HttpsImageUrl::orNull` — https only, never `data:`.
- A community without an icon keeps the instance icon (only users get the mouse).

## Review Focus

- Feed URLs with a query (`/feeds/c/technology.xml?sort=New`, as stored locally) → the community page, and the migration clears them too.
- A federated actor page (`/c/name@other.host`) the local instance has not seen renders no actor record → the instance's site icon.
- A non-Lemmy site with a `/c/<x>` or `/u/<x>` path → that page is fetched; its `<link rel=icon>` still wins, the fallback stays `https://host/favicon.ico`.
- Two feeds of the same actor (page form and feed form) → one fetch, both get the icon.
- A description or bio holding `{`, `}` or `\"` → the record still parses (strings stepped over whole, as the site record).

---

### Task 1: Read the actor's icon from a Lemmy page

**Files:**
- Rename: `backend/src/Service/Outbound/Fetch/Support/LemmySiteIcon.php` → `LemmyPageIcon.php` (class `LemmyPageIcon`; `git mv`)
- Modify: `backend/src/Service/Outbound/Fetch/FaviconResolver/FaviconResolver.php:12,130` (import + call)
- Test: `backend/tests/Service/Outbound/Fetch/FaviconResolver/FaviconResolverTest.php`

**Interfaces:**
- Produces: `LemmyPageIcon::in(string $html, PageUrls $pageUrls): ?string` — a person record's https avatar, else the mouse when the page holds a person record; otherwise the community record's https icon, else the site record's; null when none applies.

- [ ] **Step 1: Write the failing tests.** Give the `lemmyPage()` helper an optional actor fragment inserted beside `site_res` (slashes escaped as for the site record) and an optional `$body` (default `''`) placed in `<body>`. Data provider `lemmyActorRecords`, test `testALemmyActorIconOutranksItsInstanceIcon` (site record `{"id":1,"icon":"https://lemmy.example.com/pictrs/image/site.png"}`, base `https://lemmy.example.com/c/technology`; stub the origin in this task — Task 2 moves the stub to the actor page):
  - community `{"id":7,"name":"technology","icon":"https://lemmy.example.com/pictrs/image/c.png"}` → `https://lemmy.example.com/pictrs/image/c.png`
  - community without `icon` → `https://lemmy.example.com/pictrs/image/site.png`
  - community `{"id":7,"description":"Rules: {be nice} \"quoted\"","icon":"https://lemmy.example.com/c.png"}` → `https://lemmy.example.com/c.png`
  - community with `http://` icon → the site icon
  - person `{"id":9,"name":"lars","avatar":"https://lemmy.example.com/pictrs/image/a.png"}` → `https://lemmy.example.com/pictrs/image/a.png`
  - person without `avatar`, body `<svg><use xlink:href="/static/9acd6610/assets/symbols.svg#icon-heart"></use></svg>` → `https://lemmy.example.com/static/9acd6610/assets/icons/icon-96x96.png`
  - person with `"bio":"likes {braces}"` and no avatar, same body → the mouse URL
  - person without `avatar` and no `/static/…/assets/` reference → the site icon
  Actor fragments: `"community_res":{"community_view":{"community":<record>,"subscribed":"NotSubscribed"}}` and `"person_res":{"person_view":{"person":<record>,"counts":{"post_count":1}}}`. The existing case whose site record is followed by a bare `"community":{…}` keeps expecting `/favicon.ico`: only a `community_view` record counts.

- [ ] **Step 2: Run** `php bin/phpunit --filter FaviconResolverTest` — Expected: the new cases FAIL.

- [ ] **Step 3: Implement.** `git mv` to `LemmyPageIcon`; constants `SITE_RECORD`, `COMMUNITY_RECORD` (`"community_view":\{"community":`), `PERSON_RECORD` (`"person_view":\{"person":`), each followed by the same possessive record body as today's; `STATIC_BUILD = '#/static/([0-9a-z]+)/assets/#'`. A private `field(string $pattern, string $key, string $html): ?string` holds today's match/decode/`HttpsImageUrl::orNull` logic and an `hasRecord`-style check decides the person branch; the mouse is `HttpsImageUrl::orNull($pageUrls->httpUrl('/static/' . $build . '/assets/icons/icon-96x96.png'))`. Update the class docblock to one line naming the three records.

- [ ] **Step 4: Run** `php bin/phpunit --filter FaviconResolverTest` — Expected: PASS.

- [ ] **Step 5: Commit** — `fix(#2316): a Lemmy page's community icon or user avatar outranks its instance icon`

### Task 2: Fetch the actor page for a Lemmy community or user feed

**Files:**
- Create: `backend/src/Service/Outbound/Fetch/Support/LemmyActorPage.php`
- Modify: `backend/src/Service/Outbound/Fetch/FaviconResolver/FaviconResolver.php` (`resolveAll`, `fetchAllIcons`, `fetchIconsByOrigin` → keyed by page URL)
- Test: `backend/tests/Service/Outbound/Fetch/Support/LemmyActorPageTest.php`, `FaviconResolverTest.php`

**Interfaces:**
- Consumes: `LemmyPageIcon::in()` (Task 1).
- Produces: `LemmyActorPage::of(string $url): ?string` — `https://<host>/<c|u>/<name>` for a URL whose path is `/c/<name>`, `/u/<name>` (optional trailing `/`), `/feeds/c/<name>.xml` or `/feeds/u/<name>.xml`, any query ignored; `<name>` may carry `@host`; null for any other path or a URL without host. Static-only `final class` with a private constructor.

- [ ] **Step 1: Write the failing tests.** `LemmyActorPageTest` with a provider:
  - `https://lemmy.world/c/simplefeedreader` → `https://lemmy.world/c/simplefeedreader`
  - `http://lemmy.world/c/simplefeedreader/` → `https://lemmy.world/c/simplefeedreader`
  - `https://lemmy.world/feeds/c/technology.xml?sort=New` → `https://lemmy.world/c/technology`
  - `https://lemmy.world/u/lars_pohlmann` → `https://lemmy.world/u/lars_pohlmann`
  - `https://lemmy.world/feeds/u/lars_pohlmann.xml` → `https://lemmy.world/u/lars_pohlmann`
  - `https://feddit.org/c/linux@lemmy.ml` → `https://feddit.org/c/linux@lemmy.ml`
  - `https://lemmy.world/`, `https://lemmy.world/c/`, `https://lemmy.world/c/a/b`, `https://lemmy.world/feeds/local.xml`, `https://lemmy.world/m/technology`, `not a url` → null

  In `FaviconResolverTest`:
  - `testALemmyCommunityFeedFetchesItsCommunityPage`: stub `https://lemmy.example.com/c/technology`; base `https://lemmy.example.com/feeds/c/technology.xml?sort=New`; assert `fetchedUrls === ['https://lemmy.example.com/c/technology']` and the community icon.
  - `testALemmyUserFeedFetchesItsUserPage`: same shape for `/feeds/u/lars.xml` → `https://lemmy.example.com/u/lars`, avatar returned.
  - `testBothFormsOfOneActorShareOneFetch`: bases `/c/technology` and `/feeds/c/technology.xml` → one fetched URL, both icons equal.
  - `testAnUnreachableActorPageFallsBackToTheOriginFaviconIco`: no stub → `https://lemmy.example.com/favicon.ico`.
  - Move the stubs of the Lemmy record tests to `https://lemmy.example.com/c/technology`; change `testFeedsOnOneSiteShareASingleHomepageFetch` bases to `/blog/one`, `/blog/two` (it pins the homepage path, not an actor).

- [ ] **Step 2: Run** `php bin/phpunit --filter 'LemmyActorPageTest|FaviconResolverTest'` — Expected: FAIL.

- [ ] **Step 3: Implement.** In `resolveAll`, a feed's fetch target is `LemmyActorPage::of($baseUrl) ?? $origin`; tickets are keyed by target (one per target, however many feeds share it); the fallback for a target with no icon stays `$origin . '/favicon.ico'`. Keep the per-site guard, logging and `URL_MAX` truncation; rename origin-named locals and log context that now hold a target to `page`.

- [ ] **Step 4: Run** `php bin/phpunit --filter 'LemmyActorPageTest|FaviconResolverTest'` — Expected: PASS.

- [ ] **Step 5: Commit** — `fix(#2316): a Lemmy community or user feed takes its icon from the actor's page`

### Task 3: Re-resolve the icons of existing community and user feeds

**Files:**
- Create: `backend/migrations/Version20261117230000.php`
- Test: `backend/tests/Migrations/Version20261117230000Test.php`

- [ ] **Step 1: Write the failing test** in the style of `Version20261117120000Test` (in-memory SQLite, `CREATE TABLE feed (id INTEGER PRIMARY KEY, url VARCHAR(2048) NOT NULL, favicon_url VARCHAR(2048) DEFAULT NULL)`): `testUpClearsTheIconOfLemmyActorFeedsOnly` — `https://lemmy.world/feeds/c/a.xml`, `https://lemmy.world/feeds/c/technology.xml?sort=New` and `https://lemmy.world/feeds/u/lars.xml` lose their icon; `https://lemmy.world/feeds/local.xml` and `https://blog.example.com/feed.xml` keep theirs.

- [ ] **Step 2: Run** `php bin/phpunit tests/Migrations/Version20261117230000Test.php` — Expected: FAIL (migration missing).

- [ ] **Step 3: Implement** `up()`: `UPDATE feed SET favicon_url = NULL WHERE url LIKE 'https://%/feeds/c/%' OR url LIKE 'https://%/feeds/u/%'`; description `Lemmy community and user feeds resolve their favicon again, now from the actor's page (#2316).`; `down()` throws `throwIrreversibleMigrationException` like `Version20261117180000`.

- [ ] **Step 4: Run** the test — PASS; then migrate on both dialects: `bin/console doctrine:migrations:migrate --no-interaction` natively (SQLite) and `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction` (MySQL), each followed by `doctrine:schema:validate`.

- [ ] **Step 5: Commit** — `fix(#2316): existing Lemmy community and user feeds resolve their icon again`

### Task 4: Gates and live check

- [ ] `composer check`, `composer md` (touched files clean), PhpStorm lint on changed PHP, `php bin/phpunit` and `docker compose exec php composer test:parallel`, `composer infection:diff`.
- [ ] Live: refresh the Lemmy feeds in the Docker stack; feed 892's `favicon_url` becomes `https://lemmy.world/pictrs/image/da958314-efea-4b90-b3a5-38c4b47a1b30.png` and `http://localhost:4200/?subscription=2750` shows the community logo. Subscribe to `https://lemmy.world/feeds/u/lars_pohlmann.xml` and check the avatar; check an avatar-less user's feed shows the mouse. Remove the test subscriptions afterwards. Scan today's dev log.
