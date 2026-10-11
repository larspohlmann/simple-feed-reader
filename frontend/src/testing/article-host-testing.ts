export function articleHost(
  html: string,
  decorate: (host: HTMLElement) => void = () => undefined,
): HTMLElement {
  const host = document.createElement('div');
  host.innerHTML = html;
  decorate(host);
  return host;
}

/** An article body attached to `document`, for passes that only act on connected nodes. */
export function attachedArticleHost(html: string): HTMLElement {
  const host = articleHost(html);
  document.body.appendChild(host);
  return host;
}
