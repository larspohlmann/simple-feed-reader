# #1538 — YouTube URL rules get one home, the entry JSON ships a Short's player URL

**Goal:** every YouTube URL rule lives in one low-module helper; Discovery and Share stop importing
`Service\Reader`; the entry JSON carries a Short's player URL instead of `isShort`, so the SPA stops
re-parsing YouTube; the list's Short iframe takes its security attributes from the article's source.

## Rulings

- **Home:** `Service/Url/Support/YouTubeUrl` (static) parses a URL once into a
  `Service/Url/Model/YouTubeVideoModel` (id + whether it is a Short), which builds the nocookie player
  URL and the poster URL. It replaces `Reader/Media/Support/YouTubeVideoId` and `YouTubeShortUrl`.
- **One youtu.be rule:** hosts `youtu.be` and `www.youtu.be`, path `/<id>` with an optional trailing
  slash. The embed provider no longer reads `youtu.be/embed/<id>` or `youtu.be/v/<id>` (not spellings
  youtu.be serves); the playlist feed now accepts `www.youtu.be`. `?v=` is read on youtube.com only.
- **CoverAspectRatio** (one user) folds into `EntryJson`, derived from the Short player URL so the
  entry URL is parsed once.
- **API:** `isShort: bool` is replaced by `shortPlayerUrl: string|null` (the provider's normalised
  `…/embed/<id>#shorts`). The SPA treats a non-null value as "is a Short", checks it with
  `isAllowedEmbed()`, then inserts `autoplay=1`. The frame pattern drops `(?:\?autoplay=1)?`; the
  generated allow-list is regenerated.
- **Frontend iframe:** one `EMBED_FRAME_ATTRIBUTES` map in `media-embeds.ts`; `embedFrame()` applies
  it and a new `EmbedFrameDirective` declares the same map as static host attributes (Angular accepts
  `sandbox` only statically).
- **Reader cache:** bump `ReaderCacheService.VERSION` — the embed provider's youtu.be rule can change
  `/reader` output.

## Files

Backend: new `Service/Url/Support/YouTubeUrl.php`, `Service/Url/Model/YouTubeVideoModel.php` (+ tests);
edit `YouTubeEmbedProvider`, `YouTubeIdAttributeSource`, `YouTubeVideoChannelFeed`,
`YouTubePlaylistFeed`, `EntryShareClassifier`, `Http/EntryJson`; delete `YouTubeVideoId`,
`YouTubeShortUrl`, `CoverAspectRatio` and their tests.

Frontend: `models.ts`, `preview-image.ts`, `entry-row.component.html`, `short-embed.ts`,
`entry-media.component.{ts,html}`, `media-embeds.ts`, new `embed-frame.directive.ts`,
`embed-frame-allowlist.generated.json`, `reader-cache.service.ts`, specs and e2e fixtures.

## Tasks

1. Tests for `YouTubeUrl` (moved from the two deleted tests + youtu.be cases), then the helper/model.
2. Switch every caller; drop the Reader imports from Discovery and Share; fold CoverAspectRatio.
3. `EntryJson` ships `shortPlayerUrl`; frame pattern loses autoplay; regenerate the allow-list.
4. SPA: `shortPlayerUrl` field, `short-embed.ts` without regex, directive for the iframe attributes,
   cache VERSION bump.
5. Gates, simplify, PR with the §6 checklist.
