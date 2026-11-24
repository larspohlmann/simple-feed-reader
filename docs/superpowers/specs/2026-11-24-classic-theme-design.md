# Classic theme (#2524)

## Intent

A second theme next to graphite for readers who want the feel of a printed newspaper: warmer paper, serif type, still a modern app. The look was agreed on the running app with a throwaway overlay (2026-11-24); this document records what was picked there.

## Goals

- A theme `classic`, in light and dark, next to today's `graphite`.
- Off by default. Picked in Settings → Preferences, stored with the account, the same on every device.
- Serif type on everything: headlines, summaries, article body, sidebar, list header, buttons, settings, dialogs.
- In every theme: an icon that sits with text following the text-size control scales with that text.

## Non-goals

- No layout change. The boxed and airy magazine and sidebar styles work under classic as they are.
- No squared or flat shapes: classic keeps today's radii and the glass rim.
- The sidebar still ignores the text-size control.
- Light, dark and system stay a per-device choice in the sidebar footer; classic does not touch it.
- No second preference for typeface alone, and no new screenshots for the docs or the homepage.

## Anti-goals

- Graphite changes. With classic off, every page renders as it does today (the icon scaling below is the one intended difference, and only at a text size other than 100%).
- Text riding high. Newsreader's own metrics lift text about 2px above the icons in pills and buttons; shipping that is a failure even if everything else is right.
- A first frame in graphite colours on reload for an account that picked classic.

## Approaches

1. **Second palette on the existing tokens** (picked): a palette file beside `_graphite.scss`, one more attribute on `<html>`, a theme-owned font token, an account preference like `sidebarStyle`. Smallest change; the registry already anticipates it.
2. A separate stylesheet loaded on demand: saves a few kilobytes of CSS, costs a loader, its own first-paint handling and a second home for tokens. The font is lazy either way.
3. Palette and typeface as two preferences: more combinations for the reader, twice the combinations to keep right across both modes and every brightness step. The tokens in 1 leave this open for later.

## The look (as ruled)

Palette, light / dark:

| Token | Light | Dark |
|---|---|---|
| `surface-0` | `#f2ecdd` | `#161511` |
| `surface-1` | `#faf6ea` | `#1d1b16` |
| `surface-2` | `#fefbf2` | `#25231d` |
| `surface-read` | `#faf6ea` | `#1f1d18` |
| `surface-sunken` | `#eee7d6` | `#17160f` |
| `border` | `#e4dcc9` | `#2c2a22` |
| `border-strong` | `#d0c7b0` | `#3b382e` |
| `text-primary` | `#26231d` | `#cbc6b6` |
| `text-secondary` | `#5c574b` | `#9e9988` |
| `text-muted` | `#8e8878` | `#6d695b` |
| `accent` | `#2f6f62` | `#6aa998` |
| `on-accent` | `#fff` | `#0f1a17` |
| `accent-soft` | accent 10% into `surface-1` | accent 18% into `surface-1` |
| `favicon-backdrop` | `transparent` | `#e8e4da` |

The danger, success and warning colours were graphite's in the round and are not ruled.

Type:

