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

### Recognizer roster

Market share (wmtips, 2026): Swiper ~20%, Slick ~10%, Owl ~10%, then Splide,
Glide, Flickity, tiny-slider. Embla is common in the React ecosystem.

| Recognizer | Marker (container › slide) | Stage | Confidence in fetched HTML |
|---|---|---|---|
| Tagesschau `data-v` | `[data-v-type="Carousel"]`, slides in `data-v` JSON | raw-page | High (verified) |
| Swiper | `.swiper` › `.swiper-slide` | in-body | High — author-written |
| Splide | `.splide` › `.splide__slide` | in-body | High — author-written |
| Glide | `.glide` › `.glide__slide` | in-body | High — author-written |
| Embla | `.embla` › `.embla__slide` | in-body | High — author-written |
| Owl Carousel | `.owl-carousel` › child items (`.item`) | in-body | Medium — `.owl-item` is added by JS; container class is author-written |
| Flickity | container › `.carousel-cell` | in-body | Medium — `.carousel-cell` is a convention, not enforced |
| Slick | `.slick-slider` › `.slick-slide` | in-body | Low — classes added by JS; fires only on pre-rendered pages |
| tiny-slider | `.tns-item` | in-body | Low — same, JS-injected |

Slick and tiny-slider recognizers are included but flagged best-effort: they
only match when the page was served with the markup pre-rendered. We do not
claim to recover a Slick carousel that only exists after the browser runs it.

## Two stages, one model

Both stages produce the same value objects and share one markup builder. The
split is by **where the slides live**, which is a real difference, not
ceremony.

### Stage A — raw-page recognizers (tagesschau `data-v`)

Runs next to `PageMediaScanner` on the raw page HTML (before Readability), the
same place recovered media is scanned today ([ArticleExtractor.php:75](../../../backend/src/Service/Reader/ArticleExtractor.php)).
A recognizer decodes the `data-v` JSON, reads each slide's `alttext` and
responsive URLs, and emits a `Slideshow` carrying an **anchor** (the album link
href, which also appears on the surviving `.copytext-galerie` `<a>`). The
inserter later seats the recreated block at that anchor in the cleaned body.

### Stage B — in-body recognizers (static-markup libraries)

Runs inside `ReaderBodyCleaner` on the cleaned body, where the slides and their
`<img>` tags already sit. A recognizer finds the carousel element, reads the
existing slides, and **rewrites the element in place** into the `reader-slideshow`
block. No anchor is needed — the element is already in position.

### Value objects

- `Slide` — one slide. Responsive image sources (a small ordered set of
  url+width, as `<picture>`/`srcset` will consume), `alt` text, optional
  `caption`. Immutable.
- `Slideshow` — `list<Slide>` plus an optional `anchorHref` (stage A only) and a
  `title` (the gallery heading when present). Immutable. Rejects itself when it
  holds fewer than two slides (the guard lives here, enforced once).
- `SlideshowRecognizerInterface` — one method returning `list<Slideshow>` from
  the document it is given. Two tagged, keyed locators (raw-page, in-body) hold
  the two recognizer families, following the repo's strategy pattern
  ([Service/Refresh/FeedBodyParser.php](../../../backend/src/Service/Refresh/FeedBodyParser.php)).

### Backend units

1. `Slide`, `Slideshow` — the model above (`Service/Reader/Slideshow/`).
2. `SlideshowRecognizerInterface` + the recognizers, one class per library.
3. `RawSlideshowScanner` — runs stage-A recognizers over the raw document;
   returns `list<Slideshow>`. Invoked from `ArticleExtractor` alongside the
   media scan, its result threaded into `ReaderBodyCleaner::clean()`.
4. `SlideshowMarkup` — builds the sanitizer-safe `reader-slideshow` HTML from a
   `Slideshow`. One place; both stages call it.
5. `SlideshowInserter` — stage A: seats the block at `anchorHref`, or appends it
   at body end when the anchor did not survive (never drops it). Stage B:
   replaces the recognized element in place. Modelled on `PageMediaInserter`.
6. `EntrySanitizer` allow-list — admit the `reader-slideshow` structure and its
   attributes (see markup below).

