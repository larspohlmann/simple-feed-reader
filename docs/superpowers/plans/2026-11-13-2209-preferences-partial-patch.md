# One partial-update PATCH for the simple account settings Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `PATCH /api/me/preferences` updates any subset of the four simple reading settings; the per-setting routes for the magazine style, Trending and handedness go away, and the frontend sends all four through one writer.

**Architecture:** `UpdatePreferencesRequest` gets four optional typed fields, rejects an empty body (`#[Assert\IsTrue]` on a `has…()` method) and unknown keys (`ALLOW_EXTRA_ATTRIBUTES => false`). It hands the controller an `App\Entity\ReadingSettingsChange` (nullable fields, absent = keep), which `ReadingSettings::apply()` takes — the `InclusionChange` pattern from #2202. On the frontend one `PREFERENCES_WRITER.write(change)` replaces three writer tokens.

**Tech Stack:** Symfony 8.1 / PHP 8.5, Angular 20 signals, PHPUnit, Jest.

**Spec:** GitHub issue [#2209](https://github.com/larspohlmann/simple-feed-reader/issues/2209).

**Start:** after PR #2208 (#2203) merges. Rebase `feature/2209-preferences-partial-patch` onto the merged `develop` first; this plan's commit is its only own commit until then.

## Global Constraints

- The four settings: `scrapeFallbackEnabled` (bool), `magazineStyle` (`MagazineStyle`), `handedness` (`Handedness`), `trendingEnabled` (bool). Nothing else moves: sharing, digest, feed-directory scope and locale keep their routes.
- An absent field keeps its stored value. `{}` → 422. An unknown key → 422. A wrong type or enum value → 422. Signed out → 401. All status assertions as `Response::HTTP_*`.
- Response: the profile, `MeProfileJson::of($user)`, unchanged in shape.
- Removed outright, no aliases: `PATCH /api/me/magazine-style`, `/api/me/trending`, `/api/me/handedness`, their DTOs and actions, `AccountPreferencesWriter::changeScrapeFallback/changeMagazineStyle/changeHandedness/changeTrending`.
- Frontend behaviour of `PreferencesService`, `MagazineStyleService`, `HandednessService` (local-first, paint cache, `saveFailed`, `adopt`, `reset`) is unchanged; only the transport changes.
- Commits `type(#2209): …`; CLAUDE.md Clean Code rules; every touched `backend/src` file PHPMD-clean.

## Review Focus

1. **`null` in the body** (`{"handedness":null}`): a reasonable client expects either "keep" or a 422, never a stored null or a 500. The nullable field makes it "absent"; with no other field the body is empty → 422. Task 1 tests it.
2. **Two settings at once** (`{"magazineStyle":"airy","trendingEnabled":false}`): both apply in one write. Task 1 tests it.
3. **A boolean sent as a string** (`"trendingEnabled":"false"`): must be 422, not coerced to `true`. Task 1 tests it.
4. **A frontend write racing another** (toggle Trending, then pick a style before the first PATCH returns): each one-key body touches only its own field, so neither overwrites the other. Task 3's spec asserts each service sends exactly one key.
5. **Old route still called by a cached old SPA bundle** after deploy: it 404s, the service shows its `saveFailed` banner, the value stays local until reload. Accepted (no aliases, per the issue); Task 2 pins the 404.

---

### Task 1: Partial update on `/api/me/preferences`

**Files:**
- Create: `backend/src/Entity/ReadingSettingsChange.php`, `backend/src/Http/PartialPatchPayload.php`
- Modify: `backend/src/Entity/ReadingSettings.php`, `backend/src/Dto/Me/UpdatePreferencesRequest.php`, `backend/src/Controller/Api/ReaderPreferencesController.php` (`updatePreferences` only), `backend/src/Service/Account/AccountPreferencesWriter.php`
- Test: `backend/tests/Entity/ReadingSettingsTest.php`, `backend/tests/Controller/Api/ReaderPreferencesControllerTest.php`, `backend/tests/Service/Account/AccountPreferencesWriterTest.php`

**Interfaces:**
- Produces: `final readonly class App\Entity\ReadingSettingsChange(?bool $scrapeFallbackEnabled = null, ?MagazineStyle $magazineStyle = null, ?Handedness $handedness = null, ?bool $trendingEnabled = null)`; `ReadingSettings::apply(ReadingSettingsChange $change): void`; `AccountPreferencesWriter::changeReadingSettings(User $user, ReadingSettingsChange $change): void`; `UpdatePreferencesRequest::toChange(): ReadingSettingsChange`; `App\Http\PartialPatchPayload::CONTEXT = [AbstractNormalizer::ALLOW_EXTRA_ATTRIBUTES => false]`.

- [ ] **Step 1: Write the failing tests.**
  `ReadingSettingsTest`: `testApplyChangesOnlyTheGivenSettings` (set Airy + Left via `apply(new ReadingSettingsChange(magazineStyle: Airy, handedness: Left))`, assert scraping still false and Trending still true), `testApplyWithNothingGivenKeepsEverything`.
  `ReaderPreferencesControllerTest` (PATCH `/api/me/preferences`, read back through `GET /api/me`'s `preferences`): `testEachSettingChangesAlone` (data provider over the four keys with a non-default value; the other three stay at their defaults), `testTwoSettingsChangeInOneWrite`, `testAnEmptyBodyIsRejected` (`{}` → 422), `testANullOnlyBodyIsRejected` (`{"handedness":null}` → 422), `testAnUnknownKeyIsRejected` (`{"handedness":"left","fontSize":3}` → 422, handedness unchanged), `testAnUnknownValueIsRejected` (`{"magazineStyle":"cards"}` → 422), `testABooleanAsAStringIsRejected` (`{"trendingEnabled":"false"}` → 422), `testASignedOutClientIsRejected` (401). Keep the file's existing scrape-fallback cases that still hold; delete what they duplicate.
  `AccountPreferencesWriterTest`: replace the four `testChange…Persists` tests with `testChangeReadingSettingsPersistsTheGivenOnes`.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'ReadingSettingsTest|ReaderPreferencesControllerTest|AccountPreferencesWriterTest'` — FAIL (class `ReadingSettingsChange` not found; empty body accepted).
- [ ] **Step 3: Implement.** `apply()` assigns `$change->x ?? $this->x` per field (no branching). `UpdatePreferencesRequest`: four nullable fields defaulting to `null`, `#[Assert\IsTrue(message: 'Name at least one setting to change.')] public function namesASetting(): bool`, `toChange()`; drop its old docblock (the reason it gave is gone). Controller: `#[MapRequestPayload(serializationContext: PartialPatchPayload::CONTEXT)]`, `$this->preferences->changeReadingSettings($user, $request->toChange())`; rewrite the action's docblock to one line saying why sharing/digest/scope/locale keep their own routes (their own validation). `PartialPatchPayload` mirrors `FullReplacePayload`, its one-line docblock saying an unknown key is refused instead of ignored. `changeReadingSettings` = `forUser($user)->apply($change)` + flush.
- [ ] **Step 4: Run** the same filter — PASS.
- [ ] **Step 5: Commit** `feat(#2209): one partial-update PATCH for the reading settings`.

### Task 2: Retire the per-setting routes

**Files:**
- Delete: `backend/src/Dto/Me/UpdateMagazineStyleRequest.php`, `UpdateTrendingRequest.php`, `UpdateHandednessRequest.php`, `backend/tests/Controller/Api/MagazineStyleControllerTest.php`, `HandednessControllerTest.php`
- Modify: `ReaderPreferencesController.php` (drop three actions), `AccountPreferencesWriter.php` (drop four methods), `backend/tests/Controller/Api/MeRoutesTest.php`, `MeControllerTest.php`, `ReaderPreferencesControllerTest.php`, `docs/architecture.md` if it names a removed route

**Interfaces:**
- Consumes: Task 1's route.

- [ ] **Step 1: Write the failing test.** `ReaderPreferencesControllerTest::testTheRetiredSettingRoutesAreGone` — data provider `/api/me/magazine-style`, `/api/me/trending`, `/api/me/handedness`; signed in, PATCH each with a valid body → 404. Port the useful cases from the two deleted test files into Task 1's tests first (anything not already covered there), then delete them. `MeControllerTest::testThePreferencesObjectKeepsItsShapeForEveryStoredSetting`: set scraping, style, handedness and Trending in one `PATCH /api/me/preferences`.
- [ ] **Step 2: Run** `php bin/phpunit --filter 'ReaderPreferencesControllerTest|MeControllerTest|MeRoutesTest'` — FAIL (the retired routes answer 200).
- [ ] **Step 3: Implement** the deletions; remove the three rows from `MeRoutesTest`. `grep -rn "magazine-style\|api/me/trending\|api/me/handedness" backend docs --include='*.php' --include='*.md' | grep -v superpowers` returns nothing.
- [ ] **Step 4: Run** backend gates from `backend/`: `composer check`, `composer md`, `php bin/phpunit`, and in parallel `docker compose exec php composer test:parallel`, then `composer infection:diff`. All green.
- [ ] **Step 5: Commit** `refactor(#2209): retire the per-setting PATCH routes`.

### Task 3: One frontend writer

**Files:**
- Modify: `frontend/src/app/core/preferences/preferences-writer.ts`, `http-preferences-writer.ts`, `preferences.service.ts`, `magazine-style.service.ts`, `handedness.service.ts`, their `.spec.ts`; `frontend/src/app/app.config.ts`; the specs that provide `MAGAZINE_STYLE_WRITER` / `HANDEDNESS_WRITER` or spy on `writeTrending` (`entry-list.component.spec.ts`, `view-controls.component.spec.ts`, `preferences-section.component.spec.ts`)
- Delete: `magazine-style-writer.ts`, `http-magazine-style-writer.ts`, `handedness-writer.ts`, `http-handedness-writer.ts`

**Interfaces:**
- Consumes: Task 1's route and body.
- Produces: in `preferences-writer.ts`, `interface ReadingPreferences { scrapeFallbackEnabled: boolean; magazineStyle: MagazineStyle; handedness: Handedness; trendingEnabled: boolean }` and `PreferencesWriter.write(change: Partial<ReadingPreferences>): Observable<boolean>` (resolves `false` on failure, never errors; the root default resolves `true`). `writeTrending` is gone.

- [ ] **Step 1: Write the failing specs.** In each service spec, the writer double records the change it gets: `PreferencesService.setScrapeFallbackEnabled(true)` sends exactly `{ scrapeFallbackEnabled: true }`, `setTrendingEnabled(false)` exactly `{ trendingEnabled: false }`, `MagazineStyleService.set('airy')` exactly `{ magazineStyle: 'airy' }`, `HandednessService.set('left')` exactly `{ handedness: 'left' }` (`toEqual` on the whole object — Review Focus 4). The existing failure/`saveFailed` cases switch to the same double. A `http-preferences-writer` spec (`HttpTestingController`): `write({ handedness: 'left' })` PATCHes `/api/me/preferences` with that body and resolves `true`; a 422 resolves `false`.
- [ ] **Step 2: Run** `docker compose exec -T frontend npx jest src/app/core/preferences` — FAIL.
- [ ] **Step 3: Implement.** The three services inject `PREFERENCES_WRITER`; delete the four writer files and their two providers in `app.config.ts`; move the three component specs to providing or spying on `PREFERENCES_WRITER`. `grep -rn "MAGAZINE_STYLE_WRITER\|HANDEDNESS_WRITER\|writeTrending" frontend/src frontend/e2e` returns nothing.
- [ ] **Step 4: Run** `docker compose exec -T frontend npm run check`, `npm run build`, `npm run typecheck:e2e` — green; `npx playwright test e2e/corner-cluster-mobile.spec.ts e2e/magazine-airy-style.spec.ts --trace off` — green.
- [ ] **Step 5: Live check** on `http://localhost:4200` (stack current: `cache:clear`, frontend restarted): in Settings → Preferences toggle scraping, Trending, the magazine style and the handedness; each persists across a reload; DevTools network shows each as `PATCH /api/me/preferences` with a one-key body. Scan today's dev log for errors.
- [ ] **Step 6: Commit** `refactor(#2209): one writer for the reading settings`.
