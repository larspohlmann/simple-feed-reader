# Collapsible, visually distinct AI provider rows

- **Branch:** feature/347-duplicate-ai-provider-config (added alongside the duplicate feature at the user's request)
- **Date:** 2026-08-10
- **Status:** Design approved.

## Problem

The AI settings section renders every provider configuration as one long row
with all of its controls (key hint, reasoning toggle, parallel-requests select,
model info) and five action buttons visible at once. With several
configurations the list is a dense wall, the rows are hard to tell apart, and
the action buttons sit beside the form controls and compete with them.

## Goal

1. Each configuration collapses to a compact summary by default; its details
   open on demand.
2. Configurations are visually distinct from each other, and the active one is
   obvious.
3. In the expanded body, the action buttons sit in their own full-width bar
   below the form content, not beside it.

## Design decisions (agreed)

- Collapsed summary shows: name (or `host · model` fallback) + the **Active**
  badge + the model, muted.
- All rows start collapsed (the Active badge marks the in-use one).
- All controls and buttons live in the expanded body.
- Distinction: flat rows in the one settings card (no per-row cards, per
  `docs/design-language.md`), a stronger divider, and a left accent bar +
  subtle tint on the active row.

## Part A — extend the shared `<app-disclosure>`

`shared/disclosure/` today renders a string `label` as a bordered pill toggle.
It is "the one wrapper" for `<details>`/`<summary>`, so it is extended rather
than hand-rolling `<details>` in the feature. Two backwards-compatible changes:

1. **Projected summary slot** — the summary renders projected `[summary]`
   content when present, else the `label` string (native `<ng-content>`
   fallback content, supported in this Angular version):
   ```html
   <details>
     <summary><ng-content select="[summary]">{{ label() }}</ng-content></summary>
     <ng-content />
   </details>
   ```
2. **`appearance` input** — `input<'pill' | 'row'>('pill')`. `'pill'` keeps
   today's bordered-toggle chrome (the two existing callers —
   `recommendation-settings-card`, `recommendation-debug-log` — pass nothing and
   stay identical). `'row'` renders a full-width, flat summary sized as a list
   row: no pill border/background, the chevron trailing (pushed right with
   `margin-left: auto` / order), summary padding from the comfortable row
   density. Bind the appearance to a host/summary class so the scss can branch.
3. `label` becomes optional: `input<string>('')` (a `row` caller supplies its
   summary via projection). Existing callers still pass a string and are
   unaffected.

The summary's `'pill'` styling stays exactly as it is now; `'row'` is a new
branch. Update `disclosure.component.spec.ts` with: the projected summary wins
over `label`; the `label` fallback still renders when nothing is projected; the
`appearance="row"` class is applied.

## Part B — rebuild the config row

`settings/ai-section.component.html` + `.scss` (+ spec). The component `.ts`
needs no new logic — `label(config)`, the signals, and all handlers already
exist.

Each `<li class="config-row" [class.is-active]="config.active">` wraps
`<app-disclosure appearance="row" [label]="label(config)">`:

- **Summary slot** (`[summary]`, always visible): `.label` (name or
  `host · model`), the `.badge` when `config.active`, and the model shown muted.
- **Body** (collapsed by default): the key hint (`.hint`), the reasoning toggle
  (`.reasoning-toggle`), the parallel-requests `app-field`/`select` + `.saved`
  note, the model picker (`.model-picker`, shown when
  `choosingModelFor() === config.id`), the inline rename field (shown when
  `renamingId() === config.id`), and then the **action bar**.
- **Action bar** (`.acts`, at the very bottom of the body): Activate
  (`.activate`), Change model (`.change-model`), Duplicate (`.duplicate`),
  Rename (`.rename`), Delete (`.delete`) — a **full-width** row that fills the
  body width and wraps on narrow screens, kept clear of the form controls above.

**Preserve every existing class name** (`.label`, `.badge`, `.hint`, `.model`,
`.reasoning-toggle`, `.activate`, `.change-model`, `.duplicate`, `.rename`,
`.delete`, `.rename-save`, `.rename-cancel`, `.save-model`, `.saved`, the
`app-field select`) and every handler binding, so existing component tests keep
passing.

### scss (tokens only — no hex, no raw px spacing, no media literals outside `theme/`)

- `.config-row`: keep the flat row in the card; strengthen the divider
  (`border-bottom: 1px solid var(--border-strong)`); comfortable row padding.
- `.config-row.is-active`: `border-left: 2px solid var(--accent)` +
  `background: var(--accent-soft)`, with left padding so content clears the bar.
- Body: a vertical stack (`display: flex; flex-direction: column;
  gap: var(--space-3)`).
- `.acts`: `width: 100%`, `display: flex`, `flex-wrap: wrap`,
  `gap: var(--space-2)`; the buttons share the width. Keep the existing phone
  rule intent (full-width wrap) — now the default.
- Summary content: name `.label` full weight; `.model` muted (`--text-muted`,
  `--fs-sm`).

## Testing

- Disclosure spec: projected-summary-wins, label fallback, `appearance="row"`
  class (Part A).
- ai-section spec: the existing suite must stay green (classes/handlers
  preserved). Add: a row is collapsed by default (`details.open === false`); the
  summary carries `.label` + `.badge` (active) + model; expanding the details
  (`summary.click()`) shows the `.acts` bar with the five buttons; the active
  row has `.is-active`. The existing button/click tests may need a
  `summary.click()` to open the row first if a query starts failing — open, then
  assert.

## Non-goals

- No change to any provider behavior or the duplicate feature.
- No new i18n strings (the chevron is CSS; summary reuses existing labels).
