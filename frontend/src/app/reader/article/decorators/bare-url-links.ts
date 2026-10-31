// Mirrors the backend's LinkedPlainText::URL, which links bare URLs in plain-text comments and descriptions.
const BARE_URL = /https?:\/\/[^\s<>"]+?(?=[.,;:!?)\]"'”’»…]*(?:\s|$))/giu;

const UNLINKABLE = 'a, code, pre';

/** Wraps every bare http(s) URL in the body's text in a link to itself. */
export function linkBareUrls(host: HTMLElement): void {
  for (const text of textNodesWithUrls(host)) {
    text.replaceWith(...linkedPieces(text.data, host.ownerDocument));
  }
}

function textNodesWithUrls(host: HTMLElement): Text[] {
  const walker = host.ownerDocument.createTreeWalker(host, NodeFilter.SHOW_TEXT);
  const found: Text[] = [];
  for (let node = walker.nextNode(); node; node = walker.nextNode()) {
    const text = node as Text;
    if (text.data.search(BARE_URL) === -1) continue;
    if (!text.parentElement?.closest(UNLINKABLE)) found.push(text);
  }
  return found;
}

function linkedPieces(data: string, document: Document): (string | HTMLAnchorElement)[] {
  const pieces: (string | HTMLAnchorElement)[] = [];
  let consumed = 0;
  for (const match of data.matchAll(BARE_URL)) {
    pieces.push(data.slice(consumed, match.index), linkTo(match[0], document));
    consumed = match.index + match[0].length;
  }
  pieces.push(data.slice(consumed));
  return pieces.filter((piece) => piece !== '');
}

function linkTo(url: string, document: Document): HTMLAnchorElement {
  const link = document.createElement('a');
  link.href = url;
  link.textContent = url;
  return link;
}
