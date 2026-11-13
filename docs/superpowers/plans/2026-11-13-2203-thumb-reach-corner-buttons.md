# Thumb-reach corner buttons Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On touch screens every floating corner disc stacks in one corner, chosen by a per-account handedness setting, and all corner discs drop to a 40% tone.

**Architecture:** A `Handedness` enum on `ReadingSettings` travels its own `PATCH /api/me/handedness`, the `/api/me` preferences object and the backup `account` line, exactly as `magazineStyle` does. On the frontend a `HandednessService` (a twin of `MagazineStyleService`) feeds a `left-handed` class onto a new `.corner-cluster` wrapper in the list and the article view; one SCSS mixin lays the cluster out — today's split on a fine pointer, one stacked corner on `pointer: coarse`.

**Tech Stack:** Symfony 8.1 / PHP 8.5 / Doctrine (MySQL + SQLite), Angular 20 standalone + signals, SCSS, Jest, PHPUnit.

**Spec:** GitHub issue [#2203](https://github.com/larspohlmann/simple-feed-reader/issues/2203) — the body holds every decision, the first comment the design write-up. Read both.

## Global Constraints

- Branch `feature/2203-thumb-reach-corner-buttons`; commits `type(#2203): …`; no Claude attribution anywhere.
- Gap inside a group: `--space-2` (8px). Gap between the bottom pair and the column: `--space-5` (24px).
- Corner disc tone: `color-mix(in srgb, <tone> 40%, transparent)` (was 65%), at every width, every disc.
- "Touch screen" means `@media (pointer: coarse)`. With a fine pointer the layout is exactly today's, whatever the handedness.
- Handedness values: `right` (default) and `left`. Stored per account, never per device (`localStorage` is only a paint cache).
- List column, top to bottom: mark all read, mark above as read. Article column, top to bottom: favourite, save, share.
- The pair: back to top inner, the outer button (list: sidebar; article: back) on the screen edge; left-handed mirrors all of it.
- Clean Code rules of `CLAUDE.md` apply; every touched `backend/src` file PHPMD-clean; no new comments unless they clear the bar.

## Review Focus

1. **The list's pair renders no button** (sidebar visible, list near the top): the column must sit on the bottom corner itself, not float 24px up. Owned by Task 6 Step 5 (the shell passes no corner template while the sidebar is in view), checked in Task 7.
2. **A toast lifts the corner** (`--corner-lift`): the whole cluster rises, not only the pair. Task 7 live check.
3. **A short list** (an open playlist squeezes the list): today the discs hide below `13rem`, sized for one row of discs. The touch stack is ~164px tall, so on a coarse pointer the cluster hides below `18rem` instead (120px header clearance + 164px stack ≈ 17.75rem). Owned by Task 6, checked in Task 7.
4. **Left-handed on a fine pointer**: the class is present but nothing moves. Task 7 live check.
5. **Account switch**: a left-handed account signs out, a right-handed one signs in — the next account must start right-handed, cache included. Owned by Task 4 (`reset()` spec).

---

### Task 1: Handedness on ReadingSettings

**Files:**
- Create: `backend/src/Enum/Handedness.php`
- Modify: `backend/src/Entity/ReadingSettings.php`
- Create: `backend/migrations/Version20261113140000.php`
- Test: `backend/tests/Entity/ReadingSettingsTest.php`

**Interfaces:**
- Produces: `enum App\Enum\Handedness: string { case Right = 'right'; case Left = 'left'; }`; `ReadingSettings::getHandedness(): Handedness`, `ReadingSettings::setHandedness(Handedness $handedness): void`.

- [ ] **Step 1: Write the failing tests** in `ReadingSettingsTest`: `testANewAccountIsRightHanded` (`new ReadingSettings(...)` as the file's other tests build it, assert `Handedness::Right === getHandedness()`), `testTheHandednessKeepsWhatItIsGiven` (set `Left`, assert `Left`).
- [ ] **Step 2: Run** `php bin/phpunit tests/Entity/ReadingSettingsTest.php` — expect FAIL (class `Handedness` not found).
- [ ] **Step 3: Implement.** Enum as above. Column on `ReadingSettings`, after `magazineStyle`:
  `#[ORM\Column(length: 5, enumType: Handedness::class, options: ['default' => 'right'])] private Handedness $handedness = Handedness::Right;` plus getter/setter.
  Migration modelled on `Version20261113110000.php` (skip-if / abort-if-unsupported-platform / non-transactional), description `'Let an account choose the corner the floating buttons sit in on a touch screen (#2203).'`; `up`: `ALTER TABLE user_reading_settings ADD COLUMN handedness VARCHAR(5) DEFAULT 'right' NOT NULL` (same statement on both platforms), skipped when the column exists; `down`: `ALTER TABLE user_reading_settings DROP COLUMN handedness`.
- [ ] **Step 4: Run** the test — PASS. Then the CI migration leg on SQLite, from `backend/`:
  ```bash
  rm -f var/migration-check.db && DATABASE_URL='sqlite:///%kernel.project_dir%/var/migration-check.db' php bin/console doctrine:migrations:migrate -n && DATABASE_URL='sqlite:///%kernel.project_dir%/var/migration-check.db' php bin/console doctrine:schema:validate
  ```
  Expected: `[OK] The mapping files are correct.` and `[OK] The database schema is in sync with the mapping files.` Then the MySQL leg: `docker compose exec php bin/console doctrine:migrations:migrate -n` and `docker compose exec php bin/console doctrine:schema:validate` — same two OKs (this also applies the migration to the live dev DB, which Task 7 needs).
- [ ] **Step 5: Commit** `feat(#2203): store the account's handedness`.

### Task 2: PATCH /api/me/handedness and the profile field

**Files:**
- Create: `backend/src/Dto/Me/UpdateHandednessRequest.php`
- Modify: `backend/src/Controller/Api/ReaderPreferencesController.php`, `backend/src/Service/Account/AccountPreferencesWriter.php`, `backend/src/Http/MeJson.php`
- Create: `backend/tests/Controller/Api/HandednessControllerTest.php`
- Modify tests: `backend/tests/Controller/Api/MeControllerTest.php`, `backend/tests/Controller/Api/MeRoutesTest.php`, `backend/tests/Service/Account/AccountPreferencesWriterTest.php`

**Interfaces:**
- Consumes: Task 1's enum and accessors.
- Produces: route `api_me_update_handedness`, `PATCH /api/me/handedness`, body `{"handedness":"right"|"left"}`, response = the profile (`MeProfileJson::of`); `/api/me` → `preferences.handedness` (string), placed right after `magazineStyle`; `AccountPreferencesWriter::changeHandedness(User $user, Handedness $handedness): void`.

- [ ] **Step 1: Write the failing tests.**
  `HandednessControllerTest` mirrors `MagazineStyleControllerTest` case for case: `testANewAccountIsRightHanded` (`'right'`), `testChangingTheHandednessPersistsIt` (`'left'`, reloaded via `StoredUserSettings::reading(...)->getHandedness()` is `Handedness::Left`), `testAnUnknownHandednessIsRejected` (`'both'` → 422), `testAnEmptyBodyIsRejected` (422), `testASignedOutClientIsRejected` (401), `testAMagazineStyleWriteLeavesTheHandednessAlone` (patch `left`, then `PATCH /api/me/magazine-style {"magazineStyle":"airy"}`, handedness still `'left'`). Status codes as `Response::HTTP_*` constants.
  `MeControllerTest`: add `'handedness' => 'right'` after `'magazineStyle' => 'boxed'` in the default array; in the every-setting test PATCH `/api/me/handedness` with `left` and add `"handedness":"left",` after `"magazineStyle":"airy",` in `$expected`.
  `MeRoutesTest`: an `api_me_update_handedness` row (`PATCH`, `/api/me/handedness`, `ReaderPreferencesController::class . '::updateHandedness'`) in the style of the magazine-style row.
  `AccountPreferencesWriterTest`: `testChangeHandednessPersists`, shaped like `testChangeMagazineStylePersists`.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'Handedness|MeControllerTest|MeRoutesTest|AccountPreferencesWriterTest'` — expect FAIL (404 / missing key / missing method).
- [ ] **Step 3: Implement.** `UpdateHandednessRequest(public Handedness $handedness)`, final readonly, no default, no docblock. Controller action `updateHandedness` with the one-line docblock the sibling actions carry (`/** Its own PATCH for the reason updatePreferences() gives. */`). Writer method mirrors `changeMagazineStyle`. `MeJson`: `'handedness' => $settings->reading->getHandedness()->value`.
- [ ] **Step 4: Run** the same filter — PASS.
- [ ] **Step 5: Commit** `feat(#2203): let the account change its handedness`.

### Task 3: Backup carries the handedness

**Files:**
- Modify: `backend/src/Service/Backup/Dto/AccountLine.php`, `backend/src/Service/Backup/Pass/RestoreLoadPass.php`, `backend/tests/Support/BackupFieldDeclarations.php`, `backend/tests/Support/FullyPopulatedAccount.php`, `docs/backup.md`
- Test: `backend/tests/Service/Backup/Dto/AccountLineTest.php`

**Interfaces:**
- Consumes: Task 1.
- Produces: backup key `handedness` on the `account` line, written after `magazineStyle`.

- [ ] **Step 1: Write the failing tests** in `AccountLineTest`, beside the magazine-style ones: `testItReadsTheHandedness` (`'left'` → `Handedness::Left`), `testAMissingHandednessFallsBackToRight`, `testAnInvalidHandednessFallsBackToRight` (`'both'`); extend the export test (the one asserting the JSON string at ~line 240) with `"handedness":"left"` after `"magazineStyle":"airy",` after setting `Left` on its settings. Add `'handedness' => 'handedness'` to `BackupFieldDeclarations::BACKED_UP[ReadingSettings::class]` and `$reading->setHandedness(Handedness::Left)` in `FullyPopulatedAccount` beside the magazine style.
- [ ] **Step 2: Run** `php bin/phpunit tests/Service/Backup` — expect FAIL (unknown named argument / missing key; `BackupSchemaCoverageTest` names `handedness`).
- [ ] **Step 3: Implement.** `AccountLine`: constructor field `public Handedness $handedness` after `$magazineStyle`; `fromLine` reads `Handedness::tryFrom(LineFieldWithDefault::string($line, 'handedness', Handedness::Right->value)) ?? Handedness::Right` (restore over reject, as the style does; no new comment — the style's comment covers the pattern); the user-side constructor reads `$settings->reading->getHandedness()`; the export array writes `'handedness' => $this->handedness->value`. `RestoreLoadPass`: `$this->accountSettings->reading->setHandedness($line->handedness);` after the magazine style. `docs/backup.md` `account` row: after the magazine style, add `which corner the floating buttons sit in on a touch screen (\`handedness\`, \`right\` or \`left\`; a file without it, or with another value, restores \`right\`)`. Backup fixtures under `tests/Fixtures/backup/` stay as they are: they prove the missing-field fallback.
- [ ] **Step 4: Run** `php bin/phpunit tests/Service/Backup` — PASS.
- [ ] **Step 5: Run backend gates** from `backend/`: `composer check`, `composer md`, `php bin/phpunit`, and in parallel `docker compose exec php composer test:parallel`. All green; PHPMD reports nothing for the touched `src` files.
- [ ] **Step 6: Commit** `feat(#2203): back up and restore the handedness`.

### Task 4: HandednessService on the frontend

**Files:**
- Create: `frontend/src/app/core/preferences/handedness.ts`, `handedness-writer.ts`, `http-handedness-writer.ts`, `handedness.service.ts`, `handedness.service.spec.ts`
- Modify: `frontend/src/app/core/auth/auth.service.ts`, `frontend/src/app/app.config.ts`, and every fixture `grep -rln "magazineStyle: '" frontend/src frontend/e2e` lists (9 files today)

**Interfaces:**
- Consumes: Task 2's endpoint and `preferences.handedness`.
- Produces: `type Handedness = 'right' | 'left'`, `HANDEDNESS: readonly Handedness[] = ['right', 'left']`, `HANDEDNESS_KEY = 'sfr.handedness'`, `asHandedness(value: string | null): Handedness | null`; token `HANDEDNESS_WRITER` (`write(hand: Handedness): Observable<boolean>`, root default writes nothing and resolves `true`); `HttpHandednessWriter` (`PATCH ${base}/api/me/handedness`, body `{ handedness }`); `HandednessService` with `hand: Signal<Handedness>`, `saveFailed: Signal<boolean>`, `set(hand)`, `adopt(user: CurrentUser)`, `reset()`; `UserPreferences.handedness: string` (required — the server always sends it).

These four files are a copy of the magazine-style quartet with the names and values swapped; that is the second enum preference, so no shared base yet (DRY's third occurrence is the refactor).

- [ ] **Step 1: Write the failing spec** `handedness.service.spec.ts`, mirroring `magazine-style.service.spec.ts` test for test: starts `'right'` with nothing cached; starts from the cache; ignores an unknown cached value; `set('left')` caches and writes through; a failed write sets `saveFailed` and keeps `'left'`; the next write clears the failure; `adopt` caches the account value without writing; `reset()` removes `sfr.handedness` and returns to `'right'`.
- [ ] **Step 2: Run** `docker compose exec -T frontend npx jest src/app/core/preferences/handedness.service.spec.ts` — FAIL (module not found).
- [ ] **Step 3: Implement** the four files; in `AuthService` call `this.handedness.adopt(user)` beside `this.magazineStyle.adopt(user)` and `this.handedness.reset()` beside `this.magazineStyle.reset()`; in `app.config.ts` provide `{ provide: HANDEDNESS_WRITER, useExisting: HttpHandednessWriter }` beside the magazine-style provider; add `handedness: string` after `magazineStyle` in `UserPreferences` and `handedness: 'right'` after each fixture's `magazineStyle: '…'`. If the auth service spec asserts the adopt/reset calls for the magazine style, add the same two assertions for handedness.
- [ ] **Step 4: Run** the spec — PASS; then `docker compose exec -T frontend npm run check` — green.
- [ ] **Step 5: Commit** `feat(#2203): keep the account's handedness on the frontend`.

### Task 5: The setting in Settings → Preferences

**Files:**
- Modify: `frontend/src/app/settings/preferences/preferences-section.component.ts`, `.html`, `.spec.ts`; `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json`

**Interfaces:**
- Consumes: Task 4's `HandednessService`, `HANDEDNESS`.

- [ ] **Step 1: Write the failing spec** in `preferences-section.component.spec.ts`, in the style of its magazine-style cases: the row renders a segmented choice with the two options; picking `left` calls `HandednessService.set('left')`; a `saveFailed()` of `true` shows the error banner.
- [ ] **Step 2: Run** `docker compose exec -T frontend npx jest src/app/settings/preferences` — FAIL.
- [ ] **Step 3: Implement.** A `app-settings-row` right after the magazine-style row and its banner, identical in shape: `[title]="'settings.handedness' | transloco"`, `[description]="'settings.handednessHint' | transloco"`, `[stackable]="true"`, `app-segmented-choice` with `[options]="handednessOptions"`, `[selected]="handedness.hand()"`, `ariaLabelKey="settings.handedness"`, `labelPrefix="settings.handednessOption."`, `(pick)="handedness.set($event)"`; then `@if (handedness.saveFailed())` banner with `settings.handednessSaveFailed`. The row shows at every width: the setting is per account, so a desktop session may set it for the phone. Copy, under `settings` next to the magazine-style keys:

  | key | en | de |
  |---|---|---|
  | `handedness` | Handedness | Händigkeit |
  | `handednessHint` | Which corner the floating buttons sit in on a touch screen. | In welcher Ecke die schwebenden Knöpfe auf einem Touchscreen sitzen. |
  | `handednessSaveFailed` | The handedness could not be saved to your account. | Die Händigkeit konnte nicht in deinem Konto gespeichert werden. |
  | `handednessOption.right` | Right-handed | Rechtshändig |
  | `handednessOption.left` | Left-handed | Linkshändig |

- [ ] **Step 4: Run** the spec — PASS; `npm run check` green.
- [ ] **Step 5: Commit** `feat(#2203): choose the handedness in the preferences`.

### Task 6: One corner cluster for the list and the article, at 40%

**Files:**
- Modify: `frontend/src/app/theme/_corner-circle-button.scss`, `frontend/src/app/theme/_breakpoints.scss`
- Modify: `frontend/src/app/reader/list/entry-list/entry-list.component.html`, `.scss`, `.ts`, `.spec.ts`
- Modify: `frontend/src/app/reader/article/article-layer/article-layer.component.html`, `.scss`, `.ts`, `.spec.ts`
- Modify: `frontend/src/app/reader/shell/reader-shell.component.html`, `.spec.ts`
- Modify: `docs/design-language.md`

**Interfaces:**
- Consumes: Task 4's `HandednessService.hand()`.
- Produces: SCSS `@mixin corner-cluster($layer, $actions-flow: column)` in `_corner-circle-button.scss`; `$container-list-corner-cluster: 18rem` in `_breakpoints.scss`; a `protected readonly leftHanded = computed(() => this.handedness.hand() === 'left')` on both components; wrapper `<div class="corner-cluster" [class.left-handed]="leftHanded()">`.

- [ ] **Step 1: Write the failing specs.** `entry-list.component.spec.ts`: `.corner-cluster` holds `.corner-actions` before `.end-corner` (DOM order — the column reads top to bottom); `.corner-cluster` has `left-handed` exactly when `HandednessService.hand()` is `'left'` (set it through the service's `set`, with `HANDEDNESS_WRITER` the root default). `article-layer.component.spec.ts`: `.corner-cluster` holds `.corner-actions` and `.end-corner`, `.dock` is outside it; the same `left-handed` case.
- [ ] **Step 2: Run** `docker compose exec -T frontend npx jest src/app/reader/list/entry-list src/app/reader/article/article-layer` — FAIL.
- [ ] **Step 3: Tone.** In `corner-circle-button`, `65%` → `40%`, and rewrite its comment's numbers to match (`40% keeps the tone recognisable while the rows show through`, one or two lines).
- [ ] **Step 4: Mixin.** `corner-cluster($layer, $actions-flow: column)` — applied to the wrapper; it owns the anchors the components set today, so remove those (`circle.corner-anchor` on `.corner-actions` / `.end-corner` in both `.scss`, and the list's `.end-corner` pointer-events block):
  ```scss
  @mixin corner-cluster($layer, $actions-flow: column) {
    display: contents;
    pointer-events: none;

    .corner-actions,
    .end-corner { pointer-events: auto; }
    .corner-actions { @include corner-anchor(left, $layer); }
    .end-corner { @include corner-anchor(right, $layer); }

    @media (pointer: coarse) {
      @include corner-anchor(right, $layer);
      flex-direction: column;
      align-items: flex-end;
      gap: var(--space-5);

      .corner-actions,
      .end-corner { position: static; }
      .corner-actions { flex-direction: $actions-flow; }

      &.left-handed {
        right: auto;
        left: var(--space-5);
        align-items: flex-start;

        .end-corner { flex-direction: row-reverse; }
      }
    }
  }
  ```
  Header comment, one line: what the cluster is on each pointer (#2203). The groups, not the buttons, take `pointer-events: auto`: the sidebar disc (shell template) and share / save / favourite (article page templates) are projected, so the component stylesheets cannot select them under emulated encapsulation; they inherit the groups' value instead — the reason the article sets it on its groups today.
- [ ] **Step 5: Sidebar disc only when shown.** On touch an `.end-corner` with no visible disc would still hold the column 24px off the corner (Review Focus 1). The list renders `.end-corner` only when `toTopShown || cornerTrailing()`, but the shell always passes its template and hides the button inside it. In `reader-shell.component.html` pass `[cornerTrailing]="sidebarOutOfView() ? sidebarCorner : null"` and drop the `@if (sidebarOutOfView())` inside `#sidebarCorner`. Spec in `reader-shell.component.spec.ts`: with the sidebar in view the list's `cornerTrailing` input is `null`; out of view it is the template.
- [ ] **Step 6: List.** Wrap the `.corner-actions` block and the `.end-corner` block (in that order — the column now comes first in the DOM) in the cluster div; inject `HandednessService`. In `.scss`: `.corner-cluster { @include circle.corner-cluster(4); }`; keep the existing `13rem` container query and extend its selector list with `.corner-cluster`; add beside it `@container entry-list (height < #{bp.$container-list-corner-cluster}) { @media (pointer: coarse) { .corner-cluster { display: none; } } }` with `$container-list-corner-cluster: 18rem; // header clearance (~120px) plus the touch stack (~164px)` in `_breakpoints.scss`. `$corner-clear` stays: it reserves 216px, the touch stack needs 164px.
- [ ] **Step 7: Article.** In `article-layer.component.html` wrap `.corner-actions` and the `@if (!leaving())` `.end-corner` block in the cluster div; `.dock` stays a sibling outside it. In `.scss`: `.corner-cluster { @include circle.corner-cluster(20, column-reverse); }` (the DOM order is share, save, favourite; the column reads favourite, save, share); drop `.corner-actions` and `.end-corner` from the `pointer-events: auto` list, keep `.dock` there.
- [ ] **Step 8: Run** the specs (add `src/app/reader/shell` to the jest paths) — PASS; `docker compose exec -T frontend npm run check` and `npm run build` (template errors slip past `check`) — green.
- [ ] **Step 9: Docs.** `docs/design-language.md`: the `--corner-button-size` row says the discs are the corner cluster's; add a short "Corner cluster (#2203)" subsection near the floating-chrome rules: fine pointer = actions bottom-start, pair bottom-end; coarse pointer = one stack in the handedness corner, column over the pair's outer disc, `--space-2` inside a group and `--space-5` between them, list column mark all / mark above, article column favourite / save / share; discs at a 40% tone; the cluster hides below `18rem` of list height on touch.
- [ ] **Step 10: Commit** `feat(#2203): stack the corner discs within thumb reach`.

### Task 7: Live check and branch gates

No code unless a check fails; a failure goes back to the task that owns it.

- [ ] **Step 1:** Confirm the dev stack serves this branch (Task 1 migration applied, `:4200` not serving a stale chunk). In the built-in browser at `http://localhost:4200`, `resize_window` preset `mobile` (touch emulation → `pointer: coarse`), reload, sign in to the dev account.
- [ ] **Step 2: List, right-handed.** Scroll down until back to top and mark above show. Screenshot. Expect: sidebar disc on the right edge, back to top left of it, mark all read over mark above directly over the sidebar disc; 8px inside groups, 24px between; all discs at 40%. Measure gaps with `getBoundingClientRect()` via `javascript_tool`.
- [ ] **Step 3: Review Focus 1.** `resize_window` to a coarse-pointer tablet (`preset: tablet`, landscape `1024×768` if the sidebar needs it), sidebar visible, list at the top so no pair disc shows: the column's lowest disc sits on the corner, its `bottom` equal to where the pair's discs sit. Then tap each disc in Steps 2, 4 and 5 once (projected discs must take taps).
- [ ] **Step 4: Article, right-handed.** Open an entry, scroll. Expect back on the right edge, back to top left of it, favourite / save / share top to bottom over back. Screenshot.
- [ ] **Step 5: Left-handed.** Settings → Preferences → Left-handed; reload; repeat Steps 2 and 4: the whole cluster mirrored to the bottom-left. Screenshots.
- [ ] **Step 6: Review Focus 2–4.** Trigger a toast (e.g. mark all read with undo) — the whole cluster rises. Open the playlist so the list shrinks below 18rem — the cluster hides. `resize_window` preset `desktop`, reload — left-handed has no effect: actions bottom-left, pair bottom-right, at 40%.
- [ ] **Step 7:** Set the dev account back to right-handed; reset the viewport to `desktop`. Send the screenshots to the user.
- [ ] **Step 8: Branch gates.** Backend: `composer check`, `composer md`, `php bin/phpunit`, `docker compose exec php composer test:parallel`, `composer infection:diff`. Frontend: `docker compose exec -T frontend npm test`, `npm run check`, `npm run build`. Scan today's dev log (`ls -t backend/var/log/dev-*.log | head -1 | xargs tail -n 200 | jq .`) for deprecations or errors. All green before the PR.