`ReaderBodyCleaner::clean()` gains one parameter: the stage-A `list<Slideshow>`.
Stage-B recognition is a cleaning step it already owns, so it needs no new input
for it. If the parameter list grows tramp-ish, the clean() inputs move into a
context object rather than lengthening the signature (phptramp rule).

## Extensibility — adding the next slideshow source is one file

The design is open for extension, closed for change. Everything downstream of a
recognizer — the `Slide`/`Slideshow` model, `SlideshowMarkup`, the inserter, the
sanitizer allow-list, and the client hydrator — is source-agnostic. A new
slideshow source touches **only its own recognizer**.

When we find another slideshow in real data later:

1. Add one `FooSlideshowRecognizer implements SlideshowRecognizerInterface`
   (`Service/Reader/Slideshow/Recognizer/`), keyed on that source's high-signal
   marker, returning `Slideshow` value objects.
2. Tag it into the right locator — **raw-page** if the images hide in a data
   attribute, **in-body** if they already sit as `<img>` in the extracted body.
   The tag is the whole wiring; no existing code changes.
3. Add one fixture in `tests/Fixtures/**` and its recognizer test.

No new markup, insertion, sanitizer, or frontend work — a new source reuses all
of it. This is why the two-stage split is by *where the slides live* (a fact
about the source) and not by library: a future source falls into one of the two
stages by its nature, and the recognizer is the only new code. New recognizers
are still added only for a marker seen in real data ("generalize on the second
case"), so the roster grows deliberately, not speculatively.

## Recreated markup

`SlideshowMarkup` emits, for both stages, a self-describing block:

```html
<figure class="reader-slideshow" aria-roledescription="carousel">
  <figcaption class="reader-slideshow__title">Die Hauptgründe für das Ergebnis …</figcaption>
  <ol class="reader-slideshow__track">
    <li class="reader-slideshow__slide">
      <picture>
        <source srcset="…-122.webp?width=1280" media="(min-width: …)">
        <img src="…-122.webp?width=960" alt="Umfrage, …" loading="eager">
      </picture>
    </li>
    <li class="reader-slideshow__slide">
      <img src="…-118.webp?width=960" alt="Umfrage, …" loading="lazy">
    </li>
    …
  </ol>
</figure>
```

- Real `<img>`/`<picture>` per slide. First eager, the rest lazy.
- With no JavaScript this is a captioned vertical stack — the images are all
  there. **This is the fallback, and it is also the test oracle**: a backend
  test asserts on this static structure, independent of any client behaviour.
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
- Styles in a sibling `.scss` (`styleUrl`) with design tokens — no hex, no raw
  `px`, per the frontend conventions.

Idempotent: re-running the enhancer over an already-hydrated block is a no-op
(guard with a marker attribute), because the enhancement effect re-runs on the
Reader/Original toggle.

## Testing

- **Backend, per recognizer:** a fixture HTML in `tests/Fixtures/**` → assert the
  emitted `reader-slideshow` structure and slide count. Add one fixture per
  library so each marker is proven, and measure against `tests/Fixtures/**`, not
  a topical subset.
- **Tagesschau anchor (the key risk):** a fixture of the real page → assert (a)
  Readability keeps the `.copytext-galerie` anchor, (b) the slideshow lands
  directly after that heading, (c) all ~20 slides are present. If the anchor is
  ever absent, assert the block appends at body end rather than vanishing.
- **Guard:** a single-figure fixture and a one-slide carousel → assert **no**
  slideshow is produced (the two-slide floor holds).
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

1. **Anchor survival (stage A).** The design depends on the gallery heading
   surviving Readability. Proven by the fixture test above; the append-at-end
   path is the safety net when it does not.
2. **False positives (all stages).** Contained by keying on documented markers
   plus the two-slide-with-image floor. New recognizers are added only for a
   marker we have seen in real data ("generalize on the second case").
3. **Slick / tiny-slider yield.** Low on fetched HTML by nature. Documented as
   best-effort, not a coverage claim.

## Sources

- Market share: <https://www.wmtips.com/technologies/javascript-libraries/filter/carousel/>
- Splide structure: <https://splidejs.com/guides/structure/>
