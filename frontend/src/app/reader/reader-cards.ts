const CARD_CLASS = 'reader-card';
const CANDIDATE_SELECTOR = 'section, aside, div';

const MAX_TEASER_LENGTH = 900;
const MAX_ARTICLE_SHARE = 0.5;

export function markInsetCards(host: HTMLElement): void {
  for (const tagged of Array.from(host.querySelectorAll(`.${CARD_CLASS}`))) {
    tagged.classList.remove(CARD_CLASS);
  }
  const articleLength = (host.textContent ?? '').trim().length;
  const candidates = Array.from(host.querySelectorAll(CANDIDATE_SELECTOR)).reverse();
  for (const insert of candidates) {
    if (dominatesArticle(insert, articleLength)) continue;
    if (isSemanticInsert(insert)) {
      clearNestedCards(insert);
      insert.classList.add(CARD_CLASS);
      continue;
    }
    if (insert.querySelector(`.${CARD_CLASS}`)) continue;
    if (isLinkedImageTeaser(insert)) {
      insert.classList.add(CARD_CLASS);
    }
  }
}

function dominatesArticle(element: Element, articleLength: number): boolean {
  if (articleLength === 0) return false;
  return (element.textContent ?? '').trim().length > articleLength * MAX_ARTICLE_SHARE;
}

function isSemanticInsert(element: Element): boolean {
  if (element.tagName !== 'SECTION' && element.tagName !== 'ASIDE') {
    return false;
  }
  return !element.querySelector('h1, h2, h3, h4, h5, h6');
}

function isLinkedImageTeaser(element: Element): boolean {
  if (
    element.tagName !== 'DIV' ||
    element.querySelector('h1, h2, h3, h4, h5, h6') ||
    hasCaptionedFigure(element)
  ) {
    return false;
  }
  const images = ownedElements(element, 'img');
  const linksAnImage = ownedElements(element, 'a').some(
    (link) =>
      images.some((image) => link.contains(image)) && (link.textContent ?? '').trim() === '',
  );
  const hasCopy = ownedElements(element, 'p').some((paragraph) => !paragraph.closest('figure'));
  const length = ownedText(element).length;
  return linksAnImage && (length === 0 || (hasCopy && length <= MAX_TEASER_LENGTH));
}

function hasCaptionedFigure(element: Element): boolean {
  return ownedElements(element, 'figure').some((figure) => figure.querySelector('figcaption'));
}

function ownedElements(element: Element, selector: string): Element[] {
  return Array.from(element.querySelectorAll(selector)).filter(
    (descendant) => descendant.closest(CANDIDATE_SELECTOR) === element,
  );
}

function ownedText(element: Element): string {
  const walker = element.ownerDocument.createTreeWalker(element, NodeFilter.SHOW_TEXT);
  const fragments: string[] = [];
  while (walker.nextNode()) {
    if (walker.currentNode.parentElement?.closest(CANDIDATE_SELECTOR) === element) {
      fragments.push(walker.currentNode.textContent ?? '');
    }
  }
  return fragments.join(' ').trim();
}

function clearNestedCards(element: Element): void {
  for (const nested of Array.from(element.querySelectorAll(`.${CARD_CLASS}`))) {
    nested.classList.remove(CARD_CLASS);
  }
}
