# Profile Group Starts Collapsed Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On Settings → AI the Profile group shows only its header and caption until the user expands it (#1355).

**Architecture:** `app-settings-group` has no collapsible body, and the design language names `<app-disclosure appearance="drill-in">` as the one way to expand a section in place inside a group (design-language §8.3). So the whole group body — profile text, generated line, status, banners, Generate now, schedule, connection, caps and save bar — moves inside one drill-in disclosure, closed by default. The group header (icon, title, caption) stays outside it. `<details>` keeps its body in the DOM, so a typed cap draft and the status polling are untouched by collapsing; the toggle is the native `<summary>`, keyboard-operable and exposed with its expanded state, no ARIA reimplementation. The open state is not remembered: every visit starts collapsed. The debug-log group is unchanged.

**Tech Stack:** Angular 20 standalone components, Transloco, Jest (jsdom).

**Spec:** GitHub issue #1355.

## Global Constraints

- The caption "What the model has learned from your reading. For You recommendations are scored against it." stays visible while collapsed.
- Sibling `.scss` only, no hex or `px` literals; every new string in `public/i18n/en.json` and `de.json`.
- Jest runs in Docker, one process: `docker compose exec -T frontend npm test -- <pattern>`; finish with `docker compose exec -T frontend npm run check`.

---

### Task 1: Collapse the Profile group body behind a drill-in disclosure

**Files:**
- Modify: `frontend/src/app/settings/profile/profile-section.component.html`
- Modify: `frontend/src/app/settings/profile/profile-section.component.ts` (import `DisclosureComponent`)
- Modify: `frontend/public/i18n/en.json`, `frontend/public/i18n/de.json` (`settings.profile.details`)
- Test: `frontend/src/app/settings/profile/profile-section.component.spec.ts`

**Interfaces:**
- Consumes: `DisclosureComponent` (`shared/disclosure`), inputs `label`, `appearance`.
- Produces: nothing new.

- [ ] **Step 1: Write the failing tests**

```ts
  const disclosure = (fixture: ComponentFixture<ProfileSectionComponent>): HTMLDetailsElement =>
    element(fixture).querySelector('app-settings-group details') as HTMLDetailsElement;

  it('hides the profile and its settings until the group is expanded', () => {
    const fixture = mount();
    const body = [
      'profile-text', 'profile-meta', 'generate-now',
      'profile-schedule', 'profile-connection', 'profile-kept-cap', 'profile-viewed-cap',
    ];

    expect(disclosure(fixture).open).toBe(false);
    for (const id of body) expect(byTestId(fixture, id)?.closest('details')).toBe(disclosure(fixture));
    expect(element(fixture).querySelector('app-settings-save-bar')?.closest('details')).toBe(
      disclosure(fixture),
    );

    disclosure(fixture).querySelector('summary')!.click();

    expect(disclosure(fixture).open).toBe(true);
  });

  it('keeps the title and caption visible while the group is collapsed', () => {
    const group = element(mount()).querySelector('app-settings-group')!;

    expect(group.querySelector('.g-title')?.closest('details')).toBeNull();
    expect(group.querySelector('.g-caption')?.textContent).toContain(
      'What the model has learned from your reading.',
    );
    expect(group.querySelector('.g-caption')?.closest('details')).toBeNull();
  });
```

- [ ] **Step 2: Run to verify the first fails** — `docker compose exec -T frontend npm test -- profile-section` → FAIL (no `details` in the group).

- [ ] **Step 3: Implement** — in the template wrap everything inside `<app-settings-group>` in

```html
    <app-disclosure appearance="drill-in" [label]="'settings.profile.details' | transloco">
      …the existing .profile block, both rows, .caps, the failure banner and the save bar…
    </app-disclosure>
```

add `DisclosureComponent` to `imports`, and add the keys `"details": "Profile and settings"` (en) and `"details": "Profil und Einstellungen"` (de) under `settings.profile`. The `.profile` block gains a top divider so the drill-in row and the profile read as two rows.

- [ ] **Step 4: Run to verify both pass**, deletion-check each test (move the content out of the disclosure / move the caption into it, quote the FAIL, restore from a copy aside), then `docker compose exec -T frontend npm run check`.

- [ ] **Step 5: Commit** — `feat(#1355): start the Profile group collapsed behind a drill-in`.

- [ ] **Step 6: Real render** — `/settings/ai` on :4200, screenshot collapsed and expanded; look only, never run or save.
