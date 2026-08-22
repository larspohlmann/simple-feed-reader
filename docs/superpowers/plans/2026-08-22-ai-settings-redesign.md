# AI Settings Redesign (#541) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild the `settings/ai` page in a "Grouped" design language, decouple recommendation-reason visibility from Debug mode, and turn info tips into popovers — the pilot for a later settings/admin-wide rollout.

**Architecture:** Backend adds one persisted `showReasons` flag that gates reason emission independently of the existing `debugEnabled` flag (which keeps scores + logs); feed JSON emits reason and score on two independent axes via a small visibility value object. Frontend delivers a **reusable settings design system** — a small set of shared primitives in `shared/settings/` (group, row, drill-in disclosure, save bar) plus the popover info-tip, documented in `docs/design-language.md` — and the AI page (`ai-section`, `recommendation-settings-card`) is rebuilt as the **first consumer** that composes those primitives, not a page of one-off styles. Every future settings/admin section builds from the same primitives; the AI page proves them.

**Design-system rule:** the Grouped look lives in shared components and tokens, never inline in a feature component. If the AI page needs a visual pattern, it becomes (or extends) a `shared/settings/` primitive first, then the page consumes it. No design decision is duplicated into a feature `.scss`.

**Tech Stack:** Symfony 7.4 / PHP 8.4 / Doctrine (MySQL prod, SQLite native tests); Angular 20 standalone + signals; Transloco i18n; graphite design tokens.

**Spec:** GitHub issue #541 (`https://github.com/larspohlmann/simple-feed-reader/issues/541`). Visual source of truth: the validated mockup at the session scratchpad `ai-settings-redesign.html` (Grouped direction) — its markup/tokens are the reference for every frontend template/SCSS task.

## Global Constraints

- Branch `feature/541-ai-settings-redesign` (off develop); work in place, no worktrees. One branch/PR for the whole feature.
- Commit messages: `type(#541): summary` (issue number is the scope).
- Backend: `declare(strict_types=1)` every file; Clean Code mandatory; **no boolean-flag parameters that split behaviour** — use a value object; thin controllers (no responsibility-carrying private methods); every touched `src` file must be PHPMD-clean; `composer check` (cs + stan + tramp) green; PhpStorm inspections clean (ERROR/WARNING) on changed PHP.
- New setting default is **OFF for everyone, including a migration backfill**; debug-on users lose reasons on upgrade until they enable the new toggle (accepted).
- Migrations get their own verification: metadata-built test schemas never run a migration, so verify the new migration from empty on **both SQLite and MySQL** in a scratch DB — never against the dev DB.
- Mutation testing gates changed lines (`composer infection:diff`, `minMsi` in `infection.json5`); a src change proven only by e2e scores 0% — unit-test it too.
- Frontend: `npm run check` (ESLint + Prettier + Stylelint + Jest) green; no hex or raw `px` spacing/media literals in `.scss` outside `theme/`; component styles in a sibling `.scss` (`styleUrl`), never inline; standalone components + signals, no NgModules.
- Keep a native Swift iOS client viable: bearer-token, stateless, `application/problem+json` errors, no browser-only inputs. The new field must round-trip in JSON in and out.

---

## Task 0: Settings shell sticky top bar — DONE

Already implemented and verified live on this branch. `settings-shell.component.{html,scss}`: `.bar` is `position: sticky; top: 0` with a translucent blur, a refined back-to-reader control (hover state), a `.divider` element, refined `h1`; the rail re-anchors at `calc(var(--bar-h) + var(--space-5))`. Stylelint clean. No further action; listed for coverage.

---

## Task 1: Add `showReasons` to the settings value objects and entity

**Files:**
- Modify: `backend/src/Service/Recommendation/RecommendationSettingsValues.php`
- Modify: `backend/src/Service/Recommendation/EffectiveRecommendationSettings.php`
- Modify: `backend/src/Entity/RecommendationSettings.php`
- Test: `backend/tests/Entity/RecommendationSettingsTest.php` (create if absent) or the nearest existing entity round-trip test

**Interfaces:**
- Produces: `RecommendationSettingsValues::$showReasons` (bool), `EffectiveRecommendationSettings::$showReasons` (bool), `RecommendationSettings` persists/round-trips `showReasons`.
- Add `showReasons` as a **trailing constructor parameter defaulted to `false`** on both value objects (matching how `profileText`/`autoGenerateIntervalHours` were added additively so existing callers keep compiling).

- [ ] **Step 1: Write the failing test** — entity update/values round-trip carries `showReasons`.

