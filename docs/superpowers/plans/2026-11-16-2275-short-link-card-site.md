# Short-Link Card Site Name Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A link card posted as a short link names the site the link lands on ("the-independent.com"), not the shortener ("trib.al").

**Architecture:** `shared_link.site_name` is written once at ingest; for a Bluesky card it is the host of the posted URL. When the link-resolution sweep settles a short link as resolved, it renames the site to the landing's host, but only where the stored name is still the posted URL's host. Every reader of a card (reader list, thread posts, quoted links, composer, Trending) reads `site_name`, so none of them changes. One migration applies the same rule to the links already resolved.

**Tech Stack:** PHP 8.5, Symfony 8.1, Doctrine DBAL, PHPUnit 12. No frontend change.

**Spec:** GitHub issue #2275.

**Departure from the issue text:** the issue says "derived at read time". Five read paths serve a card, two of them through `SharedLink::preview()` with no resolution in reach. Correcting the stored name at the moment the landing is learned is one write instead of five joins, and it also fixes Trending's `card_site_name`. The price is the backfill in Task 2.

## Global Constraints

- Branch `fix/2275-short-link-card-site`; commits `fix(#2275): …`; no attribution lines.
- A site name a network supplied (Mastodon's `provider_name`, a page's `og:site_name`) is never overwritten: the rename applies only where `site_name` equals `HostKey::forUrl(<posted url>)`.
- The new name is `HostKey::forUrl(<landing url>)`. It needs no cut: a hostname holds at most 253 characters, the column 255.
- The card's `url` and `readerToken` stay the posted short link. Switching the target is out of scope.
- Queries live in `src/Repository`. `composer check`, `composer md`, both test legs and `composer infection:diff` pass before the PR.

## Review Focus

- A Mastodon card of a short link with a real `provider_name` keeps it. Pinned in Task 1.
- A link that settles `unchanged` or `unresolved` keeps its name. Pinned in Task 1.
- A lost settle race (another pass settled the row first) still renames; both passes followed the same link, so the name is the same. No test.
- A landing URL with a `www.` host is named without it (`www.npr.org` → `npr.org`). Pinned in Task 1.
- The migration on an install with no resolved links adds no statement. Pinned in Task 2.

---

### Task 1: Rename the site when a short link resolves

**Files:**
- Modify: `backend/src/Repository/SharedLinkRepository.php`
- Modify: `backend/src/Service/Content/LinkResolution/LinkResolutionSweep.php`
- Test: `backend/tests/Service/Content/LinkResolution/LinkResolutionSweepTest.php`

**Interfaces:**
- Produces: `SharedLinkRepository::renameSite(int $linkId, string $from, string $to): void` — `UPDATE shared_link SET site_name = :to WHERE id = :id AND site_name = :from`, through DBAL.

- [ ] **Step 1: Write the failing tests** in `LinkResolutionSweepTest`. `linkOf()` there builds its card through `SharedLinks`; read both and give the helper a site-name argument if it has none (default: the posted URL's host, which is what Bluesky stores).

```php
public function testAResolvedShortLinkIsNamedAfterItsLanding(): void
{
    $link = $this->linkOf('https://n.pr/a');                       // site name "n.pr"
    $this->sweep([LinkLandingWiring::redirect('https://www.npr.org/a')])
        ->resolvePending(LinkResolutionBudgetModel::links(5));
    self::assertSame('npr.org', $this->siteNameOf($link));
}

public function testASiteNameTheNetworkSuppliedIsKept(): void
{
    $link = $this->linkOf('https://n.pr/a', 'NPR');
    // same sweep
    self::assertSame('NPR', $this->siteNameOf($link));
}

public function testALinkThatLandsOnItselfKeepsItsName(): void
{
    $link = $this->linkOf('https://n.pr/b');
    // sweep answers HTTP_OK twice (HEAD, then nothing changes)
    self::assertSame('n.pr', $this->siteNameOf($link));
}
```

`siteNameOf()` reads `site_name` by id with DBAL, not through the identity map.

- [ ] **Step 2: Run** `php bin/phpunit tests/Service/Content/LinkResolution/LinkResolutionSweepTest.php` — the first test FAILS with `'n.pr'` given.

- [ ] **Step 3: Add `renameSite()`** to `SharedLinkRepository` as specified above.

- [ ] **Step 4: Call it from `LinkResolutionSweep::settle()`.** Inject `SharedLinkRepository $sharedLinks`. After `linkResolutions->settle()`, when `$landing->url !== null`, call `renameSite($link->linkId, HostKey::forUrl($link->url), <landing host cut to SITE_NAME_MAX>)`. Keep `settle()` reading as a sentence: the rename is its own private method, `nameAfterLanding(PendingLinkModel $link, string $landingUrl): void`.

- [ ] **Step 5: Run** the test file — PASS. Run `composer check && composer md`.

- [ ] **Step 6: Commit** `fix(#2275): name a short link's card after the site it lands on`. The body carries the rationale from **Architecture** above.

### Task 2: Backfill the links already resolved

**Files:**
- Create: `backend/migrations/Version20261116200000.php`
- Test: `backend/tests/Migrations/Version20261116200000Test.php` (mirror `Version20261114160000Test.php`)

**Interfaces:**
- Consumes: nothing from Task 1; the rule is repeated in the migration so it stays frozen.

- [ ] **Step 1: Write the failing test.** Three stored links, each with a `resolved` resolution to `https://www.the-independent.com/x`:

| posted URL | `site_name` before | after `up()` |
|---|---|---|
| `https://trib.al/P3VsbgA` | `trib.al` | `the-independent.com` |
| `https://trib.al/abc` | `The Independent` | `The Independent` |
| `https://bit.ly/abc`, resolution `unchanged`, `resolved_url` NULL | `bit.ly` | `bit.ly` |

And one test with no resolution rows: `up()` runs and changes nothing.

- [ ] **Step 2: Run it** — FAILS, class not found.

- [ ] **Step 3: Write the migration**, shaped like `Version20261114160000`: select `link.id, link.url, link.site_name, landing.resolved_url` for `landing.status = 'resolved'`, decide per row in PHP with `HostKey::forUrl`, and `addSql('UPDATE shared_link SET site_name = ? WHERE id = ?', …)` per renamed row. `getDescription()`: `Name resolved short links' cards after the site they land on (#2275).` `down()` is empty: the old name is the posted URL's host, which the reader shows no worse than before, and nothing depends on it.

- [ ] **Step 4: Run** the test — PASS. Then migrate from empty on SQLite and on MySQL (`docker compose exec php bin/console doctrine:migrations:migrate --no-interaction`) and run `doctrine:schema:validate`.

- [ ] **Step 5: Commit** `fix(#2275): backfill the site name of resolved short links`.

### Task 3: Gates and PR

- [ ] `php bin/phpunit` and `docker compose exec php composer test:parallel`, in parallel.
- [ ] `composer infection:diff`; kill what escapes on the touched lines.
- [ ] PhpStorm inspections on the changed PHP; scan today's dev log.
- [ ] `/simplify`, then open the PR into `develop` with `Closes #2275`.
