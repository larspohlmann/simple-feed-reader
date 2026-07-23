# Plan 5d — Magazine Reading Layout — Design

**Status:** approved (collaborative brainstorm, 2026-07-23)
**Depends on:** 5b (core reader — entry list, `EntryRow`, `ReadingLayoutService`, preview-image extraction). No backend change.
**Reference:** tagesschau.de at narrow widths — a mix of one large lead item, medium items with a side thumbnail, and collapsed runs of small items grouped by source.

## Goal

Add a third on-device reading layout — **Magazine** — that renders the *same* entry list as a varied, scannable single column instead of uniform rows: a large **hero** now and then, medium **feature** rows, one-line **compact** items, and **source groups** that collapse a run of consecutive same-source entries into three small items plus a "more" link to that feed. The point is that scrolling never looks monotonous. Magazine becomes the **default** layout; List and Pane remain available.

## Why it needs no backend change

Every signal the layout decisions need already ships on `EntryDto` (verified in 5b): `title`, `summary`/`contentHtml` (→ `textSnippet()` length and `firstPreviewImage()`), `source` + `subscriptionId` (grouping), `publishedAt`/`createdAt`, and `isRead`/`isFavorite`/`isKept`. The layout is a **pure planner** over the already-ordered entry list.

## Settled decisions (from the brainstorm)

- **A — Width:** single column everywhere. On wide screens the column is centered with a max reading width (~680px), so heroes don't stretch absurdly.
- **B — Read entries:** **not** demoted. Read state does not change an entry's size; it keeps the existing dimmed-title / no-unread-dot styling.
- **C — Hero without an image:** allowed. A long-text entry with no usable image can still lead as a **text hero** (headline-forward, no image block).
- **D — Source grouping:** trigger at **≥3 consecutive** entries sharing a `subscriptionId`; show the first 3 as compact items + a "more" link. The "more" link opens that source's feed showing **all** its entries (not unread-filtered).
- **E — Default:** Magazine is the **default** mode for anyone with nothing saved. Users who already saved `list`/`pane` keep it.

**Heuristics in v1** (the ★ set, minus read-demotion per B):
1. **Rhythm cadence** — even when content signals are weak, impose a repeating shape (a hero roughly every N blocks, never two heroes adjacent, features and compacts between). Content *biases* the pattern; the cadence *guarantees* variety.
2. **Image-quality gate** — after a hero's image loads, if it errored or is too small (tracking pixel / favicon), the hero renders as a feature instead. Prevents an empty giant card. (Runtime refinement in the hero component, not the planner.)

Explicitly deferred (offered, not chosen for v1): recency-lead, text-density weighting, media-type cards, favorite-never-shrinks, and 2-column masonry on wide screens. None are precluded by this design.

## Architecture

```
src/app/reader/
  reading-layout.service.ts     EDIT  — add 'magazine' to the union; default → 'magazine'
  magazine/
    magazine-planner.ts         NEW   — pure planMagazine(entries, selection) → Block[]
    entry-hero.component.ts      NEW   — hero card + image-quality gate → feature fallback
    entry-compact.component.ts   NEW   — one-line item
    source-group.component.ts    NEW   — labelled 3 compacts + "more" link
  entry-row/entry-row.component.ts  EDIT — add optional imageSide input ('right' default)
  entry-list/entry-list.component.ts EDIT — add `layout` input; render planned blocks in magazine mode
  header/reader-header.component.ts EDIT — third segment in the layout toggle
  reader-shell.component.ts     EDIT  — pass layout mode into <app-entry-list>
```

`EntryListComponent` keeps ownership of the list header (title, Unread/All toggle, Mark-all-read), the loading/empty/error states, and the infinite-scroll sentinel + `IntersectionObserver`. Only its **body** switches: in `list`/`pane` it renders the current flat `@for` of `EntryRow`; in `magazine` it renders `planMagazine(entries, selection)`. This avoids duplicating the header/scroll machinery.

Pane stays a distinct mode (`layout==='pane' && wide` → list+reader split). Magazine is single-column: opening an entry shows the reader view exactly as List mode does today.

## The planner (the crux)

`magazine-planner.ts` exports a pure function and its block types:

```ts
export type MagazineBlock =
  | { kind: 'hero'; entry: EntryDto }
  | { kind: 'feature'; entry: EntryDto; imageSide: 'left' | 'right' }
  | { kind: 'compact'; entry: EntryDto }
  | { kind: 'group'; subscriptionId: number; source: string; entries: EntryDto[]; moreCount: number };

export function planMagazine(entries: EntryDto[], grouping: boolean): MagazineBlock[]
```

- `grouping` is `true` in aggregated views (All items, a tag, favorites, kept) and `false` in a single-subscription view (there, every entry is one source, so grouping is meaningless and we vary by tier only). The caller derives it from `selection.kind !== 'subscription'`.

**Tunable constants (top of the module):**
- `LONG_TEXT = 120` — min `title.length + snippetLength` for an image hero.
- `TEXT_HERO = 180` — higher bar for an image-less text hero (no picture to carry it).
- `GROUP_MIN = 3` — a run of this many consecutive same-source entries is grouped.
- `GROUP_SHOW = 3` — compact items shown inside a group.
- `HERO_PERIOD = 6` — target spacing between heroes.

Snippet length uses `textSnippet(summary ?? contentHtml).length` (the same helper the row uses), computed once per entry.

**Algorithm — a single deterministic left-to-right pass:**

