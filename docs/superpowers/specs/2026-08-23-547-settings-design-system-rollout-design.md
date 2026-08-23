# Roll the settings design system across settings + admin — design (#547, #454)

**Status:** approved (design round settled 2026-08-23)
**Issues:**

- https://github.com/larspohlmann/simple-feed-reader/issues/547 — roll the system out
- https://github.com/larspohlmann/simple-feed-reader/issues/454 — `/settings/import` cards are nested, not siblings

## Problem

#541 built a reusable "Grouped" settings design system —
`app-settings-group`, `app-settings-row`, `app-settings-save-bar`,
`app-disclosure appearance="drill-in"` — and proved it on one page, the AI
settings section. Every other settings and admin section still composes the
older `app-settings-card`, so the application now shows two different settings
looks depending on which entry the user opens.

Two structural faults sit underneath that split:

1. **Vertical rhythm has no owner.** `settings-shell`'s `.content` is a flex
   column with `gap: var(--space-6)` between routed sections.
   `ai-section.component.scss` then invents its *own* column, `.groups`, with
   `gap: var(--space-7)`, to stack the groups inside one section. Two different
   rhythms, and every section converted from now on copies that `.groups` block
   into its own stylesheet.

2. **Card spacing cannot cross a component host boundary.** The global
   `app-settings-card + app-settings-card` rule in `src/styles/_base.scss` is a
   sibling selector; it stops firing the moment one of the two cards is rendered
   from inside another component. `/settings/import` hit exactly that: the route
   loads `OpmlSectionComponent`, which renders `<app-backup-section />` from its
   own template purely so it lands on the same page, and
   `backup-section.component.scss` carries a compensating `margin-block-start`
   to make up the lost gap.

## Goal

Every settings and admin section composes the same primitives, no section
carries the Grouped look in its own stylesheet, spacing between groups has one
owner that works across component boundaries, and `app-settings-card` is
deleted.

## Correction to #454's premise

#454 states that `recommendation-run-history.component.scss` "already carries
the same workaround, so this is the second instance of the pattern". That is no
longer true: #541 moved the run history inside a Diagnostics drill-in, and its
stylesheet now insets itself to the drill-in's measure instead of compensating
for a lost sibling gap. `backup-section.component.scss` is the only remaining
instance.

The fix #454 proposes is still right, and the design below still closes its
larger note about host-level spacing. Its justification is simply thinner than
the issue text says — one instance, not a repeating pattern.

## Decisions

### 1. A new stack primitive owns the rhythm

Add `<app-settings-stack>` under `frontend/src/app/shared/settings/stack/`. Its
host is a flex column carrying the one canonical group gap plus `min-width: 0`,
and it projects its children.

```html
<app-settings-stack>
  <app-settings-group …>…</app-settings-group>
  <app-settings-group …>…</app-settings-group>
</app-settings-stack>
```

It becomes the template root of every settings and admin route component.

Two properties make it the right shape rather than a CSS rule:

- **It replaces feature glue.** `ai-section.component.scss` deletes its
  `.groups` block, and no converted section ever writes one.
- **It crosses host boundaries.** Its children are flex items, so a child that
  happens to be another component's host element is spaced identically to a
  group written inline. That is precisely what
  `app-settings-card + app-settings-card` could not do, and it is why #454's fix
  becomes structural instead of a margin.

Gap value: `var(--space-7)`, the value #541 settled on for the AI page. The
shell's `.content` gap stays at `--space-6`; only one routed section renders at
a time, so that gap governs the shell's own children, not groups.

**Rejected:** a global `app-settings-group + app-settings-group` rule in
`_base.scss`, beside the existing card rule. It reads as the cheapest option and
works inside a single template, but it fails silently across component host
boundaries — the exact trap #454 documents. Repeating a known trap in a new
selector is not a fix.

### 2. `app-settings-group` gains a header actions slot

`app-settings-card` has a `cardActions` slot. `app-settings-group` has none, so
the three sections that project into it today — `tags-section` ("New tag"),
`admin-catalog` ("Add category") and `admin-user-detail` (the approve / reject /
suspend / delete buttons) — would lose their header action on conversion.

Add a named `<ng-content select="[groupActions]">` to `.g-head`, right-aligned
after the title/caption block.

```html
<app-settings-group icon="sell" [title]="…">
  <app-button groupActions size="sm" variant="primary" (click)="…">…</app-button>
  …
</app-settings-group>
```

### 3. `rowTitleTip` widens its contract instead of gaining a sibling

`preferences-section` puts an "Experimental" badge after a row title.
`app-settings-row`'s `title` is a plain string input, so the badge needs a
projection slot.

The existing `[rowTitleTip]` slot already sits exactly there — immediately after
the title text inside `.row-title`. It takes the badge, and its documented
contract widens from "an info-tip" to "an inline adornment after the title: an
info-tip or a small badge".

**Trade, stated plainly:** the attribute name then under-describes the slot. The
alternative — a second named slot, or a `badge` string input on the row —
duplicates a position that already exists and adds API for one consumer. Widening
the contract costs a name that is slightly narrow; adding a slot costs a
primitive that is slightly wider than the problem. The name loses.

### 4. Admin list surfaces get the group as a frame only

