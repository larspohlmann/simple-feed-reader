# Unhealthy Feeds in Settings — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show the user which of their feeds are unhealthy (erroring or gone) at the top of the Organise settings page, with per-feed retry, unsubscribe, inline details, and a count badge on the Organise nav entry.

**Architecture:** One additive backend change serializes three already-persisted feed-health fields onto the subscription payload. Everything else is frontend: pure health helpers (filter/sort/reason), two `SubscriptionsStore` computeds, a retry action on `ManageActions`, a slim row component with an inline details disclosure, a group rendered at the top of Organise, and a count chip on the settings nav.

**Tech Stack:** Symfony 7.4 / PHP 8.4 (backend, PHPUnit); Angular 20 standalone + signals (frontend, Jest/jsdom); Transloco i18n (`frontend/public/i18n/{en,de}.json`).

**Spec:** `docs/superpowers/plans/2026-09-04-unhealthy-feeds-spec.md`

## Global Constraints

- **Backend Clean Code is mandatory** (see `CLAUDE.md`): names reveal intent, guard clauses, `final readonly` house style, no boolean flag params, thin controllers. `composer check` (cs + stan max + tramp) and `composer md` must pass on every touched file.
- **`declare(strict_types=1)`** in every PHP file.
- **Frontend:** standalone components + signals, no NgModules. Component styles in a sibling `.scss` (never inline). No hex colours / raw px / media literals in `.scss` outside `src/app/theme/`. `npm run check` (ESLint + Prettier 100-col + Stylelint + Jest) is the gate.
- **Run frontend tests inside Docker:** `docker compose exec -T frontend npm test`. Native `npx jest` skips the typecheck.
- **i18n:** every new visible string is a Transloco key present in BOTH `en.json` and `de.json`. Keys live under a new `settings.health.*` namespace.
- **Native iOS readiness:** the backend change is additive JSON only — no browser-coupled inputs.
- **Datetimes are naive UTC**; serialize with `\DateTimeInterface::ATOM` exactly as the existing `lastFetchedAt` does.

---

### Task 1: Serialize the three health fields onto `SubscriptionJson`

**Files:**
- Modify: `backend/src/Http/SubscriptionJson.php` (the `one()` array + its `@return` shape docblock)
- Test: `backend/tests/Http/SubscriptionJsonTest.php`

**Interfaces:**
- Consumes: `Feed::getLastSuccessfulFetchAt(): ?\DateTimeImmutable`, `Feed::getConsecutiveFailures(): int`, `Feed::getLastErrorMessage(): ?string` (all already exist, confirmed at `Feed.php:169,199,209`).
- Produces: three new keys on the subscription payload — `lastSuccessfulFetchAt: string|null`, `consecutiveFailures: int`, `lastErrorMessage: string|null`.

- [ ] **Step 1: Write the failing test.** Append to `SubscriptionJsonTest.php`:

```php
public function testSerialisesTheHealthFields(): void
{
    $now = new \DateTimeImmutable('2026-02-03T04:05:06Z');
    $user = new User('u@example.com', $now);
    $feed = new Feed('https://example.com/feed.xml');
    $feed->setStatus(\App\Enum\FeedStatus::Erroring);
    $feed->setLastSuccessfulFetchAt(new \DateTimeImmutable('2026-01-28T09:00:00Z'));
    $feed->setConsecutiveFailures(4);
    $feed->setLastErrorMessage('https://example.com/feed.xml: HTTP 500');
    $sub = new Subscription($user, $feed, $now);

    $shape = SubscriptionJson::one($sub);

    self::assertSame('erroring', $shape['status']);
    self::assertSame('2026-01-28T09:00:00+00:00', $shape['lastSuccessfulFetchAt']);
    self::assertSame(4, $shape['consecutiveFailures']);
    self::assertSame('https://example.com/feed.xml: HTTP 500', $shape['lastErrorMessage']);
}

public function testHealthFieldsDefaultForANewFeed(): void
{
    $feed = new Feed('https://example.com/feed.xml');
    $shape = SubscriptionJson::one($this->subscriptionTo($feed));

    self::assertNull($shape['lastSuccessfulFetchAt']);
    self::assertSame(0, $shape['consecutiveFailures']);
    self::assertNull($shape['lastErrorMessage']);
}
```

