# One ArticlePage shell for both article views (#1978) — design

Parent #1978, follows #1970. Three sequential PRs: #1982 (groundwork), #1983 (shell), #1984 (link reader
features). Brainstormed 2026-11-02; Lars took every recommendation.

## What

The reader view (`reader-view/`) and the link reader (`?link=`, `link-reader/`) are both article views but
each assembles its page by hand from the shared parts, so every article feature lands twice and the link
reader keeps getting forgotten. One `app-article-page` shell owns everything generic; the reader view
becomes "entry → page source + entry slots", the link reader becomes "link → page source".

## Decisions

1. **Shell with slots, not shared parts.** A component owns the layer bindings, skeleton order and gates
   once. Shared sub-components composed by two templates (rejected) would still wire the layer and the
   skeleton twice. An optional `entry` input on the shell (rejected) breaks "no generic article component
   takes an `EntryDto`".
2. **Providers sit on the outer view.** Content projected into the shell resolves its injector from the
   view that declares it, so comments, link cards and social counts could not see providers the shell
   declares. Each view calls `provideArticlePage({ source, autoplayRegion })` once; the shell injects
   what it provides.
3. **Two kinds of slot.** `ng-content` for parts that are rendered today regardless of loading; an
   `<ng-template>` slot, rendered with `ngTemplateOutlet`, for parts behind the loading gate — plain
   projection would create those components while the article loads. The reader view's `viewChild`
   queries still see template-slot content, since Angular tracks an embedded view at its declaration.
4. **Scroll positions key by page.** `entry:<id>` and `link:<url>`. The sessionStorage key changes, so
   positions remembered before the deploy are lost once; accepted.
5. **The link reader's embeds keep no listen action.** An audio track carries an entry id
   (`AudioTrackEntry`), which a link page has not; it passes no `EmbedTrackOrigin`.

## PR 1 — #1982: groundwork, no behaviour change

**Moves out of `reader-view/`**, specs along:

- `article-body.service.ts` and the helpers only it uses (`article-islands`, `article-chapters`,
  `article-embed-audio`, `article-embed-details`, `article-narration`, `article-player-bars`,
  `article-slideshows`, `article-unplayable-videos`) → `article/article-content/`, beside the component
  that provides `ArticleBody`.
- `article-gestures.service.ts` → `article/article-layer/`, its only consumer.
- `_article-page.scss`, `_article-toolbar.scss` → new `article/article-page/`.
- The `reader-view/article-islands.ts` path in `docs/design-language.md` follows.

**Entry leaks closed:**

- **Embed audio.** New `EmbedTrackOrigin` in `audio/` — `title`, `faviconUrl`, `imageUrl`,
  `entry: AudioTrackEntry` — built by `embedTrackOrigin(entry)`. `toEmbedTrack(origin, media, details)`,
  `EmbedListenContext.origin`, `ArticleBodyParts.origin` and `ArticleContentComponent.origin` replace
  `entry`.
- **Corner.** `ArticleCornerActionsComponent` loses the share disc and becomes `EntryCornerFlagsComponent`
  (keep, favourite) under `entry/`. Until PR 2 the reader view projects `<app-share-button
  appearance="corner">` and the flags into `articleCorner` side by side.
- **Scroll restore.** `ArticleScrollRestore.arm(key)`, `reseat(currentKey)`, `remember(key, top)` take a
  string page key; `ListScrollMemory.saveEntry/readEntry` become `saveArticle/readArticle(pageKey)`, stored
  under `feed-reader:article-scroll:<pageKey>`. `entryPageKey(id)` in `article/reading/article-page-key.ts`
  builds `entry:<id>`; #1984 adds `linkPageKey(url)` for `link:<url>`.

**Tests:** moved specs retargeted, a spec for the page-key helper; `npm run check` and `ng build`.

## PR 2 — #1983: the shell, no behaviour change in the reader view

**`ArticlePageSource`**, injected through `ARTICLE_PAGE_SOURCE`, signals unless noted:

| Member | Reader (`EntryArticlePage`) | Link (`LinkArticleSource`) |
|---|---|---|
| `key` | `entry:<id>` | `link:<url>` |
| `url` | entry url | original url (card, article or failure) |
| `title` | display title | card or article title |
| `titleHidden` | post or untitled | `false` |
| `nameplate` | display title | site name |
| `favicon` | favicon and poster avatar | `null` |
| `html` | `displayHtml` | `contentHtml` |
| `lead` | hero image | card image, only when failed |
| `paywall` | paywall url or `null` | article url when paywalled |
| `loading` | `source.loading` | `load.loading` |
| `canRefresh` | reader mode | article loaded or failed |
| `failure` | today's `fallbackNoticeShown` logic, message key by reason, quiet for `empty` | `reader.linkFailed`, offers the original |
| `socialPost` | entry is a social post | `false` |
| `bodyAudio` | enclosure url, chapters, track, `EmbedTrackOrigin` | `null` |
| `reload()` | `source.reload(id)` | `source.reload()` |

