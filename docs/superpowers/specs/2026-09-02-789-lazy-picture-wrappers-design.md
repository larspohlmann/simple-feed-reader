# #789 — Reader loses every photo of an immersive gallery page

Source: [issue #789](https://github.com/larspohlmann/simple-feed-reader/issues/789).
The issue names one entry and two acceptance lines; this document records the
measured cause and the rule.

## Problem

nature.com 495343 ("Say hello to the next generation of lobsters — August's
best science images", served from `/immersive/d41586-026-02597-z/`) carries
nine article photos, one per section, each followed by its caption. The
reader shows the og:image lead and no other picture: empty `<figure>`s,
captions with nothing above them.

## Measured, boundary by boundary (real services, the fetched page)

| boundary | photos in | photos out | why |
|---|---|---|---|
| `FetchedPageNormalizer` (LazyImageSources) | 24 `<picture>` | 11 flattened, **13 `<img>` removed** | the 13 body photos are lazy pictures: `<picture data-lazy="true">` whose `<source>` carry `data-srcset`, not `srcset`; the resolver reads `srcset` on a `<source>` and only `data-*` on the `<img>` itself, finds "no usable candidate", and removes the image |
| readability | 8 body photos (after fix 1) | **5** | the eclipse, waterlily and hiroshimaite pictures sit in text-less wrappers classed `Theme-Layer-ResponsiveMedia`, `ResponsiveMedia--image__inner`, `InlineMedia--image__inner`; readability's `RegExps::NEGATIVE` (`media`, `promo`, `share`, `widget`, …) weights the wrapper −25 and `_cleanConditionally` removes a `<div>` whose weight is negative, picture included |
| `EntrySanitizer` | 5 | **0** | the five survivors sit inside `<sh-background-transition>`, a custom element; Symfony's sanitizer drops an unknown element **with its children** (verified: `<sh-x><p>kept?</p><img></sh-x>` → nothing) |

With all three repaired in a probe through the real normalizer, readability,
`ReaderBodyCleaner` and sanitizer, the body holds nine photos, each directly
before or after its caption, in page order. Relative `./assets/…` URLs resolve
against the page's final URL (readability's `fixRelativeURLs` already does
this; the lead image proves it).

None of the three is host-specific:

1. `data-srcset` on `<source>` is the same lazy-load contract the resolver
   already honours on `<img>` (`data-lazy-srcset`, `data-srcset`).
2. A custom element (a hyphen in the tag name, per the HTML spec) has no
   semantics the sanitizer allows, so its content is lost wherever it appears
   (heise's `<a-iframe>` was the first case, solved for embeds only).
3. Any publisher that names a picture wrapper with a word in readability's
   negative list (`media` is the common one: `c-media`, `wp-block-media-text`,
   `responsive-media`) loses the picture.

## The rules

All three live in the shared pre-readability pass (`FetchedPageNormalizer::repair()`, #586):

1. **LazyImageSources** reads a `<source>`'s candidate list from
   `data-lazy-srcset`, `data-srcset`, `srcset` (that order — the same list it
   uses for the `<img>`), both when a bare `<img>` takes the picture's first
   candidate and when a placeholder `<img>` adopts the widest rendition.
2. **CustomElementUnwrapper** (new, first step of `repair()`): every element
   whose tag name contains a hyphen is replaced by its children. Attributes
   are dropped — nothing downstream can use them; `PageMediaScanner` reads the
   raw page and is unaffected.
3. **ImageWrapperClassRemover** (new, **last** step of `repair()`, after the
   share-widget and screen-reader-only removals, which read classes): for
   every `<img>`, walk up through ancestors that hold no text and exactly one
   image, and drop their `class` and `id`. Stop at the first ancestor with
   text (a `<figure>` with a caption keeps its class) or with a second image
   (a gallery grid keeps its class). Never touch `body` or the `<img>` itself.
   Skip an image inside an `<a>` — a card or teaser thumbnail, the same
   exclusion `PageMediaInserter` applies to body images — and an image inside
   `aside`, `nav` or `footer` (`PageFurniture`).

## Measured across every fixture on disk

All 30 HTML fixtures under `tests/Fixtures/` (reader, reader/media, scraped)
were run through normalizer → readability → sanitizer with and without the
three repairs: image count and extracted text length are identical for every
one. Without the linked-image exclusion, one changed: the treehugger scrape
kept a sidebar card thumbnail (`a.mntl-card-list-items > div.card__media`,
readability had dropped it by the `media` weight). The exclusion keeps it
dropped. The nature page yields nine photos either way.

## Non-goals

- The OPY-finalists carousel (five photos, no text, `MediaGallery_carousel`)
  stays out: readability drops a text-less multi-image block, and that is
  correct for the teaser carousels #779 fought.
- The "Giant gestation" `<video>` with relative `<source src>` is a separate
  media-scanner defect (relative file URLs); not this issue.
- Taking the widest rendition for a bare `<img>` (today: the first candidate)
  is parity with the existing `srcset` path; unchanged here.

## What to build

- `LazyImageSources`: one private `srcsetOf(Element): ?string` over
  `SRCSET_ATTRIBUTES`, used by `renditionOf()` and
  `candidateFromEnclosingPicture()`.
- `App\Service\Reader\CustomElementUnwrapper::unwrapIn(HTMLDocument)`.
- `App\Service\Reader\ImageWrapperClassRemover::removeFrom(HTMLDocument)`.
- `FetchedPageNormalizer` takes both as constructor collaborators; its
  docblock's repair list gains the two bullets.
- Fixture `tests/Fixtures/reader/article-immersive-gallery.html` shaped like
  the nature page (lazy pictures with `data-srcset` and relative `./assets/`
  URLs, one photo inside a custom element, one inside a `ResponsiveMedia`
  wrapper, one inside an `InlineMedia` figure with a caption), with an
  end-to-end `ArticleExtractor` test asserting every photo, absolute, beside
  its caption.
- Reader cache `VERSION` +1: an already-read article would keep its empty
  figures.

## Acceptance

- The fixture extracts with all three section photos as absolute URLs, each
  adjacent to its caption, and no custom element in the body.
- Every existing normalizer, lazy-image, extractor, confirmed-good-article
  and audit test stays green.
- Entry 495343 renders nine photos, each beside its caption, after a reload
  of the article.
- `composer check`, `composer md`, `composer infection:diff`, both phpunit
  legs, and `npm run check` are green.