Verify the Feed setters exist before relying on them: `grep -n "setLastSuccessfulFetchAt\|setConsecutiveFailures\|setLastErrorMessage\|setStatus" backend/src/Entity/Feed.php`. If any setter is missing, prefer driving state through the domain (`FeedScheduler::recordFailure($feed, 'msg')`) in the test rather than adding a bare setter the production code does not need.

- [ ] **Step 2: Run the test, verify it fails.** Run: `cd backend && php bin/phpunit --filter 'testSerialisesTheHealthFields|testHealthFieldsDefaultForANewFeed'`. Expected: FAIL (undefined array key).

- [ ] **Step 3: Implement.** In `SubscriptionJson::one()`, add three keys next to `lastFetchedAt`:

```php
            'lastFetchedAt' => $feed->getLastFetchedAt()?->format(\DateTimeInterface::ATOM),
            'lastSuccessfulFetchAt' => $feed->getLastSuccessfulFetchAt()?->format(\DateTimeInterface::ATOM),
            'consecutiveFailures' => $feed->getConsecutiveFailures(),
            'lastErrorMessage' => $feed->getLastErrorMessage(),
```

Update the `@return` array shape docblock to include `lastSuccessfulFetchAt: string|null, consecutiveFailures: int, lastErrorMessage: string|null` (place them right after `lastFetchedAt: string|null`).

- [ ] **Step 4: Run tests, verify pass.** Run: `cd backend && php bin/phpunit tests/Http/SubscriptionJsonTest.php`. Expected: PASS.

- [ ] **Step 5: Gate + commit.** Run `cd backend && composer check && composer md` (both clean on the touched file). Then:

```bash
git add backend/src/Http/SubscriptionJson.php backend/tests/Http/SubscriptionJsonTest.php
git commit -m "feat(#847): serialise feed-health fields onto the subscription payload"
```

---

### Task 2: Extend `SubscriptionDto` and every fixture

**Files:**
- Modify: `frontend/src/app/reader/models.ts` (the `SubscriptionDto` interface)
- Modify: every test file that builds a `SubscriptionDto` literal (found by the typecheck)

**Interfaces:**
- Produces: `SubscriptionDto` gains `lastSuccessfulFetchAt: string | null`, `consecutiveFailures: number`, `lastErrorMessage: string | null`.

- [ ] **Step 1: Extend the interface.** In `models.ts`, add after `lastFetchedAt`:

```ts
  /** When the feed last delivered content (ISO), or null if it never has.
   *  With `status` and `consecutiveFailures`, powers the unhealthy-feeds list. */
  lastSuccessfulFetchAt: string | null;
  /** The feed's current failure streak; 0 when healthy. */
  consecutiveFailures: number;
  /** The raw fetcher error for the last failed attempt, or null. Untranslated,
   *  capped at 1000 chars by the API — shown only in the health-details panel. */
  lastErrorMessage: string | null;
```

- [ ] **Step 2: Find every broken fixture.** Run: `docker compose exec -T frontend npx tsc --noEmit -p tsconfig.spec.json` (or `npm run check`). Expected: FAIL — TS2739/2741 "missing the following properties" at each `SubscriptionDto` literal. Note every file/line.