*(Lars's call, 2026-08-23.)* `admin-users`, `admin-catalog` and
`admin-user-detail` render lists and tables, not title/description-plus-control
rows. They are wrapped in `app-settings-group` for the icon-chip header and the
card surface, and their list markup and stylesheets are left alone.

No list-row primitive is added. The system covers rows; lists stay bespoke and
honest about it.

One consequence to handle per section: the group's `.panel` is unpadded by
design, because rows pad themselves. A list that relied on
`app-settings-card`'s padding must carry that padding in its own glue
stylesheet after conversion.

### 5. `/settings/import` becomes a page of two siblings (#454)

Add `ImportSectionComponent` as the route component for `/settings/import`. Its
template is one stack holding `<app-opml-section />` and
`<app-backup-section />` as siblings. `settings.routes.ts` points at it.
`backup-section.component.scss` drops its compensating `margin-block-start`, and
`opml-section` stops rendering a component it does not own.

Both sections become their own `app-settings-group` — "Import & export"
(`import_export`) and "Account backup" (`backup`) — so the page reads as two
groups, and reordering them, or adding a third, is one line in the page
template.

### 6. `app-settings-card` is deleted

Once nothing composes it: delete `frontend/src/app/shared/settings-card/` and
its spec, and delete the `app-settings-card` and `app-settings-card +
app-settings-card` rules from `frontend/src/styles/_base.scss`.

## Conversion map

Icons are the ones each section already carries in `settings-sections.ts` where
one exists.

| Section | Result |
|---|---|
| `about-section` | One group, `info`. Each version line becomes an `app-settings-row` (label as title, version + commit as the control). Loading and stale-bundle states project as-is. |
| `account-section` | **Two groups.** `person` "Account" — the identity block, member-since row, sign-out row. `warning` "Danger zone" — the delete note, the delete button, the error banner. The danger zone earns its own group rather than living as a bordered block inside one card. |
| `preferences-section` | One group, `tune`. Language row and scraping-toggle row; the "Experimental" badge projects through `[rowTitleTip]` (decision 3). Both controls stay instant-save. |
| `tags-section` | One group, `sell`. "New tag" moves to `[groupActions]`. The `cdkDropList` tag list and its stylesheet are unchanged; the list keeps its own padding. |
| `opml-section` | One group, `import_export`. Stops rendering `<app-backup-section />`. |
| `backup-section` | One group, `backup`. Compensating `margin-block-start` deleted. |
| **`import-section`** | **New.** Route component: one stack, the two sections above as siblings. |
| `admin-settings` (instance) | One group, `toggle_on`. The two raw `<input type="checkbox">` become `app-settings-row` + `app-toggle`, matching the rest of the application. Instant save is already the behaviour and stays. |
| `admin-users` | One group, `shield_person`. Status filters move to `[groupActions]`. User list unchanged. |
| `admin-catalog` | **Two groups.** `upload` "Catalog import" — the upload and warm blocks. `category` "Categories & feeds" — the category/feed list. |
| `admin-user-detail` | **Three groups, not five.** The user's own group holds the loading/error/detail chain plus the account/activity/footprint sub-cards; then Tags; then Feeds. Five groups (one per `<h3>`) would have split the loading and error states across sections that cannot hold them, since those states belong to the page, not to a subsection. |
| `proxy-section` | Already Grouped (#490, merged as PR #553). Adopts `app-settings-stack` as its template root; nothing else changes. |

### Save-by-control-type

The convention is already satisfied everywhere this rollout touches: every
control in these sections is either an instant toggle/select or an action
button. No section converted here gains an `app-settings-save-bar`. The bar
stays with the AI section, which owns the only typed-field form.

## Gotchas to respect during conversion

- **The row divider is host-positional.** `app-settings-row` draws its divider
  with `:host(:not(:last-child))`. A group whose panel ends with a *conditional*
  non-row element — an `app-error-banner`, a status paragraph — makes the last
  row stop being `:last-child`, so it draws a trailing divider above that
  element. Where that reads wrong, the trailing element belongs outside the
  group or inside the row's control slot.
- **`app-settings-group` takes translated strings, not keys.** It lives in
  `shared/`. Every `icon`, `title` and `caption` is resolved by the caller's
  `transloco` pipe.
- **New caption keys land in both locales.** `public/i18n/en.json` and
  `public/i18n/de.json`.
- **The proxy section already composes the primitives.** #490 merged into
  `develop` as PR #553 while this design was being written.
  `settings/admin/proxy/` is a single `app-settings-group` with no group column
  of its own, so it needs nothing but the stack wrapper for consistency — see
  the conversion map.

## Testing

- **Primitives.** New spec for `app-settings-stack`. Extend
  `settings-group.component.spec.ts` for the `[groupActions]` slot; extend
  `settings-row.component.spec.ts` for a badge in `[rowTitleTip]`.
- **Sections.** Every section spec that asserts on `app-settings-card` moves to
  `app-settings-group`. Behavioural assertions in those specs stay untouched —
  this is a composition change, not a behaviour change, and a spec that needs
  rewriting beyond its selectors is a signal the conversion changed something it
  should not have.
- **Import page.** New spec for `ImportSectionComponent` asserting both children
  render as siblings. `settings.routes.spec.ts` asserts `/settings/import`
  resolves to it.
- **Deletion.** `settings-card.component.spec.ts` is deleted with the component.
  A tree-wide grep for `app-settings-card` returning nothing is the completion
  check.
- **Gate.** `npm run check` from `frontend/` (ESLint + Prettier + Stylelint +
  Jest).
- **Manual.** Every converted route, desktop and mobile widths, light and dark.

## Out of scope

- A shared list-row primitive for the admin tables (decision 4).
- Any change to `settings-shell`, `settings-nav` or `settings-hub`.
- Any behaviour change. Controls persist exactly when they persist today.

## Work order

The stack and the group actions slot land first, because every conversion
consumes them. `ai-section` moves onto the stack immediately after, which proves
the primitive against the one page already built on the system before nine more
depend on it. Sections then convert one at a time. `app-settings-card` is
deleted last, when the grep is clean.
