# One comment-body renderer (#2511, #2512)

## Intent

A link in a comment should be clickable, and it was only for some comment types, because every
comment source builds its body HTML its own way. Lars: unify those code paths first, so the
link fix — and every later body fix — lands once for all comment types.

## Today

| Source | Input | Path |
|---|---|---|
| `CommentsFeedSource` | HTML | `EntrySanitizer` |
| `Pass/LemmyThread` | Markdown | `MarkdownHtml` → `EntrySanitizer` |
| `Pass/SubstackThread` | plain text | `LinkedPlainText` (never sanitized) |
| `Social/Bluesky/BlueskyThreadReplies` | text + facets | `BlueskyRichText` + uncarded link → `EntrySanitizer` |
| `Social/Mastodon/MastodonContextReplies` | HTML + CW | `<details>` fold → `EntrySanitizer` |

Self-thread posts (`ThreadPost`) are built from the Mastodon/Bluesky reply models, so they follow.

## Goals

- One service, `Service/Content/Comments/CommentBodyRenderer`, turns every comment body into its
  HTML. Sources hand it a `Model/CommentBodyModel` that only says what the body is:
  `fromHtml()`, `fromMarkdown()`, `fromPlainText()`. It returns `?string` — null for a body that
  renders empty; each source keeps its current reaction to that (drop the comment, or `''`).
- Source-specific conversions stay in their source and hand over HTML: Bluesky facets and the
  uncarded link, the Mastodon CW fold.
- #2511 is a refactoring: the HTML each source produces stays as it is, except Substack bodies now
  pass the sanitizer like every other body (anchors gain `target`/`rel`).
- #2512, on top: the renderer links bare URLs in the text of every body (outside `a`, `code`,
  `pre`) before sanitizing, so the new anchors get `target="_blank"`/`rel` like the rest. The
  HTML-aware linker private to `Social/Share/Support/SharedLinks` moves to `Foundation/Html/Support`
  (beside `TextNodes`) and both use it. Plain text keeps `LinkedPlainText`; the shared step finds
  its URLs already linked and leaves them.

## Non-goals

- Frontend: comments and thread replies keep their markup; nothing to render there.
- No backfill of stored `ThreadPost` HTML; newly folded threads get links.
- Entry bodies, link-card descriptions and embed details keep their own paths.

## Approaches

1. **One renderer every source calls (picked).** Typed body in, HTML out; one place for format
   conversion, linking and sanitizing.
2. Sources keep converting; a shared pass over finished comments adds the common steps. Rejected:
   five paths stay five, and the pass must know which bodies are already escaped, sanitized or
   linked — the split being removed.

## Builder's calls

Names, the shape of the format inside the model (an enum in the module is fine), and how the
Pass objects (`LemmyThread`, `SubstackThread`) receive the renderer.
