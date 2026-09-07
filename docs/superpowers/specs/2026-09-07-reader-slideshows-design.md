# Detect slideshows and recreate them as swipeable carousels — design

**Status:** approved scope, pre-implementation. Backend + frontend.

## Problem

Publishers embed image slideshows ("Bildergalerie", carousels) inside articles.
Our reader shows the gallery heading with nothing under it, because the slides
never reach the rendered body.

Two distinct reasons the slides go missing, and they need different handling:

1. **Images hidden in a JS-data attribute.** The slide list lives only inside an
   HTML-entity-encoded JSON blob on a container element; the site's JavaScript
   hydrates it into `<img>` tags at runtime. Our fetch never runs that
   JavaScript, and Readability strips the attribute. The extracted body keeps
   the heading and loses every image.
2. **Images present but wrapped in carousel markup.** Static-markup libraries
   (Swiper, Splide, and friends) write real `<img>` tags inside slide `<li>`/
   `<div>` elements. Those survive extraction — Readability runs with
   `keepClasses: true` ([ArticleExtractor.php:146](../../../backend/src/Service/Reader/ArticleExtractor.php)) — but they render as a
   broken, un-hydrated pile of stacked images with no slide affordance, and the
   library's own CSS (which we drop) was the only thing that made them a
   carousel.

### Concrete evidence — tagesschau (entry 503506)

`https://www.tagesschau.de/inland/innenpolitik/umfragen-wichtigstes-landtagswahl-sachsen-anhalt-2026-100.html`

```html
<a href="/multimedia/bilder/wahl/electionalbum-ts-ltw26st-102.html"
   class="copytext-galerie ...">
  <h3 class="copytext-galerie__dachzeile">Landtagswahl Sachsen-Anhalt</h3>
  <h2 class="copytext-galerie__headline">Die Hauptgründe für das Ergebnis …</h2>
</a>
<div class="v-instance carousel__prerender-height--gallery"
     data-v-type="Carousel"
     data-v="{&quot;…&quot;:…,&quot;slides&quot;:[
        {&quot;alttext&quot;:&quot;Umfrage, …&quot;,
         &quot;l&quot;:&quot;https://images.tagesschau.de/image/…/16x9-big/electionchart-…-122.webp?width=1280&quot;,
         &quot;m&quot;:&quot;…/1x1-big/…-122.webp?width=960&quot;,
         &quot;s&quot;:&quot;…/1x1-big/…-122.webp?width=960&quot;,
         &quot;xs&quot;:&quot;…/1x1-big/…-122.webp?width=840&quot;}, …]}">
</div>
```

The carousel `<div>` holds **zero `<img>` tags**. ~20 slides, each with `alttext`
and responsive URLs keyed `xs`/`s`/`m`/`l`, live inside the entity-encoded
`data-v` JSON. The album link and heading (`.copytext-galerie`) are the anchor
that survives into the extracted body and tells us where the gallery sat.

## Scope

- Detect slideshows from the popular carousel libraries **and** from the
  tagesschau `data-v` family (the case that motivated the ticket).
- Recreate each as a **swipeable carousel** in the reader view.
- Graceful degradation: with no JavaScript the same markup reads as a
  captioned vertical image stack, so the images are never lost.

Out of scope: video/mixed-media galleries (image slides only), autoplay,
per-slide deep-linking, and a "collapse long gallery" gate (the carousel shows
one slide at a time, so a 20-slide gallery is not a layout problem).

## Detection is a chain of specific recognizers, never a fuzzy heuristic

"General" means a growing list of recognizers that each key on a **high-signal,
documented class or attribute marker**, not one guess that any image list can
trip. This repo has paid for permissive detection before (paywall false
positives). Every recognizer obeys one guard: **it fires only when it finds two
or more slides that each carry a real image.** A lone figure never becomes a
carousel.

### Recognizer roster — corrected against real pages

Market share (wmtips, 2026): Swiper ~20%, Slick ~10%, Owl ~10%, then Splide,
Glide, Flickity, tiny-slider. Embla is common in the React ecosystem.

I ran a probe that models the recognizer over real gallery pages, fetched with
`curl` (raw server HTML, no JavaScript — exactly what our fetcher sees). The
lightGallery library demos are faithful stand-ins for the article-embedded
gallery our reader meets. Results:

