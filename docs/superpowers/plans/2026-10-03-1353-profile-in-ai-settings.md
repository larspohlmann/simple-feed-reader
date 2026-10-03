# Profile settings move into the AI settings page (#1353) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The Profile group (#1351) renders on Settings → AI between the connections group and the For You card; the top-level Profile entry, route and copy are gone; `/settings/profile` forwards to `/settings/ai`.

**Architecture:** Keep `ProfileSectionComponent` and render `<app-profile-section />` inside the AI page's `<app-settings-stack>`. The component drops its own `<app-settings-stack>` and becomes `display: contents`, so its groups are direct flex children of the AI stack and get the canonical `--space-7` gap (#454); a nested stack would add a second flex item and an extra gap while the profile state is still loading. Frontend only; the API is unchanged. Branch `feature/1353-profile-in-ai-settings` is checked out.

**Tech Stack:** Angular 20 (standalone, signals, OnPush), Jest in Docker, Transloco.

**Conventions:** Jest only in Docker, one process at a time: `docker compose exec -T frontend npm test -- <pattern>`. Commits `refactor(#1353): …` / `feat(#1353): …`, no attribution lines. No comments unless they clear the CLAUDE.md bar. Prettier 100 columns.

---

### Task 1: Render the profile inside the AI page

**Files:**
- Modify: `frontend/src/app/settings/profile/profile-section.component.html`, `profile-section.component.scss`, `profile-section.component.ts`, `profile-section.component.spec.ts`
- Modify: `frontend/src/app/settings/ai/ai-section.component.html`, `ai-section.component.ts`, `ai-section.component.spec.ts`

- [ ] **Step 1: Write the failing AI spec.** In `ai-section.component.spec.ts` add the import `import { profileRun, profileState } from '../../../testing/profile-settings';`. The profile only mounts once the active connection is ready, so `mount()` stays untouched. Add a `flushProfile` helper beside `CONFIG`, call it before every `http.expectOne('/api/me/ai/recommendations')` (the two inline sites and `flushReady`), so existing ready-state tests stay green under `http.verify()`:

```ts
  const flushProfile = (): void => {
    http.expectOne('/api/me/ai/profile').flush(profileState());
    http.expectOne('/api/me/ai/profile/runs/current').flush(profileRun());
  };
```

Add the test (inside `describe('AiSectionComponent'`):

```ts
  it('shows the profile group between the connections and the For You card', () => {
    const fixture = mount();
    ai.configs.set([config({ id: 1, active: true, ready: true })]);
    fixture.detectChanges();
    flushReady();
    fixture.detectChanges();
    const html = (fixture.nativeElement as HTMLElement).innerHTML;
    const profileAt = html.indexOf('data-testid="profile-text"');

    expect(profileAt).toBeGreaterThan(-1);
    expect(profileAt).toBeLessThan(html.indexOf('app-recommendation-settings-card'));
  });
```

Also in `profile-section.component.spec.ts` add:

```ts
  it('renders its groups without a stack of its own', () => {
    const fixture = mount();

    expect(element(fixture).querySelector('app-settings-stack')).toBeNull();
  });
```

- [ ] **Step 2: Run, expect FAIL.** `docker compose exec -T frontend npm test -- ai-section.component profile-section.component` — the order test fails (no profile in the AI page; `flushProfile` finds no request) and the stack test fails.

- [ ] **Step 3: Implement.**
  - `profile-section.component.html`: remove the `<app-settings-stack>` / `</app-settings-stack>` wrapper (keep `@if (svc.state(); as state) { … }`, de-indent the two groups one level; Prettier reflows).
  - `profile-section.component.ts`: remove `SettingsStackComponent` from the import line and from `imports`.
  - `profile-section.component.scss`: add at the top
    ```scss
    :host {
      display: contents;
    }
    ```
  - `ai-section.component.ts`: `import { ProfileSectionComponent } from '../profile/profile-section.component';` and add `ProfileSectionComponent` to `imports` (alphabetical, before `RecommendationDebugLogComponent`).
  - `ai-section.component.html`: insert as the first child of the existing `@if (activeReady())` block, directly before `<app-recommendation-settings-card />` (the profile, like the recommendation settings, only applies once a connection is ready; with a Jev connection active it shows its own "choose a connection" state):
    ```html
      <app-profile-section />
    ```

- [ ] **Step 4: Run, expect PASS.** `docker compose exec -T frontend npm test -- ai-section.component profile-section.component`.

