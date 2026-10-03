# Profile Debug Log Inside the Profile Group Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On Settings → AI the profile's debug log lives inside the Profile group's collapsed "Profile and settings" drill-in, below the caps and save bar, instead of in its own group (#1357).

**Architecture:** The recommendation side already shows its debug log as an `<app-disclosure appearance="drill-in">` labelled "Debug log" (design-language §8.3). The profile reuses that shape: a nested drill-in labelled with the existing `settings.ai.recommendations.debugPanelTitle`, rendered only while `state.debugEnabled`, as the last child of the outer disclosure. `<details>` nests natively; no `cdkDropList` is involved. The standalone `app-settings-group` and its `settings.profile.debugTitle` key are deleted.

**Tech Stack:** Angular 20 standalone components, Transloco, Jest (jsdom).

**Spec:** GitHub issue #1357.

## Global Constraints

- Debug log is shown only when debug mode is on; no separate profile debug group remains.
- Jest runs in Docker, one process: `docker compose exec -T frontend npm test -- <pattern>`; finish with `docker compose exec -T frontend npm run check`.

---

### Task 1: Move the debug log into the Profile disclosure

**Files:**
- Modify: `frontend/src/app/settings/profile/profile-section.component.html`
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (remove `settings.profile.debugTitle`)
- Test: `frontend/src/app/settings/profile/profile-section.component.spec.ts`

**Interfaces:**
- Consumes: `DisclosureComponent` (already imported), `app-profile-debug-log`.
- Produces: nothing new.

- [ ] **Step 1: Write the failing tests**

```ts
  it('renders the debug log inside the profile disclosure, after the save bar', () => {
    const fixture = mount(profileState({ debugEnabled: true }));
    http.expectOne(`${ENDPOINT}/runs/current/log`).flush({ entries: [] });

    const outer = element(fixture).querySelector('app-settings-group details') as HTMLDetailsElement;
    const log = element(fixture).querySelector('app-profile-debug-log')!;

    expect(log.closest('details')?.parentElement?.closest('details')).toBe(outer);
    const saveBar = outer.querySelector('app-settings-save-bar')!;
    expect(saveBar.compareDocumentPosition(log) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
  });

  it('renders no debug log when debug mode is off', () => {
    expect(element(mount()).querySelector('app-profile-debug-log')).toBeNull();
  });

  it('has no standalone debug group', () => {
    const fixture = mount(profileState({ debugEnabled: true }));
    http.expectOne(`${ENDPOINT}/runs/current/log`).flush({ entries: [] });

    expect(element(fixture).querySelectorAll('app-settings-group')).toHaveLength(1);
  });
```

- [ ] **Step 2: Run, expect FAIL**

`docker compose exec -T frontend npm test -- profile-section`

- [ ] **Step 3: Implement**

Replace the trailing `@if (state.debugEnabled)` group with, just before `</app-disclosure>`:

```html
      @if (state.debugEnabled) {
        <app-disclosure
          appearance="drill-in"
          [label]="'settings.ai.recommendations.debugPanelTitle' | transloco"
        >
          <app-profile-debug-log [running]="svc.polling()" [runId]="svc.profileRun()?.id ?? null" />
        </app-disclosure>
      }
```

Remove `settings.profile.debugTitle` from en.json and de.json.

- [ ] **Step 4: Run tests and `npm run check`, expect PASS; commit `feat(#1357): …`.**