- [ ] **Step 3: Update each fixture factory.** For each reported literal, add the three fields with healthy defaults. Known factory: the `sub()` helper in `frontend/src/app/reader/subscriptions.store.spec.ts` — add `lastSuccessfulFetchAt: null, consecutiveFailures: 0, lastErrorMessage: null` next to `lastFetchedAt: null`. Repeat for any other reported file (e.g. organise/reader specs). If a factory is duplicated across three or more specs, extract a shared `makeSubscription(partial)` builder under `frontend/src/app/reader/testing/` and have them import it (DRY) — otherwise update in place.

- [ ] **Step 4: Verify typecheck passes.** Run: `docker compose exec -T frontend npm run check`. Expected: no TS errors from `SubscriptionDto` literals (other unrelated lint may remain for later tasks — this task only needs the typecheck green).

- [ ] **Step 5: Commit.**

```bash
git add frontend/src/app/reader/models.ts frontend/src/app/**/*.spec.ts frontend/src/app/reader/testing 2>/dev/null
git commit -m "feat(#847): carry the feed-health fields on SubscriptionDto"
```

---

### Task 3: Pure feed-health helpers (filter, sort, reason)

**Files:**
- Create: `frontend/src/app/settings/organise/feed-health.ts`
- Test: `frontend/src/app/settings/organise/feed-health.spec.ts`

**Interfaces:**
- Consumes: `SubscriptionDto` (Task 2).
- Produces:
  - `isUnhealthy(sub: SubscriptionDto): boolean` — `status !== 'active'`.
  - `unhealthyFeeds(subs: SubscriptionDto[]): SubscriptionDto[]` — filtered, `gone` before `erroring`, then by title (`localeCompare`).
  - `type HealthReason = { key: string; params?: Record<string, number> }`.
  - `feedHealthReason(sub: SubscriptionDto, now: Date): HealthReason` — the friendly-line descriptor (key + params; the component translates).
  - `daysSince(iso: string, now: Date): number` — whole days, floored, never negative.

- [ ] **Step 1: Write the failing spec.** Create `feed-health.spec.ts`:

```ts
import { SubscriptionDto } from '../../reader/models';
import { feedHealthReason, isUnhealthy, unhealthyFeeds } from './feed-health';

const make = (over: Partial<SubscriptionDto>): SubscriptionDto => ({
  id: 1, feedId: 10, title: 't', faviconUrl: null, customTitle: null,
  feedUrl: 'https://f/1', siteUrl: null, description: null, imageUrl: null,
  status: 'active', sourceFormat: 'xml', createdAt: 'x', lastFetchedAt: null,
  lastSuccessfulFetchAt: null, consecutiveFailures: 0, lastErrorMessage: null,
  position: 0, tags: [], unreadCount: 0, includeInAllItems: true, includeInForYou: true,
  ...over,
});

describe('feed health', () => {
  const now = new Date('2026-02-10T00:00:00Z');

  it('treats erroring and gone as unhealthy, active as healthy', () => {
    expect(isUnhealthy(make({ status: 'active' }))).toBe(false);
    expect(isUnhealthy(make({ status: 'erroring' }))).toBe(true);
    expect(isUnhealthy(make({ status: 'gone' }))).toBe(true);
  });

  it('lists gone before erroring, then by title', () => {
    const subs = [
      make({ id: 1, title: 'Bravo', status: 'erroring' }),
      make({ id: 2, title: 'Alpha', status: 'gone' }),
      make({ id: 3, title: 'Charlie', status: 'active' }),
      make({ id: 4, title: 'Alpha', status: 'erroring' }),
    ];
    expect(unhealthyFeeds(subs).map((s) => s.id)).toEqual([2, 4, 1]);
  });

  it('describes a gone feed as no longer available', () => {
    expect(feedHealthReason(make({ status: 'gone' }), now)).toEqual({
      key: 'settings.health.reason.gone',
    });
  });

  it('describes an erroring feed by days since last success', () => {
    expect(
      feedHealthReason(make({ status: 'erroring', lastSuccessfulFetchAt: '2026-02-04T00:00:00Z' }), now),
    ).toEqual({ key: 'settings.health.reason.noUpdate', params: { days: 6 } });
  });

  it('describes an erroring feed that never succeeded by its failure streak', () => {
    expect(
      feedHealthReason(make({ status: 'erroring', consecutiveFailures: 3 }), now),
    ).toEqual({ key: 'settings.health.reason.failedAttempts', params: { count: 3 } });
  });
});
```

