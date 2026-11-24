# Classic theme fonts

`public/fonts/newsreader-classic-*.woff2` are Newsreader (OFL-1.1, licence in `public/fonts/OFL.txt`),
corrected so classic's text is as large as the system face's and sits where it does against the icons.
Safari supports neither `size-adjust` nor the `*-override` descriptors, so the correction is in the files.

## Rebuild

From `frontend/`, after `npm ci` (the only input is the dev dependency `@fontsource-variable/newsreader`):

```bash
python3 scripts/fonts/build-newsreader.py
```

Needs Python 3 with `fontTools` and `brotli`. It writes six files: Latin, Latin Extended and Vietnamese, each
roman and italic, with the package's own subsets. The output is deterministic: with `@fontsource-variable/newsreader` 5.3.0,
fontTools 4.65.0 and brotli 1.2.0 (the versions the shipped files were built with), an unchanged script
rebuilds byte-identical files; another version may compress differently.

The files are neither hashed nor versioned, so a browser's heuristic cache can serve a stale one after a
constant changes. The first time the constants change after a release, give the URLs a version: a `?v=` on
every URL in `theme/_fonts.scss`, on the preload in `index.html` and in `externalDependencies` in
`angular.json`, or new file names in all three and in `FACES`.

## The three constants

At the top of `build-newsreader.py`:

- `SIZE_MATCH`: the files' `unitsPerEm` is divided by it, so every glyph draws that much larger at the same
  CSS size. The target is an x-height equal to the system face's on a 15px line (about 0.526em). It is a
  constant scale, and Newsreader's x-height changes with optical size, so other sizes land near the system
  face's, not on it; the script prints 11, 13 and 24px beside it.
- `ASCENT`, `DESCENT`: the line metrics (hhea, OS/2 typo and win), as fractions of the CSS font size, line gap
  0 and `USE_TYPO_METRICS` on. They decide where the baseline sits in a line box, so where the x-height's
  centre sits against an icon centred beside it. Newsreader's own 0.735 / 0.265 puts it 1 to 3px high.

Variation deltas for the line metrics (MVAR) are dropped, the x-height's kept. The licence declares no Reserved
Font Name; if a new version does, the script stops, as the faces must then be renamed inside the files.

## Measure

With the Docker stack up and the dev server on `http://localhost:4200`:

```bash
node scripts/fonts/measure-metrics.mjs --browser chromium
node scripts/fonts/measure-metrics.mjs --browser webkit
```

Every API read is stubbed (`e2e/support/icon-rows.ts`); the script sets and removes `data-theme-name` itself.
For the tag pill, the vote pill, Reply, "Add comment" and a sidebar row at 100% text size it prints the text's
x-height centre minus the icon's centre under graphite and classic, then the x-height at 11, 13, 15 and 24px
in both. It exits non-zero when an offset difference exceeds 0.25px or classic's 15px line's x-height is more
than 0.2px from graphite's, and lists the font files each theme requested.

The fixtures are bundled with `esbuild`, which `@angular/build` brings in, not a dependency of its own.

Graphite's offsets come from Apple's system face: run it on macOS. Tune by changing a constant, rebuilding and measuring
again in both browsers; the line box rounds to whole pixels, so a step moves the result in jumps.
