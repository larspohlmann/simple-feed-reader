# AI settings page documentation — design

Issue: [#372](https://github.com/larspohlmann/simple-feed-reader/issues/372)
Branch: `feature/372-ai-settings-page-docs` (to be created off `develop`)
Date: 2026-08-14

> **Handoff note:** this file belongs in `docs/superpowers/specs/`, the plan in
> `docs/superpowers/plans/`. Both were authored outside the repo because the
> checkout was busy on #371 at the time; commit them as the first commit of the
> feature branch.

## Problem

`/settings/ai` exposes ~20 controls with almost no explanation. A new user
cannot tell what "active" means, what the caps do, or in what order to set
things up. The only in-page help today is `app-field`'s always-visible `hint`
line and a few plain hint spans — fine for one-liners, unusable for the
paragraph-sized explanations this page needs, and already cluttering the page
where the one-liners have grown long (reasoning, batch concurrency).

## Solution

1. A new shared **`<app-info-tip>`** component: a small ⓘ icon button that
   toggles an explanation panel. Click/tap to open (hover tooltips fail on
   touch), outside-press or Escape to close (the existing
   `DismissOnOutsideDirective` provides both).
2. **`app-field` gains an `info` input** — the field renders the tip's trigger
   at the top-right of its label row and the panel between control and hint.
   One line per call site; covers the majority of the page's controls.
3. A **step-by-step setup guide** at the top of the page: an
   `app-settings-card` with `collapsible` (the shared `<details>` wrapper), so
   it is collapsed by default with zero new accordion code. Two ordered
   walkthroughs: connect the AI endpoint, then configure recommendations.

## Decisions

- **In-flow panel, not a floating overlay.** The open panel renders in normal
  document flow and pushes content down, styled as a bordered `--surface-2`
  box. A floating popover needs viewport-edge collision handling on phones;
  the in-flow panel cannot clip or overflow by construction, which is what
  "works well on mobile" needs. Layout shift is acceptable on a settings form
  — this very page already shifts constantly through its `<details>`
  disclosures. (Rejected: CDK-overlay popover — collision logic and a second
  positioning system for no user-visible gain; rejected: hover `title`
  tooltips — invisible on touch, not translatable styling, fails the issue's
  a11y requirement.)
- **Click-to-toggle, never hover-only.** Same affordance on desktop and touch.
  The trigger is a real `<button>` with `aria-expanded`, `aria-controls`, and
  an `aria-label`; the panel has `role="note"`. Keyboard: Tab reaches the
  button, Enter/Space toggles, Escape closes.
- **Shared component takes already-translated strings** (`text`, `label`),
  like every other component in `shared/` — no feature keys inside `shared/`.
- **The trigger's accessible name is the control's label.** `app-field` passes
  its own `label()`; standalone call sites pass the translated label of the
  control they explain. The `aria-expanded` state disambiguates it from the
  control itself. (Rejected: a per-site "More information about X" key — ~20
  extra keys for marginal gain; rejected: a hardcoded English name — the
  spinner's hardcoded "Loading" is a wart, not a pattern.)
- **No info tips inside `<summary>` elements.** A click on non-interactive
  content inside `<summary>` toggles the `<details>`, so an open panel there
  would collapse the row when tapped. The "Active" badge sits in a summary
  line; its explanation therefore lives in the row-actions tip (first
  sentence) instead of on the badge.
- **One cluster tip for the five row actions** (Use this one / Change model /
  Duplicate / Rename / Delete) instead of five per-button icons. The buttons
  are already labelled with verbs; five ⓘ icons in one row is noise. The
  cluster tip satisfies "every control has an info affordance" — the
  affordance sits directly at the actions row.
- **Existing hints: keep the short, keep the stateful, migrate the long.**
  `baseUrlHint`, `apiKeyHint`, `debugHint` and the dynamic context-window
  source hint stay visible (short or state-bearing); `reasoningHint` and
  `batchConcurrencyHint` move into the info tips and the visible hint lines
  are removed (they are the clutter the issue describes). The danger zone
  keeps its always-visible `purgeExplain` — a destructive action explains
  itself without a click — and gains a tip with the full consequences.
- **The look-back select is included** although the issue predates it (#386
  added it); "every control" wins over the issue's literal list.
- **Guide = collapsible settings card, first card on the page.** Reuses
  `app-settings-card [collapsible]` exactly as `addTitle` already does;
  collapsed by default is that component's existing behaviour.

## `<app-info-tip>` contract

`frontend/src/app/shared/info-tip/` — standalone, OnPush, signal inputs,
sibling `.scss`.

| Input | Type | Meaning |
|---|---|---|
| `text` | `string` (required) | already-translated panel text |
| `label` | `string` (required) | already-translated accessible name for the trigger |
| `corner` | boolean attribute, default `false` | trigger is absolutely positioned at the top-right of the nearest **positioned ancestor** (used by `app-field`, whose host becomes `position: relative`); panel stays in flow |

Internal state: one `open` signal. The wrapper span carries
`[appDismissOnOutside]="open()" (dismiss)="close()"`. The trigger click
handler calls `preventDefault()` and `stopPropagation()` so a tip inside any
future `<summary>`/`<label>` context cannot trigger the container's default
activation. Trigger icon: `<app-icon name="info" size="text" />`. Under
`pointer: coarse` the trigger grows to `--tap-target`. Panel: `--surface-2`
background, `--border` border, `--radius`, token spacing, no hex, no px
(except a documented `max-width` escape hatch if needed — prefer `ch` units,
which the sizing rule allows).

`app-field` addition: `info = input<string | null>(null)`; when set, `:host`
gets `position: relative` and the template renders
`<app-info-tip corner [text]="info()!" [label]="label()" />` between the
`</label>` and the hint. Fields without `info` render byte-identical DOM to
today.

## Control → copy mapping

New keys under `settings.ai.info.*` (flat, one key per tip) and
`settings.ai.guide.*`. All copy exists in `en.json` and `de.json` (informal
"du" tone, matching the file). The exact strings live in the plan; the map:

| Control | Placement | Key |
|---|---|---|
| Active badge + row actions | tip after the `.acts` row in the config body | `info.rowActions` (label: `info.actionsLabel`) |
| Suppress reasoning toggle | tip beside the toggle label; `reasoningHint` line removed | `info.reasoning` |
| Batch concurrency | `app-field` `info`; `batchConcurrencyHint` removed | `info.batchConcurrency` |
| Model picker (when open) | `app-field` `info` on the model select | `info.modelPicker` |
| Add: name | `app-field` `info` | `info.name` |
| Add: Base URL | `app-field` `info`; short hint stays | `info.baseUrl` |
| Add: API key | `app-field` `info`; short hint stays | `info.apiKey` |
| Auto-generate schedule | `app-field` `info` (covers worker + cron/curl fallback) | `info.autoGenerate` |
| Look back | `app-field` `info` | `info.lookback` |
| Guidance prompt | `app-field` `info` (covers Reset) | `info.guidance` |
| Favorites/Kept/Viewed caps | `app-field` `info`, one each | `info.favoritesCap` / `info.keptCap` / `info.viewedCap` |
| Candidate pool ("Maximum articles") | `app-field` `info` | `info.candidatePool` |
| Picks limit | `app-field` `info` | `info.picksLimit` |
| Batch count | `app-field` `info` | `info.batchCount` |
| Context window | `app-field` `info`; dynamic source hint stays | `info.contextWindow` |
| Fixed prompt | tip beside the fixed-prompt disclosure label — placed **after** the `<app-disclosure>`, not inside its summary | `info.fixedPrompt` |
| Debug toggle | tip in the `.debug-text` span, after the label; `debugHint` stays | `info.debug` |
| Purge | tip beside the danger-zone note; `purgeExplain` stays | `info.purge` |

Guide keys: `guide.title`, `guide.intro`, `guide.connectionTitle`,
`guide.connectionStep1`–`5`, `guide.recommendationsTitle`,
`guide.recommendationsStep1`–`4`.

## Mobile and theming

- Trigger hit area: `--tap-target` under `@media (pointer: coarse)`, the
  documented local pattern (design-language §3 density).
- The in-flow panel spans its container's width on narrow screens; no media
  query needed. Any tuned `max-width` uses `ch` units.
- Colours only from tokens, so light and dark themes both work untested-by-CSS
  but verified visually (plan Task 6).
- The guide card is plain flowing text — nothing to adapt.

## Tests

Jest only (no backend change, no new e2e):

- `info-tip.component.spec.ts` — closed by default; click opens and sets
  `aria-expanded`/`aria-controls`; second click closes; Escape closes;
  pointerdown outside closes; pointerdown inside does not; `label` lands as
  the trigger's `aria-label`; trigger click does not bubble.
- `field.component.spec.ts` — `info` renders a tip wired to the field label;
  absent `info` renders no tip.
- `ai-section.component.spec.ts` — the guide card renders first and its
  `<details>` is closed by default; the add-form fields carry tips; the row
  body carries the actions tip; the reasoning hint line is gone.
- `recommendation-settings-card.component.spec.ts` — expert fields carry
  tips; opening the batch-count tip shows the copy; the danger zone keeps its
  visible note and carries a tip.

`npm run check` is the gate (ESLint + Prettier + Stylelint + Jest).

## Out of scope

- Backend changes of any kind.
- Behaviour changes to any control.
- Info tips on other settings pages (the component is built to be reused, but
  no other page is touched).
- A floating/anchored popover variant.