- One family for the whole app: Newsreader (variable; optical size, weights 400–700, roman and italic), falling back to Georgia, then `serif`. Icons keep Material Symbols.
- Apparent size equals graphite's: Newsreader's x-height is matched to the system face (the rebuilt files measure 7.90px on a 15px line in Chromium and 7.89px in WebKit, as the system face does, about 0.526em; the overlay's `font-size-adjust: 0.5` drew it about 5% smaller).
- Entry and article titles (not post text): weight 600, letter-spacing `-0.006em`, line-height 1.2.
- Summaries and deks: line-height 1.45. Article body: line-height 1.62; headings inside it weight 650.
- The app name in the header: weight 700, 1.2em.
- Sidebar counts: lining tabular figures, so they still align in a column.

## Constraints

- **Vertical metrics.** Text must sit where graphite's sits. Measured as the text's x-height centre against the icon centre in the same control, classic stays within 0.25px of graphite in: an entry's tag pill, the vote pill, the Reply and "Add comment" buttons, a sidebar row. Uncorrected Newsreader is 2px off. The overlay got there with `ascent-override: 88%; descent-override: 20%; line-gap-override: 0%`, but Safari supports none of the three, so the correction lives in the shipped font file.
- **Self-hosted font.** No request to a third party. Fetched only when classic is on. The licence file ships beside the font; if the licence reserves the font's name, the corrected file takes another family name.
- **Scripts Newsreader lacks** (Cyrillic, Greek and others) fall back to the system serif, not to the sans.
- **Brightness dial.** Classic gets every brightness step, derived from its two maps the way graphite's are.
- **First paint.** The inline script in `index.html` applies the cached theme before the first render, as it does for mode, brightness and text size. Sign-out clears the cache, so the next account does not inherit it.
- **Account preference.** A plain string in `/api/me` preferences and in the preferences `PATCH`, `graphite` by default; nothing browser-only (architecture §6). Included in the backup; an older backup restores as `graphite`.
- **Preferences page.** One row, "Theme", with the options Graphite and Classic, beside the magazine and sidebar style rows and using the same control. All four languages.
- **Tokens only.** Classic is expressed through tokens and the theme files; no component learns the theme's name, and no `!important`.
- **Sign-off on real renders**, light and dark, desktop and phone, before the PR: magazine list, article with comments, sidebar, phone drawer, Settings, a dialog and a menu.

## Icons follow the text size (every theme)

Rule: inside a container that follows the text-size control, a row of controls scales as one. When any text in the row scales, every icon in that row scales, icon-only buttons included (a comment's delete button beside Reply, the comments' refresh button); a row whose text all stays fixed keeps fixed icons. This is how the entry's meta row behaves today (#2457). "Icon" covers Material icons, tag glyphs, network logos and favicons set inline with text. Avatars, pictures and spacing do not scale.

Measured on the list and an article with comments at 100% against 150%, these have scaling text beside a fixed icon today:

- the source favicon in an entry's kicker line and in the article's source line (`app-entry-source`)
- the "Also in" foot icon (`app-entry-duplicates`)
- the Reply and "Add comment" icon (`app-social-counts-row`)
- the vote arrows in comments (`app-vote-pill`; the ones in the list's meta row already scale)

The same measurement runs over the other scaled surfaces (notifications, link reader, thread replies, the duplicates popover) before the work is called done. A check in `npm run check` fails when a row ships with scaling text and a fixed icon. `docs/design-language.md` ("Text-size control") is updated: it says today that icons never scale.

## Calls made without a ruling

- The preference is per account, like `sidebarStyle`, not per device.
- Names: account field `theme` with `graphite` | `classic`; the mode keeps `data-theme` and `sfr.theme`, so the new attribute is `data-theme-name` and the paint cache `sfr.themeName`.
- Apple's system face (macOS, iOS) is the reference for the metrics and x-height match, since the round ran there. Windows and Android get the same font file; there the bar is "no text visibly off its icon", checked by eye once.
- The 0.25px bound holds at 100% text size and is measured once per browser for sign-off, with the numbers in the PR. Other text sizes are looked at, not measured; it is not a standing check.
- Monospace spots stay monospace. Digest emails are untouched. Pages before sign-in render graphite.
- The brightness step stays stored per mode and is shared by both themes.
- Favicons count as icons for the scaling rule.
- Icon scaling ships under this issue as its own commits, before the theme.
- `--font-voice` (the magazine quote block) is defined nowhere and does nothing today; the dead declaration and its sentence in the design language go, so graphite stays as it renders now.

## Left to the builder

- How the font file is corrected (a script kept in the repo so the files can be rebuilt) and how the size match is done (in the file, `size-adjust`, or `font-size-adjust`), as long as the constraints hold in Safari, Chrome and Firefox.
- Which subsets ship, and whether the roman file is preloaded when classic is cached.
- How the family token is named once it no longer means "sans", and which further type tokens the title and line-height rules need.
- Whether classic warms the danger, success and warning colours; a change there is shown before it ships.
- The form of the icon-scaling check (a coverage spec like `glass-rim-coverage.spec.ts`, or a measured one).
- Whether the theme service shares code with `SidebarStyleService` or mirrors it.
