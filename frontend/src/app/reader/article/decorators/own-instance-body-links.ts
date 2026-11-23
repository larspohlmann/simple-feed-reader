export function openLinksOnOwnInstance(
  host: HTMLElement,
  rewrite: (href: string, text: string) => string,
): void {
  for (const link of Array.from(host.querySelectorAll<HTMLAnchorElement>('a[href]'))) {
    const href = link.getAttribute('href') ?? '';
    const target = rewrite(href, link.textContent?.trim() ?? '');
    if (target !== href) link.setAttribute('href', target);
  }
}