- [ ] **Step 2: Run, verify fail.** Run: `docker compose exec -T frontend npx jest feed-health --silent=false`. Expected: FAIL (module not found).

- [ ] **Step 3: Implement `feed-health.ts`.**

```ts
import { SubscriptionDto } from '../../reader/models';

export type HealthReason = { key: string; params?: Record<string, number> };

const MS_PER_DAY = 86_400_000;

export function isUnhealthy(sub: SubscriptionDto): boolean {
  return sub.status !== 'active';
}

/** Gone feeds first (dead, act now), then erroring; each alphabetical by title. */
export function unhealthyFeeds(subs: SubscriptionDto[]): SubscriptionDto[] {
  const rank = (s: SubscriptionDto): number => (s.status === 'gone' ? 0 : 1);
  return subs
    .filter(isUnhealthy)
    .sort((a, b) => rank(a) - rank(b) || a.title.localeCompare(b.title));
}

export function daysSince(iso: string, now: Date): number {
  const elapsed = now.getTime() - new Date(iso).getTime();
  return Math.max(0, Math.floor(elapsed / MS_PER_DAY));
}

export function feedHealthReason(sub: SubscriptionDto, now: Date): HealthReason {
  if (sub.status === 'gone') return { key: 'settings.health.reason.gone' };
  if (sub.lastSuccessfulFetchAt !== null) {
    return {
      key: 'settings.health.reason.noUpdate',
      params: { days: daysSince(sub.lastSuccessfulFetchAt, now) },
    };
  }
  return {
    key: 'settings.health.reason.failedAttempts',
    params: { count: sub.consecutiveFailures },
  };
}
```

- [ ] **Step 4: Run, verify pass.** Run: `docker compose exec -T frontend npx jest feed-health`. Expected: PASS.

- [ ] **Step 5: Commit.**

```bash
git add frontend/src/app/settings/organise/feed-health.ts frontend/src/app/settings/organise/feed-health.spec.ts
git commit -m "feat(#847): pure feed-health filter, sort and reason helpers"
```

---

### Task 4: `SubscriptionsStore` computeds — `unhealthy` and `unhealthyCount`

**Files:**
- Modify: `frontend/src/app/reader/subscriptions.store.ts`
- Test: `frontend/src/app/reader/subscriptions.store.spec.ts`

**Interfaces:**
- Consumes: `unhealthyFeeds` (Task 3).
- Produces: `SubscriptionsStore.unhealthy: Signal<SubscriptionDto[]>` and `SubscriptionsStore.unhealthyCount: Signal<number>`.

- [ ] **Step 1: Write the failing test.** In `subscriptions.store.spec.ts`, in the derivations `describe`, add a case that sets subscriptions with mixed statuses and asserts `store.unhealthy()` order and `store.unhealthyCount()`. Reuse the file's existing `sub()` factory; override `status` via a small spread helper, e.g. `{ ...sub(1, 0), status: 'gone' as const }`.

```ts
it('exposes unhealthy feeds and their count', () => {
  const store = TestBed.inject(SubscriptionsStore);
  store.subscriptions.set([
    { ...sub(1, 0), title: 'Bravo', status: 'erroring' },
    { ...sub(2, 0), title: 'Alpha', status: 'gone' },
    { ...sub(3, 0), status: 'active' },
  ]);
  expect(store.unhealthy().map((s) => s.id)).toEqual([2, 1]);
  expect(store.unhealthyCount()).toBe(2);
});
```

