# #1463 Magazine: an Image Entry Never Renders Text-Only — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** An entry whose image the view treats as a real picture is never planned into a text-only block (`quote`, `kicker`, `compact`); the text slot it lands in is promoted to the tallest image block the entry fits that is no taller than the slot.

**Root cause (confirmed on the dev stack):** subscription 1712 (ScreenCrush) has 15 entries, all 480×360, none with a summary. Entry 575750 is index 10 → page 1 → `IMAGE_TEMPLATES[6]` (`['hero','split','thumb','thumb','kicker']`), position 4 = the authored `kicker` slot, which `demoteUntilFit` turns into `compact` (no summary). The DOM shows `app-entry-compact`. Nothing in `settle()` ever promotes.

**Decision (Lars, 2026-10-09): both families.** The promotion bar is per family, because the text family exists for wire services that ship a ~90px miniature on every entry (`renders a text-forward rhythm for an image-poor, text-rich view`): promoting those would turn every quote into a thumb, the uniform wall the family was built to avoid.
- Image family: any usable image (`fits('thumb')`) promotes.
- Text family: a real picture (`fits('split')`, ≥ 300px or persisted-unknown) promotes.

**Architecture:** `PlanPass.templates` becomes `PlanPass.family: MagazineFamily = { templates, imageBar: EntryKind }` (`IMAGE_FAMILY` / `TEXT_FAMILY`). `layOutPage` → `assign` → `settle` receive the family's `imageBar`. `settle` promotes after demotion: when the settled kind shows no image and `fits(imageBar, entry)`, return `promotedToImage(slotKind, entry)` = the first of `IMAGE_KINDS` (`hero, wide, split, thumb`, tallest first) with `BLOCK_HEIGHT ≤ BLOCK_HEIGHT[slotKind]` that fits, else `thumb` (only a `compact` slot, 66 → 90). Page height never grows except compact → thumb.

**Tech Stack:** Angular 20, Jest (inside the Docker frontend container).

**Spec:** GitHub issue #1463.

## Global Constraints

- Prefix stability, the existing planner spec and every block kind's tests stay green.
- `docs/design-language.md` §5 documents the promotion rule.
- Frontend tests run in the Docker container (`docker compose exec -T frontend …`), one Jest process at a time.

---

### Task 1: Promote image entries out of text slots

**Files:**
- Modify: `frontend/src/app/reader/list/magazine/magazine-planner.ts`
- Test: `frontend/src/app/reader/list/magazine/magazine-planner.spec.ts`
- Modify: `docs/design-language.md` §5

- [ ] **Step 1: Failing tests** in `magazine-planner.spec.ts`:
  - `never plans an entry with an image into a text block in the image family` — the ScreenCrush shape: `many(15, (index) => entryAt(index, { imageUrl, imageWidth: 480, imageHeight: 360, summary: null, isShort: index === 11 }))`; every block's kind is one of `hero/wide/split/thumb`.
  - `promotes a text slot to the tallest image block no taller than the slot` — 17 `big()` entries (long-enough summaries for quote): page 2 is `IMAGE_TEMPLATES[11]` (`hero,thumb,split,thumb,quote,kicker`), so `blocks[15].kind === 'split'` (quote 180 → split 150) and `blocks[16].kind === 'thumb'` (kicker 140 → thumb 90).
  - `shows a real picture in the text family rather than a text block` — 80 image-less entries with a 400-char summary (text-rich) where every 8th is `big()` (12.5% < IMAGE_POOR_SHARE → text family); every `big` entry's block kind is an image kind, and the plan still contains `quote`.
- [ ] **Step 2:** run them (`docker compose exec -T frontend npx jest src/app/reader/list/magazine/magazine-planner.spec.ts`) → FAIL.
- [ ] **Step 3: Implement** as in Architecture. `IMAGE_KINDS` is planner-local. Keep `hasSummaryButNoImage` (a text-family miniature-image entry still stays `compact`/`kicker` as today).
- [ ] **Step 4:** rerun the spec → PASS, including the wire-service rhythm test unchanged.
- [ ] **Step 5: Docs.** In §5 after the demotion paragraph: an entry whose image the view counts as a picture is never left in a text block; the slot promotes to the tallest image block no taller than itself (quote → split or thumb, kicker → thumb, compact → thumb). The image family counts any usable image; the text family only one that fills `split`, so a wire service's miniatures keep the pull-quote rhythm.
- [ ] **Step 6: Gate** `docker compose exec -T frontend npm run check`.
- [ ] **Step 7: Real render.** Reload `http://localhost:4200/?subscription=1712` (container current) and confirm entry 575750 renders as `app-entry-thumb`, and no `app-entry-compact|kicker|quote` remains on that page.
- [ ] **Step 8: Commit** `fix(#1463): promote an image entry out of a text slot instead of hiding its image`.