```php
public function testUpdateAndValuesRoundTripShowReasons(): void
{
    $settings = new RecommendationSettings($this->user);
    $settings->update(new RecommendationSettingsValues(
        guidancePrompt: null, favoritesCap: 40, keptCap: 40, viewedCap: 80,
        candidatePoolSize: 500, lookbackDays: 2, picksLimit: 50,
        contextWindow: null, batchCount: null, debugEnabled: false,
        autoGenerateIntervalHours: null, profileText: null, showReasons: true,
    ));

    self::assertTrue($settings->values()->showReasons);
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd backend && php bin/phpunit --filter testUpdateAndValuesRoundTripShowReasons`
Expected: FAIL (unknown named argument `showReasons`).

- [ ] **Step 3: Implement**

In `RecommendationSettingsValues` add trailing param `public bool $showReasons = false,`. In `EffectiveRecommendationSettings` add trailing param `public bool $showReasons = false,`. In `RecommendationSettings`:
```php
#[ORM\Column(options: ['default' => false])]
private bool $showReasons = false;
```
Set it in `update()`: `$this->showReasons = $values->showReasons;` and pass `showReasons: $this->showReasons,` in `values()`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd backend && php bin/phpunit --filter testUpdateAndValuesRoundTripShowReasons`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add backend/src/Service/Recommendation/RecommendationSettingsValues.php backend/src/Service/Recommendation/EffectiveRecommendationSettings.php backend/src/Entity/RecommendationSettings.php backend/tests/Entity/RecommendationSettingsTest.php
git commit -m "feat(#541): persist showReasons on recommendation settings"
```

---

## Task 2: Expose `showReasons` through the DTO, resolver and settings JSON

**Files:**
- Modify: `backend/src/Dto/Recommendation/SaveRecommendationSettingsRequest.php`
- Modify: `backend/src/Service/Recommendation/RecommendationSettingsResolver.php:36-55`
- Modify: `backend/src/Http/RecommendationSettingsJson.php:26-52`
- Test: `backend/tests/Http/RecommendationSettingsJsonTest.php` (create/extend), `backend/tests/Service/Recommendation/RecommendationSettingsResolverTest.php` (extend)

**Interfaces:**
- Consumes: `RecommendationSettingsValues::$showReasons`, `EffectiveRecommendationSettings::$showReasons` (Task 1).
- Produces: request DTO field `showReasons` (bool) → `values()`; resolver sets `showReasons: $row?->values()->showReasons ?? false`; `RecommendationSettingsJson::state()` emits `'showReasons' => $effective->showReasons`.

- [ ] **Step 1: Write the failing test** — settings JSON exposes showReasons.

```php
public function testStateExposesShowReasons(): void
{
    $effective = $this->effectiveWith(showReasons: true); // helper builds EffectiveRecommendationSettings
    $state = RecommendationSettingsJson::state($effective, workerAlive: true);
    self::assertTrue($state['showReasons']);
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend && php bin/phpunit --filter testStateExposesShowReasons`
Expected: FAIL (undefined array key `showReasons`).

- [ ] **Step 3: Implement**

DTO: add `public bool $showReasons,` (place after `debugEnabled` for readability) and pass `showReasons: $this->showReasons,` into `values()`. Resolver `forUser()`: add `showReasons: $row?->values()->showReasons ?? false,`. JSON `state()`: add `'showReasons' => $effective->showReasons,` next to `debugEnabled`.

- [ ] **Step 4: Run to verify it passes** — the new test plus the resolver test.

Run: `cd backend && php bin/phpunit --filter 'ShowReasons|RecommendationSettingsResolver'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git commit -am "feat(#541): thread showReasons through DTO, resolver and settings JSON"
```

---

## Task 3: Feed annotation visibility value object + decouple emission in `RecommendationFeedJson`

**Files:**
- Create: `backend/src/Http/FeedAnnotationVisibility.php`
- Modify: `backend/src/Http/RecommendationFeedJson.php`
- Test: `backend/tests/Http/RecommendationFeedJsonTest.php` (create/extend)

**Interfaces:**
- Produces: `final readonly class FeedAnnotationVisibility { public function __construct(public bool $showReasons, public bool $showScores) {} }`.
- `RecommendationFeedJson::page(array $rows, ?string $nextCursor, FeedAnnotationVisibility $visibility): array` replaces the `page()`/`pageWithScores()` pair. `private entries(array $rows, FeedAnnotationVisibility $visibility)` adds `recommendationReason` iff `$visibility->showReasons` and `recommendationScore` iff `$visibility->showScores`, each appended independently. `runId`/`runGeneratedAt` stay unconditional.

Rationale: reasons × scores now vary on two independent axes (4 combinations); a single value object keeps this to one method with no boolean-flag parameters, replacing the two-method + private-bool split from #342.

