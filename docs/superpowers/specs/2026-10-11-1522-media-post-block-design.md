# #1522 — A bsky-style magazine block for image and video posts

Image-only and video posts look nothing like their source in the magazine. A text-less Bluesky image post
(`@miaganga.bsky.social`, dev subscription 1739) gets an article block headed "(untitled)". Depending on the
slot, its picture is cropped, put in a split column beside empty space, or shrunk to a 71px thumbnail. Video
posts (dev subscription 1740: Bluesky videos with a one-line caption) cannot play in the list. This spec
adds one block for posts whose point is their media, shaped like bsky.app.

## Decisions (agreed 2026-10-11)

| Question | Decision |
|---|---|
| Which posts | Any post with at least one image or video, captioned or not. A post is an entry whose title was derived from its text, or a text-less untitled post. Link posts keep the `link` block. |
| Several images | Bluesky-style grid: 2 side by side, 3 as one large plus two stacked, 4 as 2×2 |
| Video | Plays inline in the list, on click. No autoplay. |

## Measured

- Dev 1739: 7 text-less Bluesky image posts. `title` is `(untitled)` and `titleDerived` is false. `media` holds
  one image with its declared width and height.
- Dev 1740: Bluesky video posts with a caption, `titleDerived` true. `media` is
  `[thumbnail image, video(playlist.m3u8, previewImageUrl = the same thumbnail)]`, both with dimensions.
- Prod: 31 Bluesky entries, all title-derived, and no text-less Bluesky post. No backfill is needed.
- `hls.js` is already a dependency. `reader/article/decorators/hls-streams.ts` attaches it lazily on a
  `<video>`'s first play.

## Design

### 1. Backend: a text-less item is an untitled post

`EntryTitle::of` returns `ParsedTitleModel::untitled()` (not derived) when an item has neither a feed
title nor text. A Bluesky or Mastodon item never has a feed title, so the text-less post loses its post
shape. A title-less, text-less item is now `untitledPost()`, which is derived. `BlueskyEntryRule` then
treats it as a post as it already does, and `ParsedTitleModel::untitled()` goes away. An item that carries
a feed title is unaffected.

### 2. Frontend: which entries are media posts

In `magazine-slot-fit.ts`:

- `postMedia(entry)` returns the post's visual media, at most four, in feed order.
  - Videos are kept.
  - An image whose URL is a video's `previewImageUrl` is dropped, so the poster does not show twice.
  - When `media` is empty, the entry's lead image (`entryImage`) stands in as a single image.
- `isMediaPost(entry)` is true for a post (`titleDerived`) that is not a link post and has at least one item
  in `postMedia`.
- `postKind(entry)` returns `'link' | 'media' | null`: the block a post claims whatever slot it gets.
  - It replaces `isLinkPost` at the planner's three pinning sites: the wanted kinds, the look-ahead that never
    reaches past a pinned post, and `withinBudget`'s exclusion of pinned kinds.
  - It also replaces `isLinkPost` as `settle`'s first line.

In `magazine-block.ts`:

- `media` joins `ENTRY_KINDS` next to `link`.
- `BLOCK_HEIGHT.media` is 463, as for `hero` and `link`.
- `DEMOTION.media` is `media`.
- `FITS.media` is `isMediaPost`.

### 3. The block, `blocks/entry-media`

The block reuses the link block's card chrome.

1. `<app-entry-kicker-line>`.
2. The caption: the post's text (`excerpt`), clamped to six lines in the `post-text` face.
   - It is omitted for an untitled post. Its title is the `(untitled)` placeholder, and the excerpt of a
     text-less video post would otherwise be the embed's "Watch on Bluesky" line.
   - The API sends an untitled entry's `title` as `''`, so no surface shows the placeholder.
