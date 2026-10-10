# Embed Shape From Providers Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** The player box shape (landscape / tall / portrait) comes from the backend embed providers through the generated allow-list, so `media-embeds.ts` drops its `SPOTIFY_COLLECTION` and `#shorts` regexes (#1481).

**Architecture:** The shape depends on the URL, not only the provider (a Spotify track is landscape, a playlist tall; a YouTube video is landscape, a Short portrait), so a provider declares a list of frames instead of one pattern plus one kind. `EmbedProviderInterface::framePattern()` and `kind()` become `frames(): list<EmbedFrameModel>`, each frame a disjoint anchored pattern with its `EmbedKind` and a new `EmbedShape`. The allow-list gains a `shape` field per entry; the client takes the first matching entry and maps the shape to the existing `reader-embed--tall` / `--portrait` classes, so CSS and `reader-cinema.ts` stay as they are. `kind` and `shape` stay two fields: they are orthogonal (an audio track is landscape, a video Short portrait).

**Tech Stack:** Symfony 7.4 / PHP 8.4, PHPUnit 12; Angular 20, Jest.

## Global Constraints

- Branch `feature/1481-embed-shape-from-providers`; commits `type(#1481): lower-case summary`.
- Frame patterns of all providers are pairwise disjoint: every normalised URL matches exactly one entry (asserted in `EmbedFrameAllowlistTest`).
- `EmbedShape` lives in `Service/Reader/Media/Model/` beside `EmbedKind`; `EmbedFrameModel` is `final readonly` there too and is JSON-encoded as is (public fields, backed enums).
- Regenerate the client file with `bin/console app:embed:dump-frame-allowlist`.
- Gates: `composer check`, `composer md` on touched files, `php bin/phpunit`, `composer infection:diff`; frontend `docker compose exec -T frontend npm run check`.

---

### Task 1: Providers declare frames with a shape (backend)

**Files:**
- Create: `backend/src/Service/Reader/Media/Model/EmbedShape.php` (`Landscape = 'landscape'`, `Tall = 'tall'`, `Portrait = 'portrait'`)
- Create: `backend/src/Service/Reader/Media/Model/EmbedFrameModel.php` (`pattern`, `kind`, `shape`; `matches(string $url): bool`)
- Modify: `EmbedProviderInterface` — replace `framePattern()` + `kind()` with `frames()`
- Modify: all six providers. Spotify: `track|episode` → landscape, `playlist|album|artist|show` → tall. YouTube: plain id → landscape, id + `#shorts` → portrait. Others: one landscape frame.
- Modify: `EmbedProviders::framePatterns()` / `allowlistJson()` read `frames()`, sorted by pattern.
- Modify: `DumpEmbedFrameAllowlistCommand` docblock (`frames()`).
- Tests: each provider test's `kind()` assertion becomes a `frames()` assertion (kind + shape); YouTube's frame-pattern test asserts the plain and the Short URL each match their own frame only; `EmbedFrameAllowlistTest` checks every committed entry has a shape in the enum, every source URL matches exactly one pattern, and the playlist / Short URLs resolve to tall / portrait.

- [ ] Write the failing tests, run them, see them fail.
- [ ] Implement, regenerate the allow-list, run `php bin/phpunit tests/Service/Reader/Media`.
- [ ] Commit `feat(#1481): embed providers declare the frame shape`.

### Task 2: Client reads the shape (frontend)

**Files:**
- Modify: `frontend/src/app/reader/media-embeds.ts` — `EmbedFrame` gains `shape: 'landscape' | 'tall' | 'portrait'`; `boxClass(frame)` maps shape → modifier; delete `SPOTIFY_COLLECTION`, `SHORTS_FRAGMENT` and their comment; doc comment mentions the shape.
- Test: `frontend/src/app/reader/media-embeds.spec.ts` — the existing tall / portrait / landscape cases stay and must stay green (they now pass through the generated data).

- [ ] Change the code, run `docker compose exec -T frontend npm test -- src/app/reader/media-embeds.spec.ts src/app/reader/article/decorators/reader-cinema.spec.ts`.
- [ ] Break-test: temporarily set the Spotify collection entry's shape to `landscape` in the JSON, see the tall test fail, restore.
- [ ] `npm run check` in the container; commit `refactor(#1481): reader takes the embed shape from the allow-list`.

### Task 3: Gates and PR

- [ ] Backend gates (both test legs), frontend check, scan the dev log.
- [ ] Push, open the PR against `develop` with `Closes #1481`.
