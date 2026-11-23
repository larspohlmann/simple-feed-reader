# Airy sidebar (#2493)

## Intent

The sidebar's grouped cards (#1749) match the boxed magazine style. Readers who chose the airy magazine get a sidebar in the same language: one sheet, hairline rules, no boxes. The look was agreed on real-page renders (canvas "Airy sidebar", variant A "Sheet", 2026-11-23).

## Goals

- A second sidebar design, `airy`, next to today's `boxed`.
- Picked in Settings directly under the magazine style; never in the sidebar footer's view controls.
- Stored with the account like the magazine style, so every device shows the same sidebar.

## Non-goals

- No change to `boxed`: today's sidebar stays pixel-identical.
- No coupling to the magazine style; the two are independent choices.
- No new rows, sections or behaviour; only how the existing sidebar is drawn.

## Approaches

1. **Account preference like `magazineStyle`** (picked): column on `user_reading_settings`, `PATCH` preferences, `/api/me`, backup line, frontend service with a `localStorage` paint cache. Same on every device, same shape as the setting beside it; costs a migration and a backup field.
2. Device-only (`localStorage`): frontend only, but set per device and unlike its neighbour in Settings.
3. Follow the magazine style: smallest, but the request asks for a separate choice.

## The look (as ruled)

- One sheet: light `--surface-2`, dark `--surface-read`; no cards, head bands or recessed boxes. Content width stays 316px; the column widens by the sheet's gutter (`--space-3` each side).
- Search row = the list header's 56px band, one rule under it at the header rule's height, inset like the other rules, in `--border`.
- Horizontal section rules faint: light `--border` 35% into the sheet, dark `--border`.
- Network heads: name as heading (primary text, semibold, `--fs-base`, logo `--icon-md`), rows indented `--space-5` on a hairline. "+" sits 6px closer to the name so "Mastodon" does not truncate.
- Followed stories, Saved searches, Tags, Feeds heads: heading only, rows flush. An open tag's children hang on a hairline; a nested open tag has none of its own.
- Current row: accent text plus a 3px inset accent bar, no fill. Hover keeps today's rounded fill.
- Phone drawer is the sheet, no rule under its title. Touch discs and footer controls keep fills and rims. Organise mode unchanged apart from the sheet.

## Constraints

- Section heads keep their coarse-pointer tap targets (the mock forced row height everywhere).
- Styles live in the sidebar's own `.scss` files, tokens only, no `!important`.
- Older backups without the field restore as `boxed`.

## Left to the builder

- How the `airy` class reaches the sidebar's child components (host class plus `:host-context`, or a class on the shell's `aside`).
- Whether the frontend style service shares code with `MagazineStyleService` or mirrors it.
