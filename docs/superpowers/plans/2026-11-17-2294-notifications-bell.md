# Notifications Bell and Magazine-Width List Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace each social network's in-card "Notifications" row with a clickable bell (unseen count as a badge) right of the network name in the card head, and set the notification list in the magazine column.

**Architecture:** A new `SidebarNotificationsBellComponent` (a link: bell icon + badge) replaces `SidebarNotificationsRowComponent` and sits in `SidebarSocialSectionComponent`'s head between the name and the unread count. The head's single network link is split in two (name part, count part) so the bell is never nested in a link. The notification list's `.rows` adopt the magazine column (`--magazine-measure`) and the airy sheet. The 99+ cap the header badge uses moves to a shared helper both badges call.

**Tech Stack:** Angular 20 standalone + signals, Transloco, SCSS (Stylelint), Jest (jsdom), Playwright.

**Spec:** `docs/superpowers/specs/2026-11-17-notifications-bell-design.md` (issue #2294)

## Global Constraints

- Bell: `--icon-dense` (18px); colour `--text-secondary` with unseen, `--text-muted` at 0, `--accent` when that network's notification list is open.
- Badge: solid `--accent` disc, 14px tall, ~9px bold tabular digits, text `--surface-0`, on the bell's upper-right shoulder; absent at 0; caps at `99+`.
- Bell link target: `/?view=notifications&network=<network>` (same as today's row, `selectionQueryParams({ view: 'notifications', network })`, `queryParamsHandling="merge"`).
- Bell shows only when the network has a connected account (`networkAccounts.length > 0`) and not while organising. It sits in the head, so it shows on a collapsed card too.
- Coarse pointer: bell hit area ≥ `--tap-target` (44px) without growing the drawn bell.
- Accessible name: `"<Network> notifications"`, plus `", N unseen"` when N > 0 — in en/de/fr/es, via the `…One`/`…Other` pair convention (`core/i18n/plural-key.ts`).
- List: rows column `max-width: var(--magazine-measure)`, centred, sheet behind it (`--surface-read` in dark, `--surface-2` in light, exactly as `entry-list`'s `.rows.magazine.airy`); the list header stays full width.
- No hex colours, no ad-hoc `px` outside the theme (Stylelint); a deliberate `px` (the 9px numeral) gets a `stylelint-disable-next-line` with its reason, as the header badge does.
- Header avatar badge and account dropdown keep their look.
- Commits: `type(#2294): lower-case summary`.

## Running the gates from this worktree

The Docker `frontend` container bind-mounts the MAIN checkout, so it never sees this worktree. Run Jest and `npm run check` in a throwaway container on this worktree, reusing the stack's Linux `node_modules` volume (find its name with `docker volume ls | grep frontend-node-modules`):

```bash
docker run --rm -v "$PWD/frontend:/app" -v simple-feed-reader_frontend-node-modules:/app/node_modules -w /app node:22-slim npx jest <path>
```

Same command with `npm run check` for the full gate. Playwright (Task 4) needs the running stack to serve this branch, so it runs after the branch is checked out in the main checkout — not from the worktree.

## Review Focus

1. **Clicking the head anywhere but the bell still opens the network.** The split link must leave no dead strip between the name and the count — assert both parts link to `/?network=bluesky` (Task 2 test `keeps the head opening the network on both sides of the bell`).
2. **Long network names / narrow drawer.** The name ellipsises before the bell or the count is pushed out — Task 2 test asserts the bell sits between the label and `.section-count` in DOM order; the CSS gives the name `min-width: 0` and the bell/count `flex: none`.
3. **Badge clipped by the head band.** The expanded head has a background band and the card has `overflow`; the badge's `top` offset must stay inside the head — check visually in Task 4's live look.
4. **Three-digit counts.** `99+` must fit the 14px-tall disc without wrapping — Task 1 test `caps the badge at 99+` plus `white-space: nowrap` and `min-width`-based width.
5. **Keyboard users.** The bell is a real link in tab order between the network name and the ⋯ menu, with its accessible name — Task 1 tests the `aria-label`; the count part of the split link is `tabindex="-1"` so the network isn't tabbed to twice.

---

### Task 1: Shared badge cap and the bell component

**Files:**
- Create: `frontend/src/app/shared/badge-count.ts`
- Create: `frontend/src/app/shared/badge-count.spec.ts`
- Modify: `frontend/src/app/reader/shell/header/reader-header.component.ts:102-106` (use the helper)
- Create: `frontend/src/app/reader/shell/sidebar/sidebar-notifications-bell.component.{ts,html,scss,spec.ts}`
- Modify: `frontend/public/i18n/{en,de,fr,es}.json` (`reader.notifications` block)

**Interfaces:**
- Produces: `badgeCount(count: number): string` — `'99+'` above 99, else `String(count)`.
- Produces: `<app-sidebar-notifications-bell [network]="SocialNetworkName" [count]="number" [active]="boolean" />`, selector `app-sidebar-notifications-bell`, host class `current` when `active`, host class `has-unseen` when `count > 0`. Renders `<a class="bell">` containing `<app-icon name="notifications">` and, when `count > 0`, `<span class="badge" aria-hidden="true">`.
- i18n keys: `reader.notifications.bell` ("{{network}} notifications"), `reader.notifications.bellWithUnseenOne` ("{{network}} notifications, 1 unseen"), `reader.notifications.bellWithUnseenOther` ("{{network}} notifications, {{count}} unseen"). de: "{{network}}-Benachrichtigungen" / "…, 1 ungesehen" / "…, {{count}} ungesehen"; fr: "Notifications {{network}}" / "…, 1 non vue" / "…, {{count}} non vues"; es: "Notificaciones de {{network}}" / "…, 1 sin ver" / "…, {{count}} sin ver".

- [ ] **Step 1: Write the failing tests**

`badge-count.spec.ts`: `badgeCount(0) === '0'`, `badgeCount(99) === '99'`, `badgeCount(100) === '99+'`.

`sidebar-notifications-bell.component.spec.ts` (mount like `sidebar-social-section.component.spec.ts`: `provideRouter([])`, `provideTranslocoTesting`):
- `links to the network's notifications` — `a.bell` href `'/?view=notifications&network=bluesky'`, contains `app-icon` with text `notifications`.
- `shows the unseen count as a badge` — count 4 → `.badge` text `'4'`.
- `shows no badge at zero` — count 0 → no `.badge`.
- `caps the badge at 99+` — count 120 → `.badge` text `'99+'`.
- `names the network and the unseen count` — count 0 → `aria-label` `'Bluesky notifications'`; count 1 → `'Bluesky notifications, 1 unseen'`; count 4 → `'Bluesky notifications, 4 unseen'`.
- `marks itself current` — `active` true → host has class `current`; false → not.

- [ ] **Step 2: Run them to verify they fail**

Run (worktree gate command) `npx jest src/app/shared/badge-count.spec.ts src/app/reader/shell/sidebar/sidebar-notifications-bell.component.spec.ts`
Expected: FAIL, modules not found.

- [ ] **Step 3: Implement**

`badgeCount` in `shared/badge-count.ts`; `ReaderHeaderComponent.badgeText` becomes `computed(() => badgeCount(this.unseenTotal()))`. The bell component: OnPush, inputs as above, `label = computed(...)` choosing `reader.notifications.bell` at 0 else `pluralKey('reader.notifications.bellWithUnseen', count)`, network name from `'settings.social.' + network` (as the section does). SCSS: `.bell` `position: relative; display: inline-grid; place-items: center; color: var(--text-muted)`; `:host(.has-unseen) .bell` `--text-secondary`; `:host(.current) .bell` `--accent`; icon size `dense`; `.badge` modelled on `reader-header.component.scss` `.badge` but 14px tall (`calc(var(--icon-sm) - var(--space-0))`), `min-width` the same, `white-space: nowrap`, numeral `9px` with the stylelint-disable reason line; under `@media (pointer: coarse)` the link gets `min-width`/`min-height: var(--tap-target)` with negative margins so the drawn size and the head height don't change. Add the 12 i18n strings.

- [ ] **Step 4: Run the tests to verify they pass**

Same command. Expected: PASS. Also run `src/app/reader/shell/header/reader-header.component.spec.ts` — its `caps the badge at 99+` stays green.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/app/shared/badge-count* frontend/src/app/reader/shell/header/reader-header.component.ts frontend/src/app/reader/shell/sidebar/sidebar-notifications-bell.component.* frontend/public/i18n/
git commit -m "feat(#2294): add the notifications bell with an unseen badge"
```

---

### Task 2: Bell in the network head, row removed

**Files:**
- Modify: `frontend/src/app/reader/shell/sidebar/sidebar-social-section.component.{html,ts,scss,spec.ts}`
- Modify: `frontend/src/app/reader/shell/sidebar/_sidebar-row.scss` (`section-head` mixin, only if the split link needs it)
- Delete: `frontend/src/app/reader/shell/sidebar/sidebar-notifications-row.component.{ts,html,scss}`

**Interfaces:**
- Consumes: `app-sidebar-notifications-bell` from Task 1; existing `notificationsActive` computed and `section().notificationsUnseen`.

Head layout (non-organising branch), in this DOM order: `<a class="section-link">` logo + `.label` (`flex: 0 1 auto`), then `@if (current.networkAccounts.length > 0) <app-sidebar-notifications-bell>`, then `<a class="section-link section-rest" tabindex="-1" aria-hidden="true">` with `.section-count` (`flex: 1`, content justified to the end; same `routerLink`/`queryParams`). Both links share the `section-link` look; only the first carries the leading-inset negative margin. Organising branch unchanged (no bell).

- [ ] **Step 1: Replace the `describe('Notifications row')` block with `describe('Notifications bell')`**

- `sits between the network name and the count` — in `[data-testid=sidebar-social-bluesky]`, `.label`, `app-sidebar-notifications-bell`, `.section-count` appear in that document order (`compareDocumentPosition`).
- `keeps the head opening the network on both sides of the bell` — both `a.section-link` hrefs are `'/?network=bluesky'`; the bell is not inside either (`closest('a.section-link')` is null).
- `passes the unseen count` — `notificationsUnseen: 4` → bell `.badge` text `'4'`; head `.section-count` still `'3'`.
- `marks the bell current for this network's notifications` — selection `{ kind: 'notifications', network: 'bluesky' }` → bell host has `current`; `'mastodon'` → not.
- `shows the bell on a collapsed card` — `localStorage['sfr.bluesky.collapsed'] = 'true'` → bell present, `.rows` absent.
- `offers no bell without a connected account` — `networkAccounts: []` → no bell.
- `hides the bell while organising` — as the old row test.
- `has no Notifications row any more` — no `app-sidebar-notifications-row`; `.rows` first child is a feed row.

- [ ] **Step 2: Run to verify they fail**

Run (gate command) `npx jest src/app/reader/shell/sidebar/sidebar-social-section.component.spec.ts`
Expected: FAIL on the new bell tests.

- [ ] **Step 3: Implement** the head layout above, drop the row block from `.rows` and the row component from `imports`, delete the row component files, and adjust the head SCSS so the name ellipsises (`min-width: 0`), the bell and count are `flex: none`, and a `--space-2` gap separates name and bell.

- [ ] **Step 4: Run to verify they pass**

Same command, then the whole sidebar folder: `npx jest src/app/reader/shell/sidebar`. Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add -A frontend/src/app/reader/shell/sidebar
git commit -m "feat(#2294): move notifications into a bell in the network head"
```

---

### Task 3: Notification list in the magazine column

**Files:**
- Modify: `frontend/src/app/reader/notifications/notification-list/notification-list.component.scss`

No Jest test (layout only; jsdom has no layout) — Task 4's Playwright test pins it.

- [ ] **Step 1: Implement** on `.rows`: `display: flex; flex-direction: column; align-items: center; padding-inline: var(--space-5)`, children `width: 100%; max-width: var(--magazine-measure)`, and the sheet `background: linear-gradient(var(--sheet), var(--sheet)) center / min(100%, calc(var(--magazine-measure) + 2 * var(--space-5))) 100% no-repeat` with `--sheet: var(--surface-2)`, overridden to `--surface-read` under `:host-context([data-theme='dark'])` — same values as `entry-list.component.scss` `.rows.magazine.airy`. Keep the existing padding-top/bottom and min-height. The `.hint` margins become inline-zero inside the column.

- [ ] **Step 2: Run the frontend gate**

Run (gate command) `npm run check`. Expected: exit 0.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/app/reader/notifications/notification-list/notification-list.component.scss
git commit -m "feat(#2294): set the notification list in the magazine column"
```

---

### Task 4: Playwright smoke and live look

**Files:**
- Modify: `frontend/e2e/social-notifications.spec.ts` (tests at lines ~198–260 target the removed row)

- [ ] **Step 1: Rewrite the row tests for the bell**

- Replace `the network card leads with Notifications, its pill aligned with the counts` with `the network head carries a bell with the unseen count`: Bluesky head `app-sidebar-notifications-bell .badge` has text `'4'`; Lemmy's bell is visible with no `.badge`; Bluesky `.section-count` still `'51'`; no `app-sidebar-notifications-row` on the page; the bell's bounding box lies left of `.section-count`'s and right of `.label`'s.
- In `the list shows the grouped notifications`, click `networkCard(page, 'bluesky').locator('app-sidebar-notifications-bell a')`; add: the first `app-notification-row`'s width ≤ 680 and its horizontal centre is within 2px of `.rows`' centre (viewport 1440 wide).
- In `Mark all seen …`, the before/after count assertions read `app-sidebar-notifications-bell .badge` (`'4'`, then `toHaveCount(0)`).
- Update the file's header comment line 12 to describe the bell.

- [ ] **Step 2: Check the branch out in the main checkout** (worktree code is not served by the stack): after confirming the main checkout is clean and no other session uses it, `git switch feature/2294-notifications-bell` there.

- [ ] **Step 3: Run the smoke**

Run from `frontend/` in the main checkout: `npx playwright test e2e/social-notifications.spec.ts`. Expected: all pass.

- [ ] **Step 4: Live look** at http://localhost:4200 in light and dark, desktop 1440 and phone 375: bell beside each network name, badge not clipped (Review Focus 3), 99+ fits (stub or force a count), notification list centred at 680px with the sheet. Compare with the approved mockup (A at 18px).

- [ ] **Step 5: Commit**

```bash
git add frontend/e2e/social-notifications.spec.ts
git commit -m "test(#2294): cover the notifications bell and the list column"
```
