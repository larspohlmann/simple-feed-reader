# Icon-lead: rows outside the module, tag-glyph contract, strut guard (#2563)

## Intent

#2558 put every lead icon on its text's cap middle through `theme/_icon-lead.scss`. The #2563 audit
found five rows that never got the rule (0.33–0.88px low, the comment bubble off the vote pill's
line), one host that breaks the slot contract, and no guard against a rule that overrides the strut.
This branch closes those gaps so every icon beside text in the reader goes through the module, and
an override that breaks the strut fails a test.

## Goals

1. **Outside rows on the module.** Each one gets `first-line` (or `box` for the chip), with gaps
   from the module's formula, not a flat token:
   - `entry-meta` `.comments`
   - `entry-like` (button and `.likes`)
   - `social-counts-row` `.count`
   - tag-picker `.suggestion`: `box`, side padding = gap = lead space
   - comment `.byline`: `first-line('> app-avatar', '> .author')`, self-sized avatar, formula gap
   Each one is pinned in `e2e/icon-lead-first-line.spec.ts`.
2. **Tag-glyph contract.** `app-tag-glyph` sizes its host with `self-sized` (publishing
   `--icon-lead-self`), not the `[style.width]`/`[style.height]` host binding.
3. **Strut guard.**
   - (a) `!important` on the strut properties in `_icon-lead.scss`, so an override fails loudly.
   - (d) An e2e spec that scans the live CSSOM (including `@media`/`@container`) for every
     icon-lead slot, then asserts its strut and alignment on a fine and a coarse pointer.
4. **Cheap optical trial.** Swap `chat_bubble` → `mode_comment` where it leads a count. Keep the
   swap only if a real render shows it centred better. Also try `opsz` 24 the same way.

## Non-goals

- A per-glyph optical-offset table for the ~150 user-pickable tag icons (option 4 in full): it is a
  product decision and gets its own issue.
- Classic pills riding high (option 5: per-theme metric token or `text-box-trim`): its own issue.
- Avatar rows the audit did not render (`app-entry-source`, quoted-post byline, magazine `.gname`,
  story sharers). The scanner spec covers them if they are slots.

## Anti-goals

- No visible change beyond the sub-pixel alignment and the formula gaps: same tokens, no new
  colours, no row-height change on a fine pointer, tap targets unchanged on a coarse one.
- No loosening of the 0.5px tolerance the existing spec pins.

## Constraints

- The row carries the first line's font, and a slot's host spaces its children with margins, not
  `gap` (design-language §Icons beside text).
- The vote pill's arrows keep `block-size: auto` (the documented exception). The `!important`
  strut must leave that exception expressible.
- e2e runs against the single Docker stack that serves the main checkout. The specs are run from
  there, or from a worktree-served `ng serve` on :4201 that proxies to :8443.

## Approaches

- **Chosen: options 1 + 2 + 3(a+d) + the cheap part of 4.** This fixes every measured error and
  guards the strut for the least code. The scanner is the only check that catches `@media`
  overrides.
- Only option 1 would fix the visible error but leave the latent tag-glyph override unguarded.
- All five options would mix in two product decisions that need their own rulings.

## Left to the builder

The exact `lead-gap`/`$gap` arguments per row, how `!important` and the vote-pill exception
coexist, and how the scanner finds slots (marker property, selector list from the module's output,
or the `::before` strut signature).
