/**
 * Tags self-contained inserts inside an article — a publisher's series teaser,
 * a support appeal, a side note — with class `reader-card`, so the stylesheet
 * can box them off from the running prose the way the source page does.
 *
 * The backend sanitizer strips every class and style, so the original box
 * markers are gone by the time this runs. Detection works from what survives:
 * the semantic tag (`<section>`, `<aside>`), and — for a linked-image teaser —
 * the DOM shape. Runs in the component's post-render pass, next to
 * `markLeadParagraph`. Idempotent across re-renders: stale tags are cleared
 * first.
 */
const CARD_CLASS = 'reader-card';

/** A teaser this long is prose, not an insert — the bound keeps the shape rule
 *  from swallowing a body block that merely opens with a linked image. */
const MAX_TEASER_LENGTH = 900;

/** An insert sets a passage apart from the article; a block carrying more than
 *  this share of the article's text is the article's own container, not an
 *  insert. Some sites wrap the whole body in a `<section>` — carding that would
 *  box the entire article. */
const MAX_ARTICLE_SHARE = 0.5;

export function markInsetCards(host: HTMLElement): void {
  for (const tagged of Array.from(host.querySelectorAll(`.${CARD_CLASS}`))) {
    tagged.classList.remove(CARD_CLASS);
  }
  const articleLength = (host.textContent ?? '').trim().length;
  for (const insert of Array.from(host.querySelectorAll('section, aside, div'))) {
    // Skip an insert nested inside another already-carded one: the outer box
    // already sets it apart, and a card within a card reads as clutter.
    if (insert.closest(`.${CARD_CLASS}`)) continue;
    if (dominatesArticle(insert, articleLength)) continue;
    if (isSemanticInsert(insert) || isLinkedImageTeaser(insert)) {
      insert.classList.add(CARD_CLASS);
    }
  }
}

/** Whether the element carries most of the article — the mark of a body
 *  container rather than an insert set apart from it. */
function dominatesArticle(element: Element, articleLength: number): boolean {
  if (articleLength === 0) return false;
  return (element.textContent ?? '').trim().length > articleLength * MAX_ARTICLE_SHARE;
}

/**
 * A heading-less `<section>`/`<aside>` beside the article — a pull-quote, a
 * support appeal, an aside. A section *with* a heading is article structure
 * (a "Related articles" list, a titled subsection), not an insert set apart
 * from the prose — the same distinction the teaser rule draws.
 */
function isSemanticInsert(element: Element): boolean {
  if (element.tagName !== 'SECTION' && element.tagName !== 'ASIDE') {
    return false;
  }
  return !element.querySelector('h1, h2, h3, h4, h5, h6');
}

/**
 * A linked-image teaser: the "related article" promo a publisher drops into the
 * flow — an image that links out, plus a short line of copy. Recognised by
 * shape, since the sanitizer has removed the class that named it.
 *
 * The guards keep it off two lookalikes: a body block (excluded by the heading
 * check and the length bound) and a captioned article image (whose only text
 * lives in the `<figcaption>`, so it has no copy paragraph outside a figure).
 */
function isLinkedImageTeaser(element: Element): boolean {
  if (element.tagName !== 'DIV' || element.querySelector('h1, h2, h3, h4, h5, h6')) {
    return false;
  }
  const linksAnImage = Array.from(element.querySelectorAll('a')).some(
    (link) => link.querySelector('img') && (link.textContent ?? '').trim() === '',
  );
  const hasCopy = Array.from(element.querySelectorAll('p')).some((p) => !p.closest('figure'));
  const length = (element.textContent ?? '').trim().length;
  return linksAnImage && hasCopy && length > 0 && length <= MAX_TEASER_LENGTH;
}
