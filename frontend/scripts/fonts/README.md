# Classic theme fonts

`public/fonts/newsreader-classic-*.woff2` are Newsreader (OFL-1.1, licence in `public/fonts/OFL.txt`),
corrected so classic's text is as large as the system face's and sits where it does against the icons.
Safari supports neither `size-adjust` nor the `*-override` descriptors, so the correction is in the files.

## Rebuild

From `frontend/`, after `npm ci` (the only input is the dev dependency `@fontsource-variable/newsreader`):

```bash
python3 scripts/fonts/build-newsreader.py
```

Needs Python 3 with `fontTools` and `brotli`. The output is deterministic: an unchanged script rebuilds
byte-identical files.

## The three constants

At the top of `build-newsreader.py`:

- `SIZE_MATCH`: the files' `unitsPerEm` is divided by it, so every glyph draws that much larger at the same
  CSS size. Tuned until a 15px line's x-height equals the system face's.
- `ASCENT`, `DESCENT`: the line metrics (hhea, OS/2 typo and win), as fractions of the CSS font size, line gap
  0 and `USE_TYPO_METRICS` on. They decide where the baseline sits in a line box, so where the x-height's
  centre sits against an icon centred beside it. Newsreader's own 0.735 / 0.265 puts it 1 to 3px high.

Variation deltas for the line metrics (MVAR) are dropped, the x-height's kept. The family is renamed inside
the files only if the licence declares a Reserved Font Name; it declares none.

## Measure

With the Docker stack up and the dev server on `http://localhost:4200`:

```bash
node scripts/fonts/measure-metrics.mjs --browser chromium
node scripts/fonts/measure-metrics.mjs --browser webkit
```

Every API read is stubbed (`e2e/support/icon-rows.ts`); the script sets and removes `data-theme-name` itself.
For the tag pill, the vote pill, Reply, "Add comment" and a sidebar row at 100% text size it prints the text's
x-height centre minus the icon's centre under graphite and classic, and the x-height of a 15px line in each.
It exits non-zero when a difference exceeds 0.25px or the x-heights differ by more than 0.2px, and lists the
font files each theme requested.

`--graphite-only --styles <file.json>` skips classic and writes the computed type of the elements classic
converts, for a before/after diff of graphite.

Apple's system face is the reference: run it on macOS. Tune by changing a constant, rebuilding and measuring
again in both browsers; the line box rounds to whole pixels, so a step moves the result in jumps.
