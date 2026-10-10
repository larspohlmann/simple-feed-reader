# #1507 — A page video yields to the body's own player Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The reader stops inserting a page-level video (JSON-LD, attributes, player configs) when the cleaned article body already carries a playable `<video controls>`, so VICE's ProRes master no longer leads the article ahead of the body's own H.264 player.

**Architecture:** `BodyCleaningPass::discoveredMedia()` already drops embed candidates once the body recovered its own embeds (`withoutEmbeds`). The same method also drops video and stream candidates (`MediaKind::isVideo()`) when the pass's document holds a `video[controls]` with a `src` or a `source[src]`. `PageMediaPlacement` is the only consumer, so nothing else changes. `ArticleMediaModel` gains `withoutVideos()`; its three filters share one private `keeping()`.

**Tech Stack:** Symfony 7.4, PHP 8.4 `Dom\HTMLDocument`, PHPUnit 12.

**Spec:** GitHub issue #1507. Research, 2026-10-10:
- **Fixtures** (51 under `tests/Fixtures/reader/`): 5 name a JSON-LD video file, and none of them also has a body `<video>`. No `.mov` anywhere. So no measured page loses a video.
- **Mechanics:** `AttributeMediaSource` rediscovers the body's own `<video src>`. Suppressing only the `.mov` would make the body mp4 the lead and duplicate it, so the rule has to be "the body already plays a video", not "QuickTime is bad".

## Global Constraints

- Host-agnostic: no host list, no extension rule.
- `controls` decides that a body video is a player. A muted, looping decorative video (no `controls`) does not suppress a lead video.
- A body that lost its video to readability (`article-inline-video.html`) keeps today's behaviour: the page candidate restores it.
- Any reader-output change bumps `ReaderCacheService.VERSION` (`frontend/src/app/reader/article/content/reader-cache.service.ts`, now 28 → 29).
- PHP gates: `composer check`, `composer md`, `composer test:parallel`, `docker compose exec php composer test`, `composer infection:diff`.

---

### Task 1: `discoveredMedia()` drops page videos when the body plays one

**Files:**
- Modify: `backend/src/Service/Reader/Media/Model/ArticleMediaModel.php` (`withoutVideos()`, shared `keeping()`)
- Modify: `backend/src/Service/Reader/BodyCleaning/Pass/BodyCleaningPass.php`
- Test: `backend/tests/Service/Reader/BodyCleaning/Pass/BodyCleaningPassTest.php`
- Create: `backend/tests/Fixtures/reader/media/jsonld-master-beside-body-video.html` (VICE shape: a JSON-LD `VideoObject` whose `contentUrl` is a `.mov`, and a WordPress `figure.wp-block-video` with `<video controls src=….mp4>` in the body)
- Test: `backend/tests/Service/Reader/ArticleExtractor/ArticleExtractorTest.php`
- Modify: `frontend/src/app/reader/article/content/reader-cache.service.ts` (VERSION 29)

- [ ] **Step 1: Failing unit tests** (`BodyCleaningPassTest`):
  - A body with `<video controls src>` drops Video and Stream candidates and keeps Audio and Embed.
  - The same with `<video controls><source src></video>`.
  - A `<video autoplay loop muted src>` (no controls) keeps them.
- [ ] **Step 2: Failing extractor test:** the fixture yields exactly one `<video` in `contentHtml`, the body's `.mp4`, and no `.mov`.
- [ ] **Step 3: Run both, expect FAIL:** `php bin/phpunit tests/Service/Reader/BodyCleaning/Pass/BodyCleaningPassTest.php tests/Service/Reader/ArticleExtractor/ArticleExtractorTest.php --filter 'Video|Discovered'`
- [ ] **Step 4: Implement** `withoutVideos()` and the `discoveredMedia()` rule.
- [ ] **Step 5: Run, expect PASS**, plus the whole `tests/Service/Reader` directory (`article-inline-video.html` must still restore its player).
- [ ] **Step 6: Bump `ReaderCacheService.VERSION` to 29.**
- [ ] **Step 7: Gates** (see Global Constraints), and a live check: entry 496167 through `/api/entries/496167/reader` shows only the body mp4.
- [ ] **Step 8: Commit** `fix(#1507): a page video yields when the body already plays one`.