(Follow the file's existing `TestBed` setup for providing the store — copy the `beforeEach` providers from the store-behaviour `describe` already in this spec.)

- [ ] **Step 2: Run, verify fail.** Run: `docker compose exec -T frontend npx jest subscriptions.store`. Expected: FAIL (`unhealthy` is not a function).

- [ ] **Step 3: Implement.** Import `unhealthyFeeds` and add two computeds next to `totalUnread`:

```ts
  readonly unhealthy = computed(() => unhealthyFeeds(this.subscriptions()));
  readonly unhealthyCount = computed(() => this.unhealthy().length);
```

Import: `import { unhealthyFeeds } from '../settings/organise/feed-health';`. If this creates an import cycle warning (store → settings), move `feed-health.ts` to `frontend/src/app/reader/feed-health.ts` and update Task 3's paths in the imports; the reader layer is the right home for a `SubscriptionDto` helper anyway. Prefer this relocation if ESLint reports a cycle.

- [ ] **Step 4: Run, verify pass.** Run: `docker compose exec -T frontend npx jest subscriptions.store feed-health`. Expected: PASS.

- [ ] **Step 5: Commit.**

```bash
git add frontend/src/app/reader/subscriptions.store.ts frontend/src/app/reader/subscriptions.store.spec.ts frontend/src/app/**/feed-health.*
git commit -m "feat(#847): expose unhealthy feeds and count from the subscriptions store"
```

---

### Task 5: `ManageActions.retryFeed` — retry, await, toast, reload

**Files:**
- Modify: `frontend/src/app/reader/manage/manage-actions.service.ts`
- Test: `frontend/src/app/reader/manage/manage-actions.service.spec.ts` (create if absent)

**Interfaces:**
- Consumes: `ReaderApi.refresh(scope): Observable<RefreshReport>`, `SubscriptionsStore.load()`, `ToastService.show`, `TranslocoService`.
- Produces: `ManageActions.retryFeed(sub: SubscriptionDto): void`.
- Recovery rule: `report.failed === 0 && report.fetched + report.notModified >= 1`.

- [ ] **Step 1: Write the failing test.** Using `HttpTestingController` (see `subscriptions.store.spec.ts` header for the provider setup), assert that `retryFeed(sub)` POSTs to `/api/refresh?feedId=<feedId>`, and that on a `{ failed: 0, fetched: 1, notModified: 0, ... }` report it shows the success toast, while on `{ failed: 1, fetched: 0, ... }` it shows the failure toast; both then trigger a reload (`GET /api/subscriptions`). Mock `ToastService` with a jest spy and assert the message key. Build the `RefreshReport` from a local factory with all fields (`status`, `progress`, `fetched`, `notModified`, `failed`, `throttled`, `skippedForBudget`, `remaining`, `pruned`).

- [ ] **Step 2: Run, verify fail.** Run: `docker compose exec -T frontend npx jest manage-actions`. Expected: FAIL (`retryFeed` is not a function).

- [ ] **Step 3: Implement `retryFeed`.**

```ts
/** Manually re-fetch one feed (the health list's Retry). Reaches gone feeds
 *  too; a success resurrects the feed server-side. Awaits the report so the
 *  toast tells the truth, then reloads so a recovered feed leaves the list. */
retryFeed(sub: SubscriptionDto): void {
  this.api.refresh({ feedId: sub.feedId }).subscribe({
    next: (report) => {
      const recovered = report.failed === 0 && report.fetched + report.notModified >= 1;
      this.toast.show({
        message: this.i18n.translate(
          recovered ? 'settings.health.retry.recovered' : 'settings.health.retry.stillFailing',
          { title: sub.title },
        ),
        durationMs: CONFIRMATION_DURATION_MS,
      });
      this.subs.load();
    },
    error: () => {
      this.toast.show({
        message: this.i18n.translate('settings.health.retry.error', { title: sub.title }),
        durationMs: CONFIRMATION_DURATION_MS,
      });
    },
  });
}
```

Add `RefreshReport` to the `../models` import if not already present.

- [ ] **Step 4: Run, verify pass.** Run: `docker compose exec -T frontend npx jest manage-actions`. Expected: PASS.

- [ ] **Step 5: Commit.**

```bash
git add frontend/src/app/reader/manage/manage-actions.service.ts frontend/src/app/reader/manage/manage-actions.service.spec.ts
git commit -m "feat(#847): retryFeed action — refresh one feed, report the outcome, reload"
```

---

### Task 6: `UnhealthyFeedRowComponent` — slim row with inline details

**Files:**
- Create: `frontend/src/app/settings/organise/unhealthy-feed-row.component.ts`
- Create: `frontend/src/app/settings/organise/unhealthy-feed-row.component.html`
- Create: `frontend/src/app/settings/organise/unhealthy-feed-row.component.scss`
- Test: `frontend/src/app/settings/organise/unhealthy-feed-row.component.spec.ts`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: `SubscriptionDto`, `feedHealthReason` (Task 3), `FaviconComponent`, `ButtonComponent`, `DisclosureComponent`, `IconComponent`, `TranslocoPipe`.
- Produces: `<app-unhealthy-feed-row [subscription] (retry) (unsubscribe)>`. Presentational — emits, never writes.

- [ ] **Step 1: Write the failing spec.** Render the component (standalone, `TestBed` with `provideTransloco` test config as other organise specs do), assert: a `gone` feed shows the "Dead" pill and the "No longer available" reason; a `retry` output fires when the Retry button is clicked; an `unsubscribe` output fires when Unsubscribe is clicked; clicking a Retry/Unsubscribe button does NOT toggle the details (the click is stopped). Copy the harness from `organise-feed-row.component.spec.ts`.

- [ ] **Step 2: Run, verify fail.** Run: `docker compose exec -T frontend npx jest unhealthy-feed-row`. Expected: FAIL (module not found).

- [ ] **Step 3: Implement the component.** `.ts`:

```ts
import { ChangeDetectionStrategy, Component, computed, inject, input, output } from '@angular/core';
import { TranslocoPipe } from '@jsverse/transloco';
import { IconComponent } from '../../shared/icon/icon.component';
import { FaviconComponent } from '../../shared/favicon/favicon.component';
import { ButtonComponent } from '../../shared/button/button.component';
import { DisclosureComponent } from '../../shared/disclosure/disclosure.component';
import { LayoutService } from '../../reader/layout.service';
import { SubscriptionDto } from '../../reader/models';
import { feedHealthReason } from './feed-health';

/** One row in the unhealthy-feeds list: favicon, title, a status pill, a
 *  friendly reason line, Retry and Unsubscribe, and an inline details
 *  disclosure. Presentational — it emits and never writes. */
@Component({
  selector: 'app-unhealthy-feed-row',
  imports: [TranslocoPipe, IconComponent, FaviconComponent, ButtonComponent, DisclosureComponent],
  templateUrl: './unhealthy-feed-row.component.html',
  styleUrl: './unhealthy-feed-row.component.scss',
  changeDetection: ChangeDetectionStrategy.OnPush,
})
export class UnhealthyFeedRowComponent {
  readonly subscription = input.required<SubscriptionDto>();
  readonly retry = output<void>();
  readonly unsubscribe = output<void>();

  protected readonly reason = computed(() => feedHealthReason(this.subscription(), new Date()));
  protected readonly isGone = computed(() => this.subscription().status === 'gone');
}
```

`.html` — a `<app-disclosure appearance="row">` whose `[summary]` slot holds favicon + title + pill + reason + the two buttons; the projected body holds the details grid and the raw error. The pill label is `settings.health.pill.dead` / `settings.health.pill.failing`; the reason renders `reason().key | transloco: reason().params`. Give the buttons `(click)="$event.stopPropagation(); retry.emit()"` so a control click acts without toggling the `<details>`. Details rows: `settings.health.details.lastSuccess` → `subscription().lastSuccessfulFetchAt`, `.lastAttempt` → `subscription().lastFetchedAt`, `.failures` → `subscription().consecutiveFailures`, and the raw `subscription().lastErrorMessage` inside a `<code>`/`<pre>` element flagged with `settings.health.details.technical`. Show a details row only when its value is non-null.

`.scss` — use design tokens only (no hex/px literals); the pill's dead/failing colours must come from theme tokens. Read `docs/design-language.md` for the status-chip / token conventions and reuse `app-tag-glyph` chip styling as the visual reference.

- [ ] **Step 4: Add i18n keys** to `en.json` and `de.json` under `settings.health`: `pill.dead`, `pill.failing`, `reason.gone`, `reason.noUpdate` (uses `{{days}}`), `reason.failedAttempts` (uses `{{count}}`), `details.lastSuccess`, `details.lastAttempt`, `details.failures`, `details.technical`, `retry.recovered`/`retry.stillFailing`/`retry.error` (use `{{title}}`), plus `action.retry`, `action.unsubscribe`, and the group strings `title` and (for the nav) reuse existing. Keep `de.json` a real translation, not an English copy. Match the existing key ordering/nesting style in both files.

- [ ] **Step 5: Run, verify pass, then commit.** Run: `docker compose exec -T frontend npx jest unhealthy-feed-row`. Expected: PASS.

```bash
git add frontend/src/app/settings/organise/unhealthy-feed-row.component.* frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#847): unhealthy-feed row with status pill, reason and inline details"
```

---

### Task 7: Render the group at the top of Organise

**Files:**
- Modify: `frontend/src/app/settings/organise/organise-section.component.ts` (imports + wire retry/unsubscribe)
- Modify: `frontend/src/app/settings/organise/organise-section.component.html` (add the group as the first child of the stack)
- Modify: `frontend/src/app/settings/organise/organise-section.component.spec.ts`

**Interfaces:**
- Consumes: `SubscriptionsStore.unhealthy()` (Task 4), `UnhealthyFeedRowComponent` (Task 6), `ManageActions.retryFeed` (Task 5) and `ManageActions.unsubscribe` (existing).
- Produces: the visible group; no new public API.

- [ ] **Step 1: Write the failing test.** In the organise section spec, set `subs.subscriptions` to include one `gone` feed and assert an `app-unhealthy-feed-row` renders and that the group is absent when all feeds are `active`. Assert that the row's `(retry)` calls `manage.retryFeed` (spy) and `(unsubscribe)` calls `manage.unsubscribe` (spy).

- [ ] **Step 2: Run, verify fail.** Run: `docker compose exec -T frontend npx jest organise-section`. Expected: FAIL.

- [ ] **Step 3: Implement.** Add `UnhealthyFeedRowComponent` to the component `imports`. In the `.html`, as the FIRST child inside `<app-settings-stack>` (before the existing `<app-settings-group icon="rss_feed">`):

```html
@if (subs.unhealthy().length) {
  <app-settings-group
    icon="warning"
    [title]="'settings.health.title' | transloco"
    [caption]="'settings.health.summary' | transloco: { count: subs.unhealthy().length }"
  >
    @for (feed of subs.unhealthy(); track feed.id) {
      <app-unhealthy-feed-row
        [subscription]="feed"
        (retry)="manage.retryFeed(feed)"
        (unsubscribe)="manage.unsubscribe(feed)"
      />
    }
  </app-settings-group>
}
```

Add `settings.health.title` and `settings.health.summary` (`{{count}}`) to both i18n files.

- [ ] **Step 4: Run, verify pass.** Run: `docker compose exec -T frontend npx jest organise-section`. Expected: PASS.

- [ ] **Step 5: Commit.**

```bash
git add frontend/src/app/settings/organise/organise-section.component.* frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#847): show the unhealthy-feeds group atop the Organise page"
```

---

### Task 8: Count badge on the Organise nav entry

**Files:**
- Modify: `frontend/src/app/settings/settings-nav.component.ts`
- Modify: `frontend/src/app/settings/settings-nav.component.html`
- Modify: `frontend/src/app/settings/settings-nav.component.scss`
- Test: `frontend/src/app/settings/settings-nav.component.spec.ts` (create if absent)

**Interfaces:**
- Consumes: `SubscriptionsStore.unhealthyCount()` (Task 4).
- Produces: a count chip rendered on the section whose `path === 'organise'` when the count > 0.

- [ ] **Step 1: Write the failing test.** Render `<app-settings-nav variant="rail">` with a stubbed `SubscriptionsStore` whose `unhealthyCount` returns 2; assert a badge with "2" appears on the Organise link and that no badge renders when the count is 0.

- [ ] **Step 2: Run, verify fail.** Run: `docker compose exec -T frontend npx jest settings-nav`. Expected: FAIL.

- [ ] **Step 3: Implement.** Inject `SubscriptionsStore`, expose `readonly unhealthyCount = inject(SubscriptionsStore).unhealthyCount;`. In the template, inside the `<a>`, after the label, render a badge when `s.path === 'organise' && unhealthyCount() > 0`:

```html
            <span class="label">{{ s.labelKey | transloco }}</span>
            @if (s.path === 'organise' && unhealthyCount()) {
              <span class="badge" [attr.aria-label]="'settings.health.badgeLabel' | transloco: { count: unhealthyCount() }">{{ unhealthyCount() }}</span>
            }
            <app-icon class="chev" name="chevron_right" size="sm" />
```

Style `.badge` from tokens only. Add `settings.health.badgeLabel` (`{{count}}`) to both i18n files.

- [ ] **Step 4: Run, verify pass.** Run: `docker compose exec -T frontend npx jest settings-nav`. Expected: PASS.

- [ ] **Step 5: Full gate + commit.** Run `docker compose exec -T frontend npm run check` (whole frontend gate). Then:

```bash
git add frontend/src/app/settings/settings-nav.component.* frontend/public/i18n/en.json frontend/public/i18n/de.json
git commit -m "feat(#847): badge the Organise nav entry with the unhealthy-feed count"
```

---

## Final verification (after all tasks)

- [ ] Backend: `cd backend && composer check && composer md && php bin/phpunit`.
- [ ] Backend mutation gate on the diff: `cd backend && composer infection:diff`.
- [ ] Frontend: `docker compose exec -T frontend npm run check`.
- [ ] Scan today's dev log for deprecations: `ls -t backend/var/log/dev-*.log | head -1` then read it.
- [ ] Visually confirm in the running app: an unhealthy feed shows in the group, Retry toasts and reloads, details expand, the nav badge shows the count.

## Self-Review notes

- **Spec coverage:** definitions/placement → Tasks 4,7; slim row + pill + reason → Tasks 3,6; details on click → Task 6; retry semantics → Task 5; discoverability badge → Task 8; backend serialization → Task 1; DTO carriage → Task 2. All spec sections map to a task.
- **Type consistency:** `unhealthyFeeds`/`feedHealthReason`/`isUnhealthy` names are identical across Tasks 3, 4, 6. `retryFeed(sub)` matches its call site in Task 7. The three new field names (`lastSuccessfulFetchAt`, `consecutiveFailures`, `lastErrorMessage`) are identical in Tasks 1, 2, 6.
- **Placeholders:** none — every code step carries real code; i18n keys are enumerated; the recovery rule is a concrete expression.
- **Known risk:** an import cycle if the store imports from `settings/` — Task 4 Step 3 gives the relocation escape hatch (move `feed-health.ts` into `reader/`).