| Recognizer | Marker | Real-page result |
|---|---|---|
| Tagesschau `data-v` | `[data-v-type="Carousel"]`, slides in `data-v` JSON | ✅ verified — 24 slides |
| Swiper | `.swiper-slide` | ✅ detected — image slides present |
| Owl Carousel | `.owl-carousel` container | ✅ detected — via container |
| Flickity | `.carousel-cell` | ✅ detected — image slides present |
| Splide | `.splide__slide` | ✅ shape verified |
| Glide | `.glide__slide` | ✅ verified — and correctly **abstains** on Glide's imageless animation frames |
| Slick | `.slick-slide` | ⚠️ opportunistic — classes injected at runtime, usually absent server-side |
| tiny-slider | `.tns-item` | ⚠️ opportunistic — same |
| Embla | `.embla__slide` | ⚠️ opportunistic — slides usually built by JS |

Four corrections the real data forced (each is now a design rule):

1. **The two-or-more-image-slide guard is validated and essential.** It abstained
   on Glide's empty animation frames and Flickity's empty homepage cells (true
   negatives) and fired only on real image galleries. It stays exactly as
   specified.
2. **A slide's image is often in `data-src` / a lazy attribute / an `<a href>`,
   not a bare `<img src>`.** Real galleries (the lightGallery pattern publishers
   embed) carry the URL on the slide's `data-src` (e.g. Swiper/Owl/Flickity all
   did). Slide-image resolution therefore reads, in order: a descendant
   `<img src=http…>`; a lazy attribute on the slide or a descendant
   (`data-src`, `data-lazy-src`, `data-original`, `data-thumb`,
   `data-splide-lazy`, `data-flickity-lazyload-src`); an `<a href>` pointing to
   an image file. First hit wins; a slide that resolves to nothing does not count
   toward the guard.
3. **Owl keys on the `.owl-carousel` container, never a `.item` class.** Bare
   `item` appeared on almost every page tested (pure noise) and the real Owl
   slide class varied (`owl-carousel-item`). Owl slides are the container's
   element children.
4. **Detection runs on the raw normalized page, before Readability.** `data-src`
   and the `data-v` blob do not reliably survive extraction, so scanning raw —
   where the URLs still live — is the one robust path. The earlier "in-body"
   stage is dropped: everything is one raw scan.

Slick, tiny-slider, and Embla stay in the roster but are honestly labelled
opportunistic: they fire only if a page happens to be served with their markup
and images pre-rendered. We claim no real coverage of a carousel that exists
only after the browser runs the library.

## One raw scan, several recognizers, one body insertion

Detection is a single pass over the raw normalized document, next to
`PageMediaScanner` ([ArticleExtractor.php:75](../../../backend/src/Service/Reader/ArticleExtractor.php)).
A locator of recognizers each returns `list<Slideshow>`; the combined result is
threaded into `ReaderBodyCleaner::clean()`, which inserts each recreated block
into the cleaned body and removes any surviving original carousel container so
the images are not duplicated.

### Value objects (`Service/Reader/Slideshow/`)

- `Slide` — `{ imageUrl: string, alt: string }`. One resolved image URL (the
  sanitizer permits only a single-src `<img>`) plus alt text. Immutable.