- [ ] **Step 5: Commit.** `git add frontend/src && git commit -m "feat(#1353): show the profile group on the AI settings page"`

### Task 2: Remove the Profile section, forward the old path

**Files:**
- Modify: `frontend/src/app/settings/settings-sections.ts`, `settings.routes.ts`, `settings.routes.spec.ts`
- Check: `settings-sections.spec.ts`, `settings-nav.component.spec.ts`, `settings-hub.component.spec.ts` (grep found no `profile` in them; run them to confirm)

- [ ] **Step 1: Write the failing test.** In `settings.routes.spec.ts`, beside the existing `/tags` → `/organise` test (line ~36), add a test that follows the same pattern:

```ts
  it('forwards the retired /profile path to the AI page', async () => {
    await router.navigateByUrl('/profile');

    expect(router.url).toBe('/ai');
  });
```

(Setup is `TestBed.configureTestingModule({ providers: [provideRouter(SETTINGS_ROUTES)] })` and `TestBed.inject(Router)`, as in the Tags test.) Also in `settings-sections.spec.ts` add:

```ts
  it('lists no Profile section; the profile lives on the AI page (#1353)', () => {
    expect(SETTINGS_SECTIONS.map((section) => section.path)).not.toContain('profile');
  });
```

- [ ] **Step 2: Run, expect FAIL.** `docker compose exec -T frontend npm test -- settings.routes settings-sections` — `/profile` still renders the old page (`router.url` is `/profile`), the sections test fails.

- [ ] **Step 3: Implement.**
  - `settings-sections.ts`: delete the line `{ path: 'profile', icon: 'psychology', labelKey: 'settings.profile.title', group: 'general' },`.
  - `settings.routes.ts`: replace the `profile` route block with
    ```ts
      { path: 'profile', redirectTo: 'ai', pathMatch: 'full' },
    ```
    (no comment: the name and target say it).
  - `settings.profile.title` is still used by the group header; leave it.

- [ ] **Step 4: Run, expect PASS.** `docker compose exec -T frontend npm test -- settings` (nav, hub, shell, sections, routes specs).

- [ ] **Step 5: Commit.** `git add frontend/src && git commit -m "refactor(#1353): drop the top-level Profile section and forward its path to AI"`

### Task 3: Point the copy at Settings → AI

**Files:**
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (lines 152, 208, 247 in both)
- Modify: `frontend/src/app/settings/ai/ai-section.component.spec.ts` (lines 368, 393, 1045)
- Modify: `frontend/src/app/core/ai-availability.service.ts:17` (doc comment), `docs/for-you-scheduling.md:8`, `docs/recommendations-runs.md:126`

- [ ] **Step 1: Update the spec expectations first (failing).** In `ai-section.component.spec.ts` replace `takes its profile from Settings → Profile` with `takes its profile from Settings → AI` (two places) and `Open Settings → Profile` with `Open Settings → AI` (line 1045).

- [ ] **Step 2: Run, expect FAIL.** `docker compose exec -T frontend npm test -- ai-section.component`.

- [ ] **Step 3: Change the copy.**
  - en `borrowedProfile`: `takes its profile from Settings → AI`; `modelPicker`: `… take your reading profile from Settings → AI.`; `jevStep4`: `Open Settings → AI and choose your LLM connection under Profile.` (the AI nav label in en is `AI`)
  - de `borrowedProfile`: `übernimmt das Profil aus Einstellungen → KI`; `modelPicker`: `… übernehmen dein Leseprofil aus Einstellungen → KI.`; `jevStep4`: `Öffne Einstellungen → KI und wähle unter Profil deine LLM-Verbindung.`
  - Use the exact label the AI nav entry shows: check `settings.ai.title` in each file first and use that word (`settings.ai.title` is `KI` in de.json — confirmed).
  - Update the `ai-section.component.spec.ts` strings to match the exact de/en text above if they assert the Jev step wording in full.
  - Replace `Settings → Profile` with `Settings → AI` in the `ai-availability.service.ts` comment and both docs files.

- [ ] **Step 4: Gate.** `docker compose exec -T frontend npm test -- ai-section.component`, then `docker compose exec -T frontend npm run check` (ESLint, Prettier, Stylelint, full Jest). Fix anything it reports.

- [ ] **Step 5: Commit.** `git add frontend docs && git commit -m "refactor(#1353): point the profile copy at Settings → AI"`

---

**Resolved:** the profile group sits inside `@if (activeReady())`, before the recommendation card. The ordering test must mount the AI page with a ready active connection.
