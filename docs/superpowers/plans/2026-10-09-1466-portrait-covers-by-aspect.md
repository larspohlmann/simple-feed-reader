# #1466 Portrait Covers by Their Real Aspect Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** List and magazine layouts route and crop a cover by the shape of the picture it shows, not by the YouTube-specific `isShort`; `isShort` stays only as the badge/pill marker.

**Architecture:** The API gains `imageAspectRatio: number|null`, the shown picture's width / height when the image file letterboxes it (a YouTube Short's 480×360 thumbnail holds a 9:16 cover between bars → `0.5625`), else null. `imageWidth`/`imageHeight` keep describing the file, because the renditions and `entryImage()`'s ladder scaling are about the file. The frontend derives one shape from it (`coverAspectRatio(entry)`: the API's ratio, else width/height, else null) and everything orientation-dependent reads that: the planner's portrait rule (which already keeps portraits out of hero/wide, so `notAShort` goes), split's box, and the `.portrait` frame of thumb, split and the list row.

**Decision (Lars, 2026-10-09): portraits keep #1461's cap** — a portrait cover in split is as tall as the 3:2 box a landscape image gets (the existing `cqi` rule, now keyed on orientation), not the 3:4 box non-Short portraits used to get. Thumb and list row show any portrait at its own shape, 66px tall, never narrower than 9:16.

**Tech Stack:** Symfony 7.4 / PHP 8.4, Angular 20, Jest in the Docker frontend container.

**Spec:** GitHub issue #1466.

## Global Constraints

- Native iOS viability: the field is a plain number, no CSS syntax in the API.
- CLAUDE.md Clean Code / gates (backend `composer check`, `composer md`, `infection:diff`; frontend `npm run check` in the container).
- Kept on purpose: the `cqi` Stylelint allowance (split's height cap still needs it).

---

### Task 1: Backend — `imageAspectRatio`

**Files:**
- Create: `backend/src/Service/Reader/Media/Support/CoverAspectRatio.php`
- Test: `backend/tests/Service/Reader/Media/Support/CoverAspectRatioTest.php`
- Modify: `backend/src/Http/EntryJson.php` (three array-shape docblocks + the field)
- Test: `backend/tests/Http/EntryJsonTest.php`

**Interfaces:** Produces `CoverAspectRatio::of(?string $entryUrl): ?float` and JSON `imageAspectRatio`.

- [ ] Failing tests: `of('https://www.youtube.com/shorts/87Ov3cS-xMs')` = `9 / 16`; a watch URL, a non-YouTube URL and null → null. EntryJsonTest: a Short entry serialises `imageAspectRatio` 0.5625, another entry null.
- [ ] Implement:

```php
/** The shape of the picture an entry's cover shows, when the image file letterboxes it. */
final class CoverAspectRatio
{
    private const float PORTRAIT_VIDEO = 9 / 16;

    public static function of(?string $entryUrl): ?float
    {
        return YouTubeShortUrl::is($entryUrl) ? self::PORTRAIT_VIDEO : null;
    }

    private function __construct()
    {
    }
}
```

`EntryJson`: `'imageAspectRatio' => CoverAspectRatio::of($entry->getUrl()),` after `imageHeight`; add `imageAspectRatio: float|null` to the shapes.
- [ ] Gates, commit `feat(#1466): state a letterboxed cover's real aspect in the entry json`.

### Task 2: Frontend — route and crop by the cover's aspect

**Files:**
- Modify: `frontend/src/app/reader/models.ts` (`EntryDto.imageAspectRatio: number | null`), and every fixture that spells `isShort: false` (22 files: add `imageAspectRatio: null` after it).
- Modify: `frontend/src/app/reader/list/preview-image.ts` — add `coverAspectRatio(entry)`, `isPortraitCover(entry)` (ratio < 1 / 1.05, the planner's margin), `portraitCoverAspect(entry): string | null` (`String(Math.max(ratio, 9 / 16))` for a portrait, else null).
- Modify: `magazine/magazine-slot-fit.ts` — `landscapeImageAtLeast` uses `isPortraitCover(entry)`; delete `isPortrait(image)`, `notAShort`; hero/wide comments say portraits.
- Modify: `magazine/entry-image-block-base.ts` — `readonly portraitAspect = computed(() => portraitCoverAspect(this.entry()))`.
- Modify: `entry-row/entry-row.component.ts` — the same member.
- Modify templates `entry-thumb`, `entry-split`, `entry-row`: `[class.portrait]="portraitAspect() !== null"`; thumb and row bind `[style.aspect-ratio]="portraitAspect()"` on the img.
- Modify: `entry-split.component.ts` `aspect()`: `this.portraitAspect() ?? <existing landscape clamp>` (drop the `isShort` branch).
- Modify SCSS: thumb/row `.img-frame.portrait .img|.thumb { width: auto; }` (no mixin); split keeps `.top:has(…)`, `.img-frame.portrait`, and `.img-frame.portrait .img { width: auto; height: calc(100cqi * #{$side-share} * 2 / 3); }`; delete `_short-cover.scss` and the three `@use`.
- Tests: `src/testing/short-marking-testing.ts` → `describeShortPortrait` becomes `describePortraitCover`: a Short with `imageAspectRatio: 9 / 16` gets the portrait frame; a non-Short `900×1600` image gets it too; a landscape image does not. Planner spec: the two Short hero/wide tests use `imageAspectRatio: 9 / 16` on a 1280×720 file and still pass; add `routes a letterboxed portrait cover out of hero and wide without isShort` (isShort false, ratio 9/16).
- Docs: `docs/design-language.md` §5 rows for Hero/Wide/Split/Thumb say "portrait cover" (by `imageAspectRatio` or the declared dimensions) instead of "YouTube Short".
- [ ] Failing tests first (container Jest), implement, `npm run check`, real render of `:4200/?subscription=1712` (Shorts still split/thumb portrait, landscape unchanged) plus one real portrait entry from the dev DB, commit `refactor(#1466): route and crop portrait covers by their real aspect, not by isShort`.
