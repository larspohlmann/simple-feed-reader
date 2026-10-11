# #1556 — pin share blocks once; the slot ladder knows only template kinds

**Goal:** the share kinds (`link`, `media`) stop riding the demotion ladder. `layOutPage`
pins every share's block once; budgeting, the look-ahead and `settle` work only on the
unpinned positions, whose kinds are `TemplateKind`s.

## Rulings

- `ShareKind` moves next to `EntryKind` in `magazine-block.ts`, with
  `TemplateKind = Exclude<EntryKind, ShareKind>`. `Slot`, `DEMOTION`, `FITS`, `fits`,
  `settle`, `IMAGE_KINDS` and `ImageBar` take `TemplateKind`; `BLOCK_HEIGHT` stays on
  `EntryKind`, since a pinned block counts against the page cap.
- One per-page `PageLayout` (`pinned[]`, image bar) carries the pins, so
  `withinBudget` and `assign` stay at three parameters. A pinned position keeps its
  resolved template kind as a placeholder the steps skip; `toBlock` takes
  `pinned ?? kind`.
- `isShareKind` is deleted (its one caller was the guard that goes away);
  `settle` loses its share early return and the unreachable `'hero'` branch.
- `MagazineBlock` collapses to `{ kind: Exclude<EntryKind, 'split'>; entry }`, split,
  group; `toBlock` loses its `as` cast.
- The slot-fit specs that call `settle` with a share kind or a share entry are deleted:
  the first pin the dead branch, the rest pin the early return that moved into the
  planner, where the "a link share" / "a media share" planner specs already cover it.
- "foreign post(s)" in the planner comments becomes "foreign entry/entries" (#1557 leftover).
- No-change proof: a throwaway recorder logs every `planMagazine` result across the
  list specs on develop and on the branch; the two logs must be identical, and a
  deliberate break must make them differ. The recorder is never committed.

## Files

- `frontend/src/app/reader/list/magazine/magazine-block.ts`
- `frontend/src/app/reader/list/magazine/magazine-slot-fit.ts` (+ spec)
- `frontend/src/app/reader/list/magazine/magazine-planner.ts` (+ spec: `TemplateKind` casts)
- `frontend/src/app/reader/list/magazine/magazine-templates.ts`

## Tasks

1. Record the develop baseline plans with the throwaway recorder.
2. Split the kinds, pin once in `layOutPage`, collapse the block union, drop the dead specs.
3. Re-record, diff against the baseline, break once to prove the diff bites.
4. `npm run check` in the Docker frontend container.
