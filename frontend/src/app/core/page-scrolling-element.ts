export function pageScrollingElement(document: Document): Element {
  return document.scrollingElement ?? document.documentElement;
}