1. Walk `entries` by index. At each position:
   - **Grouping (aggregated views only):** if `grouping` and the run of consecutive entries starting here that share `subscriptionId` has length `≥ GROUP_MIN`, emit one `group` block — `entries` = first `GROUP_SHOW`, `moreCount = runLength − GROUP_SHOW` — advance the index past the **whole run**, and reset `sinceHero` to 0 (a group is itself a change of pace). Continue.
   - **Otherwise tier this single entry:**
     - `heroEligible` = `hasImage && (titleLen + snippetLen) ≥ LONG_TEXT`, OR `!hasImage && (titleLen + snippetLen) ≥ TEXT_HERO`.
     - If `heroEligible && !lastWasHero && sinceHero ≥ HERO_PERIOD − 1` → emit `hero`; `sinceHero = 0`, `lastWasHero = true`.
     - else if `hasImage` → emit `feature` with `imageSide` alternating (`featureCount` even → right, odd → left); `sinceHero++`, `lastWasHero = false`.
     - else → emit `compact`; `sinceHero++`, `lastWasHero = false`.
   - Seed `sinceHero = HERO_PERIOD` initially so the first hero-eligible entry near the top can lead.

2. The pass depends only on entries seen so far, so **appending a page never changes earlier blocks** — infinite scroll re-plans the whole loaded list and the prefix is stable. (Only the boundary can shift: a same-source run split across a page fetch may cross `GROUP_MIN` once the next page arrives. That re-plans the tail near the fold, never the stable prefix above.)

3. **Block keys** for `@for` tracking: `entry.id` for hero/feature/compact; `` `group-${subscriptionId}-${entries[0].id}` `` for a group.

### Determinism & image-quality gate boundary

The planner is synchronous and pure — it cannot know an image's real dimensions (those need a load). So the **image-quality gate lives in `EntryHeroComponent`**: the planner marks a block `hero`; the component loads the image and, on error or if `naturalWidth < 200` (tracking pixel / icon), flips an internal signal and renders the **feature** layout instead. This keeps the planner testable and stable while still avoiding empty heroes.

## Components

All new components emit the **same outputs** as `EntryRow` (`favorite`, `keep`, `read`, `open`) so the shell wiring is unchanged, and take a single `entry` input (plus specifics). All colours from tokens.

- **`EntryHeroComponent`** — a card: full-width 16:9 image (when present and not gated out), a source + relative-time kicker, a large headline (`--fs-lg`/`xl`), a 2–3 line dek, the action buttons, and the unread dot / read dimming. Text-hero variant (no image) drops the image block and gives the headline more room. Image-quality gate as above.
- **`EntryCompactComponent`** — one row: unread dot, `source · when` kicker (small), single-line clamped title, whole row opens the entry. No thumbnail. Keeps action buttons minimal (open only; favorite/keep/read still reachable from the opened reader).
- **`SourceGroupComponent`** — a card with a header (`source` name + a coloured dot), three `EntryCompactComponent`s (or inline equivalents), and a footer link: `{moreCount > 0 ? moreCount + ' more from ' + source : 'More from ' + source}` → routerLink to `{ subscription: subscriptionId, view: null, tag: null, entry: null, unread: '0' }` (the source's full feed, all entries — decision D). The three inner items still open normally.
- **`EntryRowComponent`** (existing) — gains an optional `imageSide: 'left' | 'right'` input (default `'right'`, so List/Pane are unchanged) used for the `feature` tier's alternation.

## Layout service & header

- `ReadingLayout = 'list' | 'pane' | 'magazine'`. `readSaved()` returns the stored value when it is one of the three, else **`'magazine'`** (new default). `set()` persists any of the three.
- Header layout toggle gains a third segment — Magazine — with a distinct icon (`view_quilt`), `aria-label="Magazine layout"`, active-state styling identical to the other two. Order: Magazine, List, Pane (default first).

## Column & responsiveness

Magazine renders inside the existing scroll container. On wide screens the block column is centered with `max-width: ~680px; margin-inline: auto` for a comfortable measure; on narrow screens it is full-width. Pane's wide-only split is untouched. Hero images use `aspect-ratio: 16/9; object-fit: cover; width: 100%`, lazy + `referrerpolicy="no-referrer"` (same as the row thumbnail).

## Testing

- **Planner (Jest, pure — the bulk):** grouping fires at exactly 3 and collapses longer runs with the right `moreCount`; no grouping in a single-subscription view; runs of 2 stay ungrouped; hero spacing obeys `HERO_PERIOD` and never adjacent; text-hero appears for long-text-no-image and not for short items; `imageSide` alternates; **prefix-stability** — `planMagazine(list)` is a prefix of `planMagazine(list.concat(page2))` up to the last complete pre-boundary block; block keys are unique.
- **Components:** hero renders headline/dek and, on image `error` or a `naturalWidth < 200` load, falls back to the feature layout; compact opens on click/Enter; source-group renders 3 items, the correct "more" label, and the right routerLink params; `EntryRow` honours `imageSide`.
- **Layout service:** default is `magazine` when storage is empty; a saved `list`/`pane` is honoured; `set('magazine')` round-trips.
- **Header:** third segment present, toggles the mode.
- **Playwright smoke:** with Magazine as default, the reader shows a hero and (given ≥3 same-source unread) a source group; switching to List still renders flat rows. Skip-if-unreachable, same convention as the existing smokes.
- Gate `npm run check` + `npm run build` green; verified live against Docker.

## Native-iOS readiness (standing rule)

Pure client-side presentation over the same bearer-JWT JSON; the layout choice is on-device (localStorage), touches no API and no auth. A native client would implement its own layout but reads the identical `EntryDto`. No new web-coupling.

## Out of scope (explicit)

- 2-column / masonry magazine on wide screens (single column only in v1).
- Read-demotion, recency-lead, text-density weighting, media-type cards, favorite-never-shrinks (deferred heuristics; the planner is structured to add them later).
- Server-side layout hints or per-feed layout overrides.
- Changing List or Pane behaviour beyond the additive `imageSide` input.
