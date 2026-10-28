# Image variant probing — design (#1855)

Decided in a grilling session on 2026-10-28. The objective: **find and save the best image variants for the
magazine planner.**

## Why

The planner fits a slot by the widest picture it knows of: `entryImage()` (`frontend/src/app/reader/list/preview-image.ts`)
lifts the lead to the widest rendition via `atLadderWidth`. Inside a slot, each block's `sizes`
(`rendition-sizes.ts`: full column 680 px, split 259 px, thumb 132 px) lets the browser download the smallest
rendition that covers it. Many entries carry one small or one huge file and no ladder:

| Source | What exists | Today |
|---|---|---|
| smarticular (feed 292) | `media:content` original 1200×630 in the feed | the feed never declares `xmlns:media`, so the 202×202 body crop wins |
| WordPress uploads | core sizes on disk (dev survey: 100 of 127 guesses exist) | not declared, not used |
| VIP / Photon / Sanity CDNs (20 WP feeds + Semafor) | any width via `?w=` | thumbs download 1600–6000 px originals |
| Deutschlandfunk | fixed set: square 512/768/1280/1920, 16:9 384/640/768/1280/1920 | not declared, not used |

Survey script and raw results: one-off, in the session scratchpad; the numbers above are what matters.

## Decisions

1. **One PR.**
2. **Parser:** standard prefixes a feed uses without declaring them (`media`, `itunes`, `content`, `dc`, `wfw`,
   `atom`, `rdf`, `podcast`, `psc`, `googleplay`) are bound to their canonical `FeedNamespace` URIs on the root
   element before parsing.
3. **Probing runs inside `ImageVerifier`**, after a lead is measured (`ImageVerifyOutcome::Measured` only). No new
   column, no migration; each image is verified once, so it is probed once. The sweep budget stays at 25 images /
   15 s per tick.
4. **Only the lead decides the verify outcome.** A failed probe (404, timeout, wrong width, refused) means no rung.
5. **Targets** — the only widths the list views need: **264 / 520 / 680 / 1100 / 1360** (thumb 132 @2×, split
   259 @1×/2×, full column 680 @1×/2×, 366 @3×). Nothing above 1360 is stored. Aspect ratio does not matter; the
   lead is never replaced.
6. **Coverage.** Each target owns a band up to the next target (`[264,520)`, `[520,680)`, `[680,1100)`,
   `[1100,1360)`, `[1360,2040)`). A band holding a known width — the lead, a feed-declared rendition, or a rung
   found earlier in the walk — needs no probe.
7. **Per entry, in order:**
   1. **Resizer check, on every host:** a ranged GET of the lead with `w=264` set (other query parameters kept).
      A measured width of 264 ± 1 marks an on-demand resizer: write a rung at every uncovered target up to the
      lead's measured width, as the lead URL with `w=<target>`, and stop. No further requests.
   2. **Size guessing** otherwise, for a lead whose filename carries `-WxH` (any host), or an unsuffixed lead under
      `/wp-content/uploads/`:
      - A suffixed lead first measures its stripped original (ranged GET). If it exists, the original's dimensions
        set the aspect and the upper bound, and the original becomes a rung when it is ≤ 1360. If not, the lead's
        own dimensions do.
      - The walk tries the common CMS widths **300 / 384 / 512 / 640 / 768 / 1024 / 1280** below the bound,
        ascending, skipping a width whose band is already covered; each is a HEAD of `<stem>-<w>x<round(h)>.<ext>`.
        200 with an `image/*` type counts, with the width taken from the filename.
      - A HEAD answered 403/405/501 falls back to a ranged GET.
8. **Guarded HTTP:** a new `GuardedImageProbe` (HEAD, ranged GET of the first 64 KiB) on `RedirectFollower`'s
   SSRF-guarded chain, 4 s timeout per request.
9. **No frontend change.**
10. **Backfill:** a throwaway script, not committed: every entry with a measured image, newest first, resumable by
    `(published_at, id)`, no per-host pacing. Rehearsed on the dev DB first (report rungs and requests per host);
    run on Strato only after Lars says the deploy is done, via the kernel-subclass one-off pattern.

## Refined while planning (beyond the grilling answers)

- **Q4b's Sanity guard is dropped.** "Skip guessing when the suffix equals the measured lead and no original
  exists" also matches Deutschlandfunk, whose `-1920x1920` is a real rendition with no original. Semafor never
  reaches guessing: it passes the resizer check first (7.1).
- **Unsuffixed WordPress originals are guessed too** (7.2). Without it smarticular, whose lead becomes the
  unsuffixed `media:content` original after the parser fix, would find no rung (its `768x403` exists); so would
  TechCrunch, The Nation and electronicgroove.
- **Resizer rungs stop at the lead's measured width**, the only width known without another request.
- **Probe requests time out after 4 s** (downloads keep 8 s), so a dead host cannot eat the sweep's 15 s.