- `ContainerSignature` — the set of class tokens on the original carousel element
  (e.g. `swiper`, or tagesschau's `carousel__prerender-height--gallery`). The
  inserter uses it to find and remove that element from the cleaned body when it
  survived. Nullable — tagesschau's attribute-only div may leave nothing behind.
- `Slideshow` — `{ slides: list<Slide>, title: ?string, precedingText: ?string,
  container: ?ContainerSignature }`. `precedingText` is the normalized text of
  the block immediately before the gallery in the raw document, used to seat the
  block via the existing `PageTextBlocks::withText()` anchor mechanism. Immutable.
  A static factory returns `null` below two image-bearing slides, so the guard is
  enforced once, in one place.
- `SlideshowRecognizerInterface::recognize(HTMLDocument $rawDocument): list<Slideshow>`
  — one tagged, keyed locator holds every recognizer, following the repo's
  strategy pattern ([Service/Refresh/FeedBodyParser.php](../../../backend/src/Service/Refresh/FeedBodyParser.php)).

### Backend units

1. `Slide`, `ContainerSignature`, `Slideshow` — the model above.
2. `SlideshowRecognizerInterface` + two recognizers:
   - `MarkupCarouselRecognizer` — one class driven by a config table, one row per
     library (Swiper, Splide, Glide, Embla, Owl, Flickity, Slick, tiny-slider).
     Swiper/Splide/Glide/Embla share one shape, so per-library classes would
     violate DRY; a row is `{ containerClass, slideClass }` (either may be null —
     Owl matches by container and takes element children; Flickity/tiny-slider
     match by slide class and group by parent). Slide-image resolution follows
     rule 2 above.
   - `TagesschauCarouselRecognizer` — `[data-v-type="Carousel"]`, decodes the
     `data-v` JSON (`name` → title; `images[].alttext` → alt;
     `images[].imageUrls.l ?? m ?? s ?? xs` → image), `precedingText` from the
     preceding `a.copytext-galerie` heading.
3. `SlideshowScanner` — runs the locator over the raw document; returns
   `list<Slideshow>`. Invoked from `ArticleExtractor`, result threaded into
   `ReaderBodyCleaner::clean()`.
4. `SlideshowMarkup` — builds the sanitizer-safe `reader-slideshow` HTML from a
   `Slideshow`. One place.
5. `SlideshowInserter` — for each `Slideshow`: remove the original container (by
   `ContainerSignature`) if it survived; then seat the recreated block after the
   `precedingText` block via `PageTextBlocks::withText()`, or append it at body
   end when no anchor survived (never drops it). Modelled on `PageMediaInserter`.
6. `EntrySanitizer` allow-list — admit the `reader-slideshow` structure and its
   marker attribute (see markup below).

`ReaderBodyCleaner::clean()` gains one parameter: `list<Slideshow>` (default
`[]`, so existing callers and tests are untouched). It forwards it to
`SlideshowInserter` — one hop within the reader, no tramp chain.

## Extensibility — adding the next slideshow source is one file

The design is open for extension, closed for change. Everything downstream of a
recognizer — the `Slide`/`Slideshow` model, `SlideshowMarkup`, the inserter, the
sanitizer allow-list, and the client hydrator — is source-agnostic. A new
slideshow source touches **only its own recognizer**.

When we find another slideshow in real data later:

- **A new CSS-class carousel library** — add one `{ containerClass, slideClass }`
  row to `MarkupCarouselRecognizer`'s table. No new class.
- **A new JSON-blob or bespoke source** (like tagesschau) — add one
  `FooSlideshowRecognizer implements SlideshowRecognizerInterface`
  (`Service/Reader/Slideshow/Recognizer/`), keyed on its marker, and tag it into
  the locator. The tag is the whole wiring; no existing code changes.

Either way, add one fixture in `tests/Fixtures/**` and its recognizer test. No
new markup, insertion, sanitizer, or frontend work — a new source reuses all of
it, because everything downstream consumes the source-agnostic `Slideshow`. New
recognizers are still added only for a marker seen in real data ("generalize on
the second case"), so the roster grows deliberately, not speculatively.

## Recreated markup

`SlideshowMarkup` emits a self-describing block. Each slide is a **single-src
`<img>`** — `EntrySanitizer` strips `<picture>`/`<source>`/`srcset` (see
[LazyImageSources.php](../../../backend/src/Service/Reader/LazyImageSources.php),
which flattens picture to one `<img>` for exactly this reason), so the recognizer
picks one URL per slide server-side:

```html
<figure class="reader-slideshow">
  <figcaption>Die Hauptgründe für das Ergebnis in Sachsen-Anhalt</figcaption>
  <ol>
    <li><img src="…-122.webp?width=1280" alt="Umfrage, …" loading="eager"></li>
    <li><img src="…-118.webp?width=1280" alt="Umfrage, …" loading="lazy"></li>
    …
  </ol>
</figure>
```

- One `<img>` per slide; first eager, the rest lazy. Structure (`figure`,
  `figcaption`, `ol`, `li`, `img`) is all in `allowSafeElements()`.
- **The marker is `class="reader-slideshow"` on the `<figure>`.** `EntrySanitizer`
  allows `class` only on `<audio>` today (#903), so the allow-list is widened to
  `class` on `<figure>` — the narrow, non-scriptable marker the client selects as
  `figure.reader-slideshow` (mirroring `audio.reader-narration`). Class carries no
  script, so no XSS surface opens.
- With no JavaScript this is a captioned vertical image stack — every image is
  present. **This is the graceful fallback, and it is also the test oracle**: a
  backend test asserts on this static structure, independent of any client code.
- Slide image URLs pass the same outbound/durable-URL handling as other reader
  media (`DurableMediaUrl`); no new network path, SSRF boundary unchanged.

## Frontend — one hydrator

`hydrateSlideshows(host)`, called in the same `queueMicrotask` enhancement block
as `upgradeMediaEmbeds` / `markNarrationPlayers` / `attachHlsStreams`
([reader-view.component.ts:356](../../../frontend/src/app/reader/reader-view/reader-view.component.ts)).

For each `.reader-slideshow` it upgrades the static stack into a swipeable
carousel:

- One slide visible; the track translates between slides.
- Previous / next controls; horizontal swipe (reuse the existing touch-swipe
  approach in the reader); arrow keys when the carousel has focus.
- A `"3 / 20"` counter, and dots when the count is small.
- Accessibility: `aria-roledescription="carousel"`, each slide labelled
  `"n of total"`, `alt` carried from the source, a live region announcing the
  current slide, controls reachable by keyboard.
- Lazy slides load as they approach view.
- Styles live in `reader-view.component.scss` under `.content ::ng-deep
  .reader-slideshow …`, the same place the narration box is styled — that is how
  a rule reaches `[innerHTML]` content. Design tokens only: no hex, no raw `px`.
- Labels are passed in by the caller (translated via Transloco), like
  `markNarrationPlayers`. New i18n keys under `reader.` in `public/i18n/{en,de}.json`:
  `slideshowPrevious`, `slideshowNext`, and a position string the caller
  interpolates to `"3 / 24"`.

Idempotent: re-running the enhancer over an already-hydrated block is a no-op
(guard with a marker attribute), because the enhancement effect re-runs on the
Reader/Original toggle.

## Testing

- **Backend, per recognizer:** a small fixture in `tests/Fixtures/**`, its markup
  copied faithfully from the real pages the probe validated (Swiper/Owl/Flickity
  with `data-src`, Splide/Glide with a real `<img>`, tagesschau `data-v`) → assert
  the emitted `reader-slideshow` structure and slide count. One fixture per
  library so each marker is proven; measure against `tests/Fixtures/**`, not a
  topical subset.
- **Image resolution:** a slide fixture whose URL sits only on `data-src`, and one
  on an `<a href>` image → assert both resolve; a slide with no resolvable image
  does not count toward the guard.
- **Tagesschau anchor (the key risk):** the real page fixture → assert (a)
  Readability keeps the `.copytext-galerie` heading text so `precedingText`
  matches, (b) the slideshow lands directly after that heading, (c) all 24 slides
  are present. Then a variant with the heading removed → assert the block appends
  at body end rather than vanishing.
- **Abstain (validated true negatives):** a Glide fixture of imageless animation
  frames and a Flickity fixture of empty cells → assert **no** slideshow. Plus a
  one-slide carousel → assert none (the two-slide floor holds).
- **Sanitizer:** assert the `reader-slideshow` markup survives `EntrySanitizer`
  unchanged, and that a hostile attribute inside it is still stripped.
- **Frontend (Jest, in the Docker frontend container):** given the static
  markup, `hydrateSlideshows` shows one slide, advances on next/prev and arrow
  keys, wraps the counter correctly, and is idempotent.
- **Mutation:** the changed backend files gate on `composer infection:diff`;
  collapse any two code paths with identical output so the mutant is killable.
- No e2e in the CI gate (galleries are third-party data); a Playwright smoke may
  stub a slideshow route but stays outside the gate.

## Key risks

1. **Anchor survival.** Placement depends on the gallery's preceding heading or
   paragraph surviving Readability so `precedingText` matches. Proven by the
   fixture tests; append-at-body-end is the safety net when it does not.
2. **False positives.** Contained by keying on documented markers plus the
   two-image-slide floor — validated against real pages, where it abstained on
   Glide's animation frames and Flickity's empty cells. New recognizers are added
   only for a marker seen in real data.
3. **Server-side detectability varies by library and site.** The probe confirmed
   that some libraries build slides only at runtime; those galleries are simply
   invisible to a no-JS fetch and out of reach for any server-side reader. We
   detect what is in the fetched HTML and claim nothing more.

## Validation

`docs/superpowers/plans/` holds no probe; the probe was a throwaway modelling the
recognizer, run over real gallery pages fetched with `curl`. Evidence recorded in
the roster table above: Swiper, Owl, Flickity detected on real (lightGallery)
galleries; tagesschau `data-v` verified at 24 slides; Glide/Flickity empty
carousels correctly rejected. The findings are folded into rules 1–4 in the
roster section.

## Sources

- Market share: <https://www.wmtips.com/technologies/javascript-libraries/filter/carousel/>
- Splide structure: <https://splidejs.com/guides/structure/>
- Library galleries probed: lightGallery demos (Swiper, Owl, Slick, Flickity),
  splidejs.com, glidejs.com, flickity.metafizzy.co