- [ ] **Step 1: Write the failing tests** — all four combinations.

```php
public function testReasonWithoutScoreWhenOnlyReasonsVisible(): void
{
    $out = RecommendationFeedJson::page([$this->row(reason: 'why', score: 900)], null,
        new FeedAnnotationVisibility(showReasons: true, showScores: false));
    self::assertSame('why', $out['entries'][0]['recommendationReason']);
    self::assertArrayNotHasKey('recommendationScore', $out['entries'][0]);
}

public function testScoreWithoutReasonWhenOnlyScoresVisible(): void
{
    $out = RecommendationFeedJson::page([$this->row(reason: 'why', score: 900)], null,
        new FeedAnnotationVisibility(showReasons: false, showScores: true));
    self::assertSame(900, $out['entries'][0]['recommendationScore']);
    self::assertArrayNotHasKey('recommendationReason', $out['entries'][0]);
}

public function testNeitherWhenBothOff(): void
{
    $out = RecommendationFeedJson::page([$this->row(reason: 'why', score: 900)], null,
        new FeedAnnotationVisibility(showReasons: false, showScores: false));
    self::assertArrayNotHasKey('recommendationReason', $out['entries'][0]);
    self::assertArrayNotHasKey('recommendationScore', $out['entries'][0]);
}

public function testBothWhenBothOn(): void
{
    $out = RecommendationFeedJson::page([$this->row(reason: 'why', score: 900)], null,
        new FeedAnnotationVisibility(showReasons: true, showScores: true));
    self::assertSame('why', $out['entries'][0]['recommendationReason']);
    self::assertSame(900, $out['entries'][0]['recommendationScore']);
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend && php bin/phpunit backend/tests/Http/RecommendationFeedJsonTest.php`
Expected: FAIL (class/method signatures not present).

- [ ] **Step 3: Implement** the value object and rewrite `RecommendationFeedJson`:

```php
public static function page(array $rows, ?string $nextCursor, FeedAnnotationVisibility $visibility): array
{
    return ['entries' => self::entries($rows, $visibility), 'nextCursor' => $nextCursor];
}

private static function entries(array $rows, FeedAnnotationVisibility $visibility): array
{
    return array_map(static function (RecommendationFeedRow $row) use ($visibility): array {
        $entry = EntryJson::one($row->row) + [
            'runId' => $row->runId,
            'runGeneratedAt' => $row->runGeneratedAt?->format(\DateTimeInterface::ATOM),
        ];
        if ($visibility->showReasons) {
            $entry += ['recommendationReason' => $row->reason];
        }
        if ($visibility->showScores) {
            $entry += ['recommendationScore' => $row->score];
        }
        return $entry;
    }, $rows);
}
```
Update the class docblock: note #541 splits reason (own flag) from score (`debugEnabled`), superseding #342's coupling.

- [ ] **Step 4: Run to verify it passes**

Run: `cd backend && php bin/phpunit backend/tests/Http/RecommendationFeedJsonTest.php`
Expected: PASS (all four).

- [ ] **Step 5: Commit**

```bash
git add backend/src/Http/FeedAnnotationVisibility.php backend/src/Http/RecommendationFeedJson.php backend/tests/Http/RecommendationFeedJsonTest.php
git commit -m "feat(#541): emit feed reason and score on independent visibility axes"
```

---

## Task 4: Wire `ForYouFeedResponder` to the two independent flags

**Files:**
- Modify: `backend/src/Service/Recommendation/ForYouFeedResponder.php`
- Test: `backend/tests/Service/Recommendation/ForYouFeedResponderTest.php` (create/extend) — plus a functional test through the controller if one exists for the for-you feed route.

**Interfaces:**
- Consumes: `EffectiveRecommendationSettings::$showReasons` and `::$debugEnabled` (Tasks 1–2); `RecommendationFeedJson::page(..., FeedAnnotationVisibility)` (Task 3).
- Produces: `page()` reads effective settings once and builds `new FeedAnnotationVisibility(showReasons: $effective->showReasons, showScores: $effective->debugEnabled)`.

- [ ] **Step 1: Write the failing test** — reasons visible with debug OFF when showReasons ON; score hidden.

```php
public function testReasonsShownWithoutDebugWhenShowReasonsOn(): void
{
    $this->resolver->method('forUser')->willReturn($this->effectiveWith(showReasons: true, debugEnabled: false));
    $this->pager->method('page')->willReturn($this->pageOfOne(reason: 'why', score: 900));

    $out = $this->responder->page($this->user, null, 20);

    self::assertSame('why', $out['entries'][0]['recommendationReason']);
    self::assertArrayNotHasKey('recommendationScore', $out['entries'][0]);
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend && php bin/phpunit --filter testReasonsShownWithoutDebugWhenShowReasonsOn`
Expected: FAIL (reason absent; old code gates both on debugEnabled).

