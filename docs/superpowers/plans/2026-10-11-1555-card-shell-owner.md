# #1555 — one owner for the magazine card shell

**Goal:** the open handling, the card states and the headline class each have one owner,
so the link and media blocks stop drifting from the others.

## Rulings

- The drift was unintended: link and media align **with** the other blocks (ticket author
  and maintainer). Titled articles in them gain the hover underline and drop to weight 400
  when read; a post's text keeps its current look (`post-text` already sets weight 400 and
  no underline).
- The `card-states` mixin carries `.title.post`, taking the post's line count as a
  parameter (media clamps at 6, the others at 4).
- Compact and quote keep their own hover (background / pull-quote underline); they take
  only the directive.
- Kicker and quote never receive a post (`entryDek` is `''`, so the planner gives them none):
  their `[class.post]`, `.title.post` and quote's `@if (!isPost())` go. Kicker takes the
  mixin anyway, so it carries the shared post rule in compiled CSS — one owner beats a
  second mixin for a rule that costs nothing.
- Quote's `lead` derives from `snippet()` (`entryDek`) instead of repeating its rule (#1557
  leftover).
- `docs/design-language.md` Link and Media rows name the shared states.

## Files

- new `frontend/src/app/reader/list/opens-entry.directive.ts` (+ spec): `[appOpensEntry]`
  owns role, tabindex, `.read` and click / Enter / Space.
- `frontend/src/app/reader/list/magazine/_magazine-card.scss`: `card-states($post-lines)`.
- the nine `blocks/entry-*` templates and SCSS, `entry-row` template/component.
- `entry-media`: `.caption` → `.title`.
- `magazine-block.component.spec.ts`: the headline spec covers every kind.

## Tasks

1. Directive + spec; apply to the nine blocks and `entry-row`; drop the now-unused `actions`.
2. Mixin; hero, wide, split, thumb, kicker, link, media include it and lose their copies.
3. Media rename; headline spec over all kinds.
4. Dead post code in kicker and quote; quote `lead` from `snippet()`.
5. Docs rows; `npm run check` in Docker; verify on the real render.
