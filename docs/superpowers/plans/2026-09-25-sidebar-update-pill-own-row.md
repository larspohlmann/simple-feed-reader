# Sidebar Update Pill On Its Own Row Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop the "Update vX.Y.Z" pill from crushing the sidebar foot's version/Feedback row (#1149).

**Architecture:** The pill leaves the `.meta` flex row and becomes its own full-width line in the sidebar foot, directly above `.meta` — the same slot pattern the trial line uses. `.meta` goes back to a fixed two-item row (version left, Feedback right) and `.version-group` is deleted. Both remaining meta links get `white-space: nowrap` so no future string length can break them mid-word.

**Tech Stack:** Angular 20 standalone component, SCSS, Jest (jsdom), manual layout check in the browser.

**Spec:** GitHub issue #1149 (root cause and agreed fix, option 2).

## Global Constraints

- Branch: `fix/1149-update-pill-own-row` off `develop`. PR into `develop`, body says `Closes #1149`.
- Commit format: `type(#1149): lower-case summary`.
- No hex colours, no ad-hoc `px` spacing in `.scss` — use the `--space-*`, `--fs-*`, `--radius-*` tokens (`npm run check` enforces it).
- Comments: default to none; at most one line. Delete the `.version-group` comment together with the rule.
- Frontend tests run inside the Docker frontend container: `docker compose exec -T frontend npm test`.
- Behaviour kept: the badge still shows whenever `availableUpdate()` is set, including while organising (it is outside the `@if (!organising())` block today and stays outside).

---

### Task 1: Move the update pill into its own row

**Files:**
- Modify: `frontend/src/app/reader/sidebar/sidebar-foot.component.html` (the `.meta` block, lines 25–49)
- Modify: `frontend/src/app/reader/sidebar/sidebar-foot.component.scss` (`.meta` … `.update-badge:hover`, lines 107–150)
- Test: `frontend/src/app/reader/sidebar/sidebar-foot.component.spec.ts`

**Interfaces:**
- Consumes: `SidebarFootComponent.availableUpdate()` (unchanged, `LatestRelease | null`).
- Produces: DOM contract — `.update-badge` is a direct child of the `app-sidebar-foot` host, placed before `.meta`; `.meta` contains exactly the `.version` and `.feedback` links.

- [ ] **Step 1: Write the failing test**

Add to `sidebar-foot.component.spec.ts`, after the existing "shows an update badge…" test:

```ts
  it('keeps the update badge out of the version and feedback row', () => {
    const f = mount();
    const versions = TestBed.inject(VersionService);
    versions.latest.set({ version: 'v9.9.9', notesUrl: 'https://github.test/releases/tag/v9.9.9' });
    versions.updateAvailable.set(true);
    f.detectChanges();

    const host = f.nativeElement as HTMLElement;
    const badge = host.querySelector('.update-badge');
    const meta = host.querySelector('.meta');
    expect(badge?.parentElement).toBe(host);
    expect(badge?.nextElementSibling).toBe(meta);
    expect(Array.from(meta?.children ?? []).map((link) => link.className)).toEqual([
      'version',
      'feedback',
    ]);
  });
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `docker compose exec -T frontend npx jest src/app/reader/sidebar/sidebar-foot.component.spec.ts`
Expected: FAIL — the badge's parent is `.version-group`, not the host.

- [ ] **Step 3: Move the badge in the template**

Replace the `.meta` block in `sidebar-foot.component.html` with:

```html
@if (availableUpdate(); as update) {
  <a
    class="update-badge"
    [href]="update.notesUrl"
    target="_blank"
    rel="noopener noreferrer"
    [attr.aria-label]="'reader.updateAvailableLabel' | transloco: { version: update.version }"
    >{{ 'reader.updateAvailable' | transloco: { version: update.version } }}</a
  >
}
<div class="meta">
  <a
    class="version"
    routerLink="/settings"
    [attr.aria-label]="'reader.versionLabel' | transloco: { version }"
    >{{ version }}</a
  >
  <a
    class="feedback"
    href="https://github.com/larspohlmann/simple-feed-reader/issues"
    target="_blank"
    rel="noopener noreferrer"
    >Feedback</a
  >
</div>
```

- [ ] **Step 4: Restyle**

In `sidebar-foot.component.scss`:

1. Delete the `.version-group` rule and the comment above it.
2. Add `white-space: nowrap;` to the shared `.version, .feedback` rule.
3. Make the badge a left-aligned line of its own that sits under the controls like the trial line:

```scss
.update-badge {
  align-self: flex-start;
  margin: var(--space-2) var(--space-2) 0;
  padding: var(--space-1) var(--space-2);
  border-radius: var(--radius-pill);
  background: var(--accent-soft);
  color: var(--accent);
  font-size: var(--fs-xs);
  font-weight: 600;
  text-decoration: none;
  white-space: nowrap;
}
```

(`align-self: flex-start` stops the host's column flex from stretching the pill to full width; `.update-badge:hover` stays as is.)

- [ ] **Step 5: Run the spec to verify it passes**

Run: `docker compose exec -T frontend npx jest src/app/reader/sidebar/sidebar-foot.component.spec.ts`
Expected: PASS, all tests in the file (the two existing badge tests query `.update-badge` from the host and are unaffected).

- [ ] **Step 6: Verify the real render**

jsdom has no layout, so check the fix in the browser at `http://localhost:4200` (logged in, desktop viewport ≈1024×768, then Mobile preset). In the page's JS console:

```js
const el = document.querySelector('app-sidebar-foot');
const versions = ng.getComponent(el).versions;
versions.latest.set({ version: 'v1.42.0-dev.12', notesUrl: 'https://example.com' });
versions.updateAvailable.set(true);
ng.applyChanges(el);
document.querySelector('.version').textContent = 'v1.41.0-dev.11';
el.scrollIntoView({ block: 'end' });
```

Expected: the pill sits on its own line under the view controls; `.version` and `.feedback` share one line (equal `getBoundingClientRect().top`, each one line tall); nothing overflows the sidebar (`el.parentElement.scrollWidth === el.parentElement.clientWidth`). Take a screenshot of the foot. Before the fix the same script wrapped the version into four lines and split "Feedback".

Then repeat with the sidebar in Organise mode on a coarse pointer (Mobile preset): the pill still shows and the meta row is still one line. Reload to discard the injected state.

- [ ] **Step 7: Run the frontend gate**

Run: `docker compose exec -T frontend npm run check`
Expected: ESLint, Prettier, Stylelint and Jest all pass.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/app/reader/sidebar/sidebar-foot.component.html frontend/src/app/reader/sidebar/sidebar-foot.component.scss frontend/src/app/reader/sidebar/sidebar-foot.component.spec.ts docs/superpowers/plans/2026-09-25-sidebar-update-pill-own-row.md
git commit -m "fix(#1149): give the update pill its own row in the sidebar foot"
```

Commit body: the root cause in two lines (a non-wrapping pill in a fixed-width flex row forced the version and Feedback links to break at `.`/`-` and mid-word; the pill now has its own line, the same slot pattern as the trial line).