- [ ] **Step 3: Implement**

```php
public function page(User $user, ?string $cursor, int $limit): array
{
    $page = $this->pager->page((int) $user->getId(), $cursor, $limit);
    $effective = $this->settings->forUser($user);

    return RecommendationFeedJson::page(
        $page->rows,
        $page->nextCursor,
        new FeedAnnotationVisibility(
            showReasons: $effective->showReasons,
            showScores: $effective->debugEnabled,
        ),
    );
}
```
Update the class docblock (debug-aware/plain shape → two independent axes).

- [ ] **Step 4: Run to verify it passes**

Run: `cd backend && php bin/phpunit --filter ForYouFeedResponder`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git commit -am "feat(#541): decouple reason visibility from debug in the for-you feed"
```

---

## Task 5: Account backup export/import round-trips `showReasons`

**Files:**
- Modify: `backend/src/Service/Backup/AccountBackupExporter.php` (recommendation-settings line, ~:209)
- Modify: `backend/src/Service/Backup/Dto/AccountLine.php` (~:53) and its importer counterpart
- Test: `backend/tests/Service/Backup/*` round-trip test (extend existing)

**Interfaces:**
- Consumes: `RecommendationSettingsValues::$showReasons`.
- Produces: the backup line carries `showReasons`; import restores it. Absent key on an old backup imports as `false` (matches the default).

- [ ] **Step 1: Write the failing test** — export then import preserves `showReasons: true`.

```php
public function testBackupRoundTripPreservesShowReasons(): void
{
    // arrange a user whose recommendation settings have showReasons = true
    $line = $this->exporter->export($user);          // shape per existing test helpers
    $this->importer->import($line, $freshUser);
    self::assertTrue($freshUser->getRecommendationSettings()->values()->showReasons);
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd backend && php bin/phpunit --filter testBackupRoundTripPreservesShowReasons`
Expected: FAIL.

- [ ] **Step 3: Implement** — add `showReasons` to `AccountLine`, to the exporter's settings mapping, and to the importer, defaulting a missing key to `false`.

- [ ] **Step 4: Run to verify it passes**

Run: `cd backend && php bin/phpunit --filter Backup`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git commit -am "feat(#541): include showReasons in account backup round-trip"
```

---

## Task 6: Doctrine migration (add column, backfill OFF) + dual-dialect verification

**Files:**
- Create: `backend/migrations/VersionYYYYMMDDHHMMSS.php` (generated)
- Reference: `docs/local-docker.md`, the CI migration leg

**Interfaces:**
- Consumes: the `showReasons` column mapping from Task 1.
- Produces: `user_recommendation_settings.show_reasons BOOLEAN NOT NULL DEFAULT 0`. The `NOT NULL DEFAULT false` column definition means existing rows backfill to `false` automatically — no data migration statement needed. Confirm the generated `up()` reflects this; if the diff omits a default, add it explicitly so existing rows are valid.

- [ ] **Step 1: Warm cache and generate the migration**

Run: `cd backend && bin/console cache:warmup && bin/console doctrine:migrations:diff`
Expected: a new migration adding `show_reasons` to `user_recommendation_settings`.

- [ ] **Step 2: Review the generated SQL** — ensure `up()` adds the column `NOT NULL DEFAULT 0` (MySQL) / matches SQLite; ensure `down()` drops it. Edit the description comment to reference #541.

- [ ] **Step 3: Verify from empty on SQLite (scratch DB, never the dev DB)**

Run: `cd backend && APP_ENV=test bin/console doctrine:migrations:migrate --no-interaction && APP_ENV=test bin/console doctrine:schema:validate`
Expected: migrates clean; schema in sync with metadata.

- [ ] **Step 4: Verify from empty on MySQL (Docker)**

Run: `docker compose exec php bin/console doctrine:migrations:migrate --no-interaction && docker compose exec php bin/console doctrine:schema:validate`
Expected: migrates clean on MySQL; schema valid. (Apply the migration to the running dev MySQL too so the live container matches the branch.)

- [ ] **Step 5: Commit**

```bash
git add backend/migrations
git commit -m "feat(#541): migration adds show_reasons column (default off)"
```

---

## Task 7: Backend gate — `composer check`, PHPMD, PhpStorm inspections, Infection

**Files:** all backend files touched in Tasks 1–6.

- [ ] **Step 1:** `cd backend && composer cs:fix && composer cs` → PSR-12 clean.
- [ ] **Step 2:** `cd backend && bin/console cache:warmup && composer stan` → PHPStan max clean (no new baseline, no unexplained ignores).
- [ ] **Step 3:** `cd backend && composer md` → PHPMD clean on every touched `src` file (fix design, not thresholds).
- [ ] **Step 4:** PhpStorm inspections (`mcp__phpstorm__lint_files`) on changed PHP → zero ERROR/WARNING.
- [ ] **Step 5:** `cd backend && composer infection:diff` → MSI at/above the gate for the changed lines; kill any escaped mutants with a targeted test.
- [ ] **Step 6:** Run the full native suite `cd backend && php bin/phpunit` and the MySQL leg `docker compose exec php vendor/bin/phpunit`. Scan `backend/var/log/dev.log` for new deprecations/errors.
- [ ] **Step 7: Commit** any fixes: `git commit -am "chore(#541): satisfy backend quality gates"`.

---

> **Frontend delivers a design SYSTEM first (Tasks 8–13), then the AI page consumes it (Tasks 14–17).** New primitives live in `frontend/src/app/shared/settings/`. Each is standalone, signal-based, OnPush, with a sibling `.scss` (tokens only — no hex, no raw `px` spacing). They are the canonical building blocks every future settings/admin section composes.

## Task 8: Shared `SettingsGroupComponent`

**Files:**
- Create: `frontend/src/app/shared/settings/settings-group/settings-group.component.ts`
- Create: `frontend/src/app/shared/settings/settings-group/settings-group.component.html`
- Create: `frontend/src/app/shared/settings/settings-group/settings-group.component.scss`
- Test: `frontend/src/app/shared/settings/settings-group/settings-group.component.spec.ts`

**Interfaces:**
- Produces: `<app-settings-group [icon]="'smart_toy'" [title]="'For You'" [caption]="'How your feed is built'">…rows…</app-settings-group>`.
  Inputs: `icon: string` (Material Symbol name, rendered via `app-icon`), `title: string`, `caption?: string`. Renders the group header (tinted icon chip + title + caption) and a projected body panel — the Grouped card surface (`--surface-1`, `--border`, `--radius-lg`, soft `--panel-shadow`). Body content is `<ng-content/>` (rows/disclosures).

- [ ] **Step 1: Write the failing test**

```ts
it('renders the icon chip, title and caption and projects rows', () => {
  const fixture = TestBed.createComponent(HostComponent); // host with <app-settings-group> + a projected div
  fixture.detectChanges();
  const host: HTMLElement = fixture.nativeElement;
  expect(host.querySelector('.g-title')?.textContent).toContain('For You');
  expect(host.querySelector('.g-caption')?.textContent).toContain('How your feed is built');
  expect(host.querySelector('app-icon')).toBeTruthy();
  expect(host.querySelector('[data-projected]')).toBeTruthy();
});
```

- [ ] **Step 2: Run to verify it fails** — `cd frontend && npx jest settings-group` → FAIL.
- [ ] **Step 3: Implement** the component (inputs via `input()`, `app-icon` in a `.g-icon` chip, `.g-title`/`.g-caption`, `<div class="panel"><ng-content/></div>`). SCSS ports the mockup's `.g-head` + Grouped `.panel` (tokens only). Add a `--panel-shadow` token to `theme/tokens.scss` mode-invariant block if not present (light + dark values), since it is a design-system token, not a one-off.
- [ ] **Step 4: Run to verify it passes** — same command → PASS. `npm run check` scoped → clean.
- [ ] **Step 5: Commit** — `git commit -m "feat(#541): add shared SettingsGroup primitive"`.

## Task 9: Shared `SettingsRowComponent`

**Files:**
- Create: `frontend/src/app/shared/settings/settings-row/settings-row.component.{ts,html,scss,spec.ts}`

**Interfaces:**
- Produces: `<app-settings-row [title]="'Show why articles were picked'" [description]="'Adds a short reason…'" [stackable]="true">…control…</app-settings-row>`.
  Inputs: `title: string`, `description?: string`, `stackable = false` (on mobile, a stackable row's control goes full-width; a toggle stays fixed). Layout: title + description stacked left, projected control right, vertically centered; inset hairline divider handled by the parent group between rows. Optional projected info-tip trigger next to the title (a named slot or the title supports a `[titleTip]` content slot).

- [ ] **Step 1: Write the failing test** — renders title/description, projects the control, applies `stackable` class.
- [ ] **Step 2: Run to verify it fails** — `cd frontend && npx jest settings-row` → FAIL.
- [ ] **Step 3: Implement** (`.row` flex, `.row-main` with `.row-title`/`.row-desc`, `.row-control` slot; `stackable` class). SCSS ports the mockup's `.row` rules (tokens only).
- [ ] **Step 4: Run to verify it passes** — same → PASS. `npm run check` scoped → clean.
- [ ] **Step 5: Commit** — `git commit -m "feat(#541): add shared SettingsRow primitive"`.

## Task 10: `disclosure` gains a `drill-in` appearance

**Files:**
- Modify: `frontend/src/app/shared/disclosure/disclosure.component.ts` (add `'drill-in'` to the `appearance` union)
- Modify: `frontend/src/app/shared/disclosure/disclosure.component.scss`
- Test: `frontend/src/app/shared/disclosure/disclosure.component.spec.ts`

**Interfaces:**
- Consumes: nothing new.
- Produces: `appearance="drill-in"` renders the summary as a full-width Grouped row — projected heading left, optional description, a trailing chevron that rotates on open — reusing the existing `<details>/<summary>` machinery and `startOpen`/`opened` API. Existing appearances (`pill`/`row`/`card-header`) are untouched.

- [ ] **Step 1: Write the failing test** — with `appearance="drill-in"` the summary carries the `is-drill-in` class and the chevron rotates on open (`details[open]`).
- [ ] **Step 2: Run to verify it fails** — `cd frontend && npx jest disclosure` → FAIL.
- [ ] **Step 3: Implement** — extend the union type and add the `summary.is-drill-in` SCSS block (full-width, trailing rotating chevron in the Grouped style; DRY with the existing `is-row`/`is-card-header` chevron rules).
- [ ] **Step 4: Run to verify it passes** — same → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(#541): add drill-in appearance to the disclosure primitive"`.

## Task 11: Shared `SettingsSaveBarComponent`

**Files:**
- Create: `frontend/src/app/shared/settings/save-bar/save-bar.component.{ts,html,scss,spec.ts}`

**Interfaces:**
- Produces: `<app-settings-save-bar [dirty]="dirty()" [saving]="saving()" (save)="onSave()" (reset)="onReset()"/>`.
  Inputs: `dirty: boolean`, `saving = false`. Outputs: `save`, `reset`. Renders an "unsaved changes" indicator when `dirty`, a Reset (ghost) and Save (primary, disabled unless `dirty`, spinner when `saving`). The success confirmation is the shared global toast — the CONSUMER fires `shared/toast` on a successful persist; the bar only owns dirty/save/reset. This replaces the ad-hoc `<p class="saved">` pattern.

- [ ] **Step 1: Write the failing test** — dirty shows the indicator and enables Save; clicking Save emits `save`; not-dirty disables Save.
- [ ] **Step 2: Run to verify it fails** — `cd frontend && npx jest save-bar` → FAIL.
- [ ] **Step 3: Implement** (reuse `shared/button`, `shared/spinner`). SCSS ports the mockup's `.savebar`/`.unsaved` (tokens only).
- [ ] **Step 4: Run to verify it passes** — same → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(#541): add shared settings SaveBar primitive"`.

## Task 12: `app-info-tip` becomes a popover

**Files:**
- Modify: `frontend/src/app/shared/info-tip/info-tip.component.{ts,html,scss}`
- Test: `frontend/src/app/shared/info-tip/info-tip.component.spec.ts`
- Re-check EVERY consumer (grep `app-info-tip`).

**Interfaces:**
- Produces: the tip panel renders as a **floating popover** anchored to the ⓘ trigger (absolute, above surrounding content, `box-shadow`, `max-width`, edge-clamped so it never clips on a narrow viewport), click-to-toggle, dismiss on outside-click (keep `DismissOnOutsideDirective`) and Escape, one open at a time. It no longer claims an in-flow full-width line; consumers' `flex-wrap` workarounds (#433) become inert but harmless.

> Design note: this reverses the deliberate in-flow choice (#433/#372, which avoided phone popover-collision handling). Because it floats now, the edge-clamp is required, not optional.

- [ ] **Step 1: Write the failing test** — opening the tip does not shift sibling layout (panel is absolutely positioned); the panel closes on Escape and on outside click.
- [ ] **Step 2: Run to verify it fails** — `cd frontend && npx jest info-tip` → FAIL.
- [ ] **Step 3: Implement** the popover (relative wrapper + absolute panel, shadow, `max-width`, edge clamp, Escape handler). Update the component docblock to record the popover decision (superseding the in-flow rationale) and keep the "already-translated strings, not i18n keys" contract.
- [ ] **Step 4: Run to verify it passes** — same → PASS. Grep `app-info-tip` and smoke-check each surface (settings AI, admin, `app-field` tips).
- [ ] **Step 5: Commit** — `git commit -m "feat(#541): open info tips as a popover"`.

## Task 13: Document the settings design language

**Files:**
- Modify: `docs/design-language.md`

**Interfaces:**
- Produces: a new "Settings design system" section cataloguing the primitives from Tasks 8–12 — `app-settings-group`, `app-settings-row`, `app-disclosure appearance="drill-in"`, `app-settings-save-bar`, the popover `app-info-tip` — with: what each is for, its inputs/outputs, and worked composition snippets. Records the two conventions this redesign settles: (1) **save-by-control-type** — toggles/selects save on change with the global toast; text/number fields wait for the SaveBar's explicit Save with an unsaved indicator; (2) **feature sections compose these primitives, never restyle** — the Grouped look lives here, not in feature `.scss`.

- [ ] **Step 1:** Add the section with the catalog + composition examples (copy the real inputs/outputs from Tasks 8–12 so the doc and code agree).
- [ ] **Step 2:** Add a short "Adding a new settings section" recipe: group per concern, rows for controls, drill-in for advanced, SaveBar for typed fields, info-tip for help.
- [ ] **Step 3:** `cd frontend && npm run check` (Prettier over the doc if configured) → clean.
- [ ] **Step 4: Commit** — `git commit -m "docs(#541): document the settings design system"`.

## Task 14: recommendation service — `showReasons` + save-by-control-type

**Files:**
- Modify: `frontend/src/app/settings/recommendation-settings.service.ts`
- Test: `frontend/src/app/settings/recommendation-settings.service.spec.ts` (create/extend)

**Interfaces:**
- Consumes: backend `showReasons` (Tasks 2–4).
- Produces: `state` includes `showReasons: boolean`; a `setShowReasons(v)` setter; an **instant persist** path (used by toggles/selects) that PUTs the whole current recommendations payload immediately, and an **explicit persist** path (`save()`) for the typed fields; both target PUT `/api/me/ai/recommendations` and include `showReasons`. A `dirty` signal tracks unsaved typed-field edits.

- [ ] **Step 1: Write the failing tests** — (a) `setShowReasons(true)` triggers an immediate PUT whose body has `showReasons: true`; (b) editing a numeric cap sets `dirty` and does NOT PUT until `save()`.
- [ ] **Step 2: Run to verify they fail** — `cd frontend && npx jest recommendation-settings.service` → FAIL.
- [ ] **Step 3: Implement** the state field, setter, instant/explicit persist paths, and `dirty` signal.
- [ ] **Step 4: Run to verify they pass** — same → PASS.
- [ ] **Step 5: Commit** — `git commit -m "feat(#541): showReasons + save-by-control-type in the recommendation service"`.

## Task 15: Rebuild the recommendation card by composing the primitives

**Files:**
- Modify: `frontend/src/app/settings/recommendation-settings-card.component.{ts,html,scss}`
- Test: `frontend/src/app/settings/recommendation-settings-card.component.spec.ts`
- Reference: mockup `ai-settings-redesign.html`, Grouped "For You" group.

**Interfaces:**
- Consumes: Tasks 8–12, 14.
- Produces: the card composed from `app-settings-group` + `app-settings-row`s + `app-disclosure appearance="drill-in"` (Expert) + `app-settings-save-bar` + popover `app-info-tip`s. The **"Show why articles were picked" toggle is the first row** (bound to `showReasons`, instant save + toast); cadence + look-back are instant selects; Expert drill-in holds the guidance textarea, the six caps (2-col grid), context window, the fixed-prompt drill-in, and the Debug toggle in an inset; the SaveBar covers only the typed fields; Purge sits in its own group. **The component `.scss` holds only glue (grid gaps), no Grouped look** — that lives in the primitives.

- [ ] **Step 1:** Rewrite the template composing the primitives, wiring each control to its signal/handler (instant vs SaveBar).
- [ ] **Step 2:** Reduce the `.scss` to layout glue only (the num-grid, spacing); delete the old bespoke card styling.
- [ ] **Step 3:** `cd frontend && npx jest recommendation-settings-card` → PASS (update the spec to the new DOM).
- [ ] **Step 4:** `cd frontend && npm run check` scoped → clean.
- [ ] **Step 5: Commit** — `git commit -m "feat(#541): rebuild the recommendation card on the settings primitives"`.

## Task 16: Rebuild `ai-section` (provider) by composing the primitives + model search-box fix

**Files:**
- Modify: `frontend/src/app/settings/ai-section.component.{ts,html,scss}`
- Modify: `frontend/src/app/shared/searchable-select/*.scss` (search field → bordered input)
- Test: `frontend/src/app/settings/ai-section.component.spec.ts`, `frontend/src/app/shared/searchable-select/*.spec.ts`
- Reference: mockup Grouped "Provider" group + connection manager + model picker.

**Interfaces:**
- Consumes: Tasks 8–12.
- Produces: a **folded provider summary** (`✓ <provider> · <model> · connected` + Manage) when a ready active config exists, expanding to the connection manager (composed from the primitives); the guide + add-connection as drill-in/collapsed; Diagnostics grouped last, collapsed. The `searchable-select` search field becomes a **bordered input** (search icon, placeholder, focus ring) instead of the current `border: 0; border-bottom: 1px` — re-check the other two consumers.

- [ ] **Step 1:** Add the folded/`managing` signal; compose the summary + manager from the primitives.
- [ ] **Step 2:** Restyle the `searchable-select` search field (bordered box + focus ring, tokens only); smoke-check the other consumers.
- [ ] **Step 3:** `cd frontend && npx jest ai-section searchable-select` → PASS.
- [ ] **Step 4:** `cd frontend && npm run check` scoped → clean.
- [ ] **Step 5: Commit** — `git commit -m "feat(#541): rebuild the provider section on the settings primitives"`.

## Task 17: i18n strings + reader reason/score check

**Files:**
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`
- Verify: `frontend/src/app/reader/recommendation-strip/recommendation-strip.component.{ts,html}`

**Interfaces:**
- Produces: EN + DE copy for `settings.ai.recommendations.showReasons` (label) and `settings.ai.info.showReasons` (help); `settings.ai.info.debug`/`debugHint` updated to drop the reasons mention. Reader `recommendation-strip` needs no logic change (reason shows on `recommendationReason` presence, score on `recommendationScore` presence — presence now reflects the two backend flags); confirm and adjust a spec only if it references debug-coupled reason copy.

- [ ] **Step 1:** Add EN keys; mirror translated in DE; update the debug help/hint in both.
- [ ] **Step 2:** Confirm `recommendation-strip` is presence-driven; update any stale spec.
- [ ] **Step 3:** `cd frontend && npm run check` → clean (no missing translations).
- [ ] **Step 4: Commit** — `git commit -m "feat(#541): add showReasons copy and update debug help"`.

## Task 18: Full verification + live smoke + PR

- [ ] **Step 1:** `cd frontend && npm run check` → green.
- [ ] **Step 2:** `cd backend && composer check && php bin/phpunit` → green; `docker compose exec php vendor/bin/phpunit` → green; `composer infection:diff` at/above gate.
- [ ] **Step 3:** Live-smoke `/settings/ai` in the running dev app: sticky bar; folded provider + Manage; reasons toggle instant-saves + toast; a numeric edit shows "unsaved" and Save clears it; Expert drill-in; popover tips; both themes; mobile width. On the reader "For You" list: reasons appear when the toggle is on with debug off; scores only with debug on.
- [ ] **Step 4:** Scan `backend/var/log/dev.log` for new errors/deprecations.
- [ ] **Step 5:** Open the PR: body `Closes #541`; note the new `shared/settings/` primitives + `docs/design-language.md` as the reusable system; verify the issue auto-closes on merge.

---

## Self-Review

**Spec coverage:** #541 acceptance criteria mapped — the reusable design system (Tasks 8–13, the core of the "general design language" steer), Grouped rebuild composing it (15–16), `showReasons` entity/DTO/JSON/resolver/migration (1–2, 6), independent reason/score emission (3–4), info-tip popover (12), backend gates + Infection (7), frontend check + responsive/themes (14–18), backup round-trip (5), reader verification (17). Sticky top bar shipped (Task 0). The settings/admin-wide rollout stays a follow-up — but this plan delivers the primitives + docs it will build on, so the follow-up is composition, not redesign.

**Placeholder scan:** Backend tasks carry real test + implementation code. Frontend primitive tasks give real inputs/outputs + test snippets; the two page-rebuild tasks reference the concrete validated mockup as their visual source and name exact files, bindings, primitives, and the gate to pass — not placeholders.

**Type consistency:** `showReasons` (bool) is used identically across Values, Effective, DTO, resolver, JSON, service, and card; `FeedAnnotationVisibility(showReasons, showScores)` is consistent across Tasks 3–4; `showScores` maps to `debugEnabled` only at the responder boundary (Task 4). Primitive selectors/inputs (`app-settings-group` icon/title/caption, `app-settings-row` title/description/stackable, `app-settings-save-bar` dirty/saving/save/reset, `app-disclosure appearance="drill-in"`) are named identically in their defining task (8–11) and their consuming tasks (15–16).