`failure` is `{ messageKey, quiet, detail, offersOriginal } | null`. `EntryArticlePage` lives in
`reader-view/` and adapts the entry plus `ArticleSource`, which keeps its entry shape.

**`provideArticlePage({ source, autoplayRegion })`** returns the source class, the token
(`useExisting`), `ReadingScope`, `ArticleSelection`, `ListAutoplayCoordinator`, `UnlockedVideoElements`,
`ArticleScrollRestore` and `AUTOPLAY_REGION`. The reader keeps `ArticleSource` and its comments region;
the link reader passes a region that covers nothing.

**`app-article-page`** in `article/article-page/`. Inputs `fullscreen`, `backLabel`,
`trailing: TrailingPart[]` (default `[]`); outputs `close`, `openOriginal`. It owns the progress rail,
`scope.connect` and `observeResizes`, `ArticleSelection.connect`; on a key change `restore.arm` and a fresh
collapsed TOC; after decoration TOC collection, `scope.refresh`, `restore.reseat`; on scroll
`restore.remember` unless the layer is leaving; `scrollToTop`, `scrollToHeading` and a public
`scrollToElement`.

Template, in order:

1. Layer mini and split-pane nameplate from `favicon`, `title`, `nameplate`.
2. Bar: `[pageBarLead]` (mode toggle), refresh while `canRefresh()`, share, `[pageBarFlags]`.
3. Title row; meta line, today's order: `[pageByline]` (source, author, time), reading time, open
   original (emits `openOriginal`), `[pageMetaTail]` (discussion).
4. `[pageHead]`: pills, actions, listen row, episode panels.
5. Table of contents.
6. Behind the loading gate: paywall; failure box (warning or quiet variant, inline retry, error detail,
   open-original button when `offersOriginal`); `<ng-template pageNotices>` (body-error banner); lead
   image; `app-article-content` with `html`, `url` and `bodyAudio`; `<ng-template pageAfterBody>` (post
   media, quoted post, link cards, thread, social counts).
7. `<ng-template pageFooter>`, not gated: categories, and comments, which the reader gates itself.
8. Dock: selection share. Loading overlay. Corner: share disc, `[pageCorner]` (flags).

**The views after PR 2.** The reader keeps the entry effect (`readerMode.reset`, autoplay sound,
`source.open`), comment count and forecast, `EntryAudio`, the trailing parts and the comments jump; its
template is only slots. The link reader keeps the back label.

**Holding gate.** The corner disc, TOC, reading time and scroll restore stay reader-only in PR 2, gated per
source, so nothing visible changes. PR 3 deletes the gate.

**Small change.** The link failure box gains the inline Retry the reader's already has.

**Risk.** Effect order across the two components on an entry change: the shell arms the restore, the reader
opens the source. A spec pins that a remembered position lands after a resume-reload.

**Tests:** reader-view and link-reader specs stay black-box over the DOM; a new `article-page` spec drives
the shell with a stub source; `npm run check` and `ng build`. Live check on :4200, desktop and iPhone:
resume-reload restore, autoplay in comments, gestures, body islands, TOC, selection bubble and phone bar,
mode toggle, refresh.

## PR 3 — #1984: the link reader's four features

The PR 2 holding gate goes; each feature then reaches both views.

- **Corner share disc** with the link's original url; the link reader projects no flags.
- **Table of contents** from the decorated link body; shown only when the page has headings.
- **Reading time** in the shell's meta line, after the byline (the site name), estimated from `html`.
- **Scroll restore** keyed `link:<url>`; `?link=` survives a resume-reload. Two cards linking the same page
  share one position.

**Done-when check:** no file under `article/` outside `reader-view/` imports `EntryDto`; each "Done when" of
#1978 checked against the code before the PR.

**Tests:** one link-reader spec per feature through the DOM (disc present, TOC from headings, reading-time
chip, restore after re-creating with a saved position); `npm run check` and `ng build`. Live check on
:4200 with a real link card, desktop and iPhone, back button included.
