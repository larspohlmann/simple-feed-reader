# Caught-up list hides the to-top and mark-above-read buttons — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:executing-plans (inline; one commit). Steps use checkbox (`- [ ]`) syntax.

**Goal:** When the list shows an empty state such as "You're all caught up.", neither the back-to-top button nor the mark-above-read button is on screen (#1322).

**Architecture:** In `entry-list.component.html` (~203–217) both buttons are gated only on `scrolling.showToTop()` and `scrolling.hasAboveFold()`. Both signals keep their values when the rows disappear. Nest both buttons under a single `@if` that also requires `content.visibleEntryCount() > 0`, the same count that selects the empty-state branch (~81).

**Tech Stack:** Angular 20 control flow, Jest in the frontend container.

## Global Constraints

- Branch `fix/1322-caught-up-hides-list-buttons` off `develop`. Commit format `type(#1322): summary`. First commit copies this plan to `docs/superpowers/plans/`.
- Gate: `docker compose exec -T frontend npm run check`.
- Deploy to Strato when merged; Lars asked for it. Push the next `v1.0.17-dev.N` tag on the merge commit, following the next-dev-tag rule in memory. The last tag was `v1.0.17-dev.9`.

---

### Task 1: Buttons only while entries are visible

**Files:**
- Modify: `frontend/src/app/reader/list/entry-list/entry-list.component.html` (~203–217)
- Test: `frontend/src/app/reader/list/entry-list/entry-list.component.spec.ts`, inside `describe('mark-above-read button (#1080)'` (~1700)

- [ ] **Step 1: Failing test.** Add to that describe:

```ts
it('hides both buttons once the list empties to the caught-up state (#1322)', () => {
  const fixture = mount();
  const element = fixture.nativeElement as HTMLElement;
  fixture.componentInstance.scrolling.showToTop.set(true);
  fixture.componentInstance.scrolling.hasAboveFold.set(true);
  fixture.detectChanges();
  expect(element.querySelector('app-to-top-button')).not.toBeNull();
  expect(element.querySelector('.mark-above')).not.toBeNull();

  fixture.componentRef.setInput('entries', []);
  fixture.detectChanges();

  expect(element.querySelector('app-to-top-button')).toBeNull();
  expect(element.querySelector('.mark-above')).toBeNull();
});
```

  Run `docker compose exec -T frontend npx jest src/app/reader/list/entry-list/entry-list.component.spec.ts -t 1322`. Expected: FAIL at the last two expects.

- [ ] **Step 2: Template.** Replace the two blocks

```html
@if (scrolling.showToTop()) {
  <app-to-top-button (activate)="scrollToTop()" />
}

@if (scrolling.showToTop() && scrolling.hasAboveFold()) {
  <button
```

with one block (the button's attributes and body stay as they are):

```html
@if (scrolling.showToTop() && content.visibleEntryCount() > 0) {
  <app-to-top-button (activate)="scrollToTop()" />

  @if (scrolling.hasAboveFold()) {
    <button
      type="button"
      class="mark-above"
      (click)="onMarkAboveRead()"
      [attr.aria-label]="'reader.markAboveRead' | transloco"
      [attr.title]="'reader.markAboveRead' | transloco"
    >
      <app-icon name="done_all" size="md" />
    </button>
  }
}
```

  Check that `content` is readable from the template; the empty-state branch at ~81 already reads `content.visibleEntryCount()`.

- [ ] **Step 3:** Rerun the test from Step 1, expecting PASS. Then run the gate.
- [ ] **Step 4: Manual check** in the running app on :4200, after restarting the frontend container:
  1. Open an unread list with enough entries to scroll, and scroll down until both buttons appear.
  2. Mark the list read. The page shows the caught-up illustration and neither button.
  3. Restore the dev data you changed, by marking those entries unread again.
- [ ] **Step 5:** Commit `fix(#1322): caught-up list hides the to-top and mark-above-read buttons`. Open the PR (`Closes #1322`), merge when green, then tag and deploy to Strato as in the Global Constraints and report the deploy run.
