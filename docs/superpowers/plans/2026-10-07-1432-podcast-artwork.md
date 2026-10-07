# Podcast artwork as item and feed images — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Read the podcast namespaces' artwork as images. On an item it becomes the entry image (list thumbnail, Original-view hero, player artwork). On a channel or Atom feed it is the fallback for the feed image (#1432).

**Architecture:** A new static `Service/Parser/Support/PodcastArtwork::of(\DOMElement $parent): ?DeclaredImageModel` reads the elements below, best first. `FeedItemImageSelector` appends it to the RSS 2.0 and Atom chains, after the existing declared sources and before the body-image fallback. `FeedImageExtractor` falls back to it for RSS 2.0 channels and Atom feeds. `ItemImageExtractor`'s private `widest`, `positiveInt` and `imageFrom` move to a static `Support/DeclaredImages` so both classes share them.

**Tech Stack:** PHP 8.4, ext-dom, PHPUnit 12.

**Spec:** GitHub issue #1432, as widened by the user: "are there other podcast image types we don't read? add those, too".

## Sources, best first

1. **Podcasting 2.0 `<podcast:image href>`** (`https://podcastindex.org/namespace/1.0`, on channel and item, may repeat).
   - Skip an element whose `type` is not `image/*` (the spec allows `video/mp4` canvases).
   - Skip an element whose `purpose` shares no token with `artwork`/`social` (banners, canvases). An element without `purpose` counts.
   - The widest qualifying element wins, joined with the rest, as Media RSS does.
2. **Deprecated `<podcast:images srcset>`**: the widest `w` candidate, carrying the srcset's ladder as renditions.
3. **`<itunes:image href>`** (`http://www.itunes.com/dtds/podcast-1.0.dtd`).
4. **`<googleplay:image href>`** (`http://www.google.com/schemas/play-podcasts/1.0`).

## Rulings

- **Precedence (revised after review).** The item's own declared pictures (Media RSS, image enclosure, custom `<image url>`) come first, then the body image, then the item's podcast artwork. Many hosts (Anchor, Libsyn, Buzzsprout, PowerPress) repeat the show art on every item, and it must not hide the post's own picture.
- **Show-art fallback (user: "fall back to the shows artwork").** An *episode* (an item with an audio or video enclosure) that has no image at all takes the channel's or Atom feed's podcast artwork. Text posts and PDF handouts in the same feed stay without one, so a newsletter that also podcasts does not put its show art on every post.
- **Unusable hrefs are skipped.** A relative or non-http(s) href would be rejected at persist time, which would leave the entry imageless. Skipping it lets a lower source answer.
- **Channel `<image>` fix, in scope.** `fromRss2Channel` now takes the first `<image>` in the channel's own namespace that has a `<url>`. Today it takes any `*:image` child, so a leading `<itunes:image>` (SoundCloud) hides the real `<image>`.
- **Backfill.** Entries whose image was never judged (`url` and `checkedAt` both NULL) fill on the next refresh through `EntryIngestor::fillMissingImages`. No migration is needed.

## Global Constraints

- CLAUDE.md Clean Code. Role folders: `Support/` holds static-only helpers that compute.
- PHPMD-clean touched files.
- `composer check`, `composer md`, `php bin/phpunit`, `composer infection:diff` green.
- Commit format `type(#1432): lower-case summary`, no attribution lines.

---

### Task 1: PodcastArtwork reads every podcast artwork element

**Files:**
- Create: `backend/src/Service/Parser/Support/DeclaredImages.php` (`widest`, `positiveDimension`, `fromElement`, all moved from `ItemImageExtractor`)
- Create: `backend/src/Service/Parser/Support/PodcastArtwork.php`
- Modify: `backend/src/Service/Parser/ItemImageExtractor.php` (use `DeclaredImages`)
- Modify: `backend/src/Service/Parser/FeedItemImageSelector.php` (append `PodcastArtwork::of` to `fromRss2` and `fromAtom`)
- Modify: `backend/src/Service/Parser/Support/FeedImageExtractor.php` (un-namespaced `<image>` with a url, then `PodcastArtwork::of`; Atom `<logo>`, then `PodcastArtwork::of`)
- Test: `backend/tests/Service/Parser/Support/PodcastArtworkTest.php`
- Test: `backend/tests/Service/Parser/FeedItemImageSelectorTest.php`
- Test: `backend/tests/Service/Parser/Support/FeedImageExtractorTest.php` (create or extend)

**Tests:**
- `PodcastArtworkTest`:
  - each of the four sources alone yields its URL;
  - the precedence order;
  - the widest `podcast:image` wins;
  - a video `type` is skipped;
  - a `banner`-only purpose is skipped, `artwork social` qualifies;
  - an element without `href` is skipped;
  - the srcset's widest candidate and its renditions are read;
  - an un-namespaced `<image href>` is not artwork.
- Selector:
  - an RSS 2.0 item with only `<itunes:image>` yields it;
  - a Media RSS image beats it;
  - an Atom entry with `<itunes:image>` yields it.
- Feed image:
  - a channel with `<itunes:image>` before `<image><url>` yields the `<image>` url (the regression);
  - a channel with only `<itunes:image>` yields it;
  - an Atom feed without `<logo>` yields its `<itunes:image>`.
- End to end: a trimmed copy of the real SoundCloud feed (`tests/Fixtures/soundcloud/sounds.rss`) through `FeedParser` gives each entry its track artwork and the feed its `<image>` logo.

Commit: `feat(#1432): podcast artwork is the image of an episode and the fallback of a feed`.
