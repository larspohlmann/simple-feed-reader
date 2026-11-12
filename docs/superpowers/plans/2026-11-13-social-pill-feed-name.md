# Social Post Pill Names Its Feed Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The network pill on a social post shows the network logo plus the feed's display title and links to that feed.

**Architecture:** `EntryPillsComponent` already resolves the post's subscription through `SubscriptionRows.find()` to read its
network; it reads the display title and id from the same row instead of rendering the network's short name. No call site
changes, so every surface (list cards, article page, magazine source groups) gets the same pill.

**Tech Stack:** Angular 20 standalone components and signals, Transloco, Jest.

**Spec:** `docs/superpowers/specs/2026-11-13-social-pill-feed-name-design.md`

## Global Constraints

- Issue #2194, branch `feature/2194-social-pill-feed-name`, commits `feat(#2194): …`.
- No backend change; no call-site change (the pill looks the same everywhere).
- Frontend tests run inside the Docker frontend container.

## Review Focus

- A long feed title (a long search query) truncates with an ellipsis inside the pill and never widens the card — existing
  `.name` CSS; verify live on :4200.
- An unrenamed home feed reads its localised label (`reader.socialHome`), not the stored title — spec in Task 1.
- An unrenamed search feed reads its current query — covered by `SubscriptionRows`' own spec; no new test.
- A subscription id not in the rows (or null) renders no feed pill — spec in Task 1.
- Clicking the pill inside a clickable card follows the feed link and does not open the post — spec in Task 1.

---

### Task 1: The pill shows and links the feed

**Files:**
- Modify: `frontend/src/app/reader/entry/entry-pills/entry-pills.component.ts`
- Modify: `frontend/src/app/reader/entry/entry-pills/entry-pills.component.html`
- Test: `frontend/src/app/reader/entry/entry-pills/entry-pills.component.spec.ts`

**Interfaces:**
- Produces: `protected readonly socialFeed: Signal<{ id: number; title: string; network: SocialNetworkName } | null>`
  replacing `network`.

- [ ] **Step 1: Rewrite the `network pill` describe as `social feed pill`**, with feeds built by `makeSubscription({ id, title,
  customTitle, social: { network, accountId: 1, kind: 'home' } })`:
  - `leads with the feed's name and its network logo, before the tags`: customTitle `'Friends'` on bluesky → first pill text
    `'Friends'`, `app-network-logo svg.bluesky` present, second pill contains `'Tech'`.
  - `names an unrenamed home feed by its localised label`: no customTitle, mastodon → text equals the testing translation of
    `reader.socialHome` with network `mastodon`; logo `svg.mastodon`.
  - `links to the feed`: href contains `subscription=5` and not `network=`.
  - keep `stops its click from bubbling…` and `shows nothing for an ordinary feed or no subscription`.
- [ ] **Step 2: Prove the specs fail on the old behaviour.** The implementation draft predates this plan, so revert the
  template's three bindings to the network short name / `{ network }` link by editing, run
  `docker compose exec -T frontend npx jest src/app/reader/entry/entry-pills` — Expected: the name, home-label and link specs
  FAIL — then reapply the draft bindings.
- [ ] **Step 3: Implement** `socialFeed` in the component (row from `rows.find(subscriptionId)`; null unless it has `social`)
  and bind the template: `[queryParams]="selectionQueryParams({ subscription: feed.id })"`, `[attr.title]="feed.title"`,
  logo `[network]="feed.network"`, name `{{ feed.title }}`. Update the class docblock's first line.
- [ ] **Step 4: Run** the same jest command — Expected: PASS.
- [ ] **Step 5: Gate** `docker compose exec -T frontend npm run check` — Expected: exit 0.
- [ ] **Step 6: Live check** on http://localhost:4200: a Bluesky/Mastodon post in a list card, the article page and a magazine
  source group each show logo + feed name; a long search title ellipsises; clicking opens the feed.
- [ ] **Step 7: Commit**

```bash
git add frontend/src/app/reader/entry/entry-pills docs/superpowers/specs/2026-11-13-social-pill-feed-name-design.md docs/superpowers/plans/2026-11-13-social-pill-feed-name.md
git commit -m "feat(#2194): a social post's pill names its feed and opens it"
```