3. The media grid, `postMedia` laid out by count.
   - **One item** keeps its own aspect ratio, from the declared dimensions, falling back to 16/9. It is
     height-capped by a new `--media-post-max-h` token and centred in the card (the user's call, 2026-10-11), so a portrait narrows rather than
     crops.
   - **Two, three or four** fill a fixed 16/9 grid, with each cell `object-fit: cover`, as on bsky.app.
   - An image is an `<img loading="lazy">`.
   - A video is `<video controls preload="none" playsinline poster=…>` with the playlist as `src`.
     `attachHlsStreams` arms it on render, so the first play loads hls.js.
   - A click or key on a video stays with its controls and does not open the reader. An image opens the
     reader, as the rest of the block does.
4. `<app-entry-meta>` and `<app-entry-duplicates>`.

A click anywhere else opens the reader, as every block does.

## Testing

- **Backend:**
  - `EntryTitleTest`: no title and no text gives an untitled post.
  - `BlueskyEntryRuleTest`: a text-less post is stored as a post.
- **Slot-fit:**
  - `postMedia`: the poster is deduped, the list is capped at four, and the lead image is the fallback.
  - `isMediaPost`: articles and link posts are excluded.
  - `settle` returns `media` from any slot.
- **Planner:** a media post keeps its block on every slot, and the look-ahead does not jump over one.
- **Component spec:**
  - one image, its aspect ratio and a portrait
  - two, three and four images
  - video markup and its HLS arming
  - no caption for an untitled post
  - a click on a video does not open the reader
- **Browser:** dev subscriptions 1739 and 1740 at desktop and phone width.

## Addendum (2026-10-11): YouTube Shorts use the same card

The user asked for YouTube Shorts entries to use the media card. The final review also found two rules
that this addendum settles.

### Measured

- A Short (dev entry 578702) has a feed title, so `titleDerived` is false, and `isShort` is true.
  - Its `imageUrl` is `hqdefault.jpg`, 480×360. That 4:3 file letterboxes a 9:16 picture, and
    `imageAspectRatio` is 0.5625.
  - Its `media` is that one image. It has no excerpt.
  - Its body links `https://www.youtube-nocookie.com/embed/<id>#shorts`. That URL is the allow-listed
    portrait frame (`embed-frame-allowlist.generated.json`, generated from `YouTubeEmbedProvider::frames()`).

### Design

- **Selection.** `isMediaPost` also takes a Short with at least one `postMedia` item. A post still needs
  `titleDerived` and must not be a link post. The kind stays `media`.
- **Caption.** A post shows its text (`excerpt`). Any other entry, a Short, shows its title.
  - The backend now sends an untitled post's excerpt as `''`. Until now it was the embed chrome, such as
    "Watch on Bluesky".
  - That removes the frontend's `'(untitled)'` literal and the headline that read "Watch on Bluesky"
    elsewhere.
  - The placeholder moves to `App\Entity\EntryHeadline::UNTITLED`, with `isUntitledPost()`. `ParsedTitleModel`
    uses that constant. `EntryJson` sends `''` for such an entry.
- **Aspect.** The lead image's frame uses the entry's `imageAspectRatio` when the API sends one: the shape
  of the picture the file shows. `object-fit: cover` then crops the letterbox bars. Any other item keeps
  its declared width and height.
- **Playback.** A Short's card shows the cover with a play button over it. A click (or Enter or Space on the
  button) swaps the cover for the allow-listed player, starting at once.
  - The URL is `https://www.youtube-nocookie.com/embed/<id>?autoplay=1#shorts`.
  - `YouTubeEmbedProvider::frames()` accepts an optional `?autoplay=1` before `#shorts`, and the JSON is
    regenerated.
  - The frame uses the reader's sandbox (exported from `media-embeds.ts` as `EMBED_SANDBOX`) and
    `allow="autoplay; fullscreen"`.
  - The play button does not open the reader.
  - The player goes back to the cover when an entry opens, when the card scrolls out of view, and when
    the block is destroyed.
  - The list's existing pause-on-open hook (`pauseVideos`) becomes `pauseMedia`. It pauses `<video>`s and
    sends each media block a `media-pause` DOM event.
