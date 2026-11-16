import { ownInstanceBodyUrl } from '../../own-instance/own-instance-url';

export function openMastodonLinksOnOwnInstance(host: HTMLElement, instance: string | null): void {
  for (const link of Array.from(host.querySelectorAll<HTMLAnchorElement>('a[href]'))) {
    const href = link.getAttribute('href') ?? '';
    const target = ownInstanceBodyUrl(href, link.textContent?.trim() ?? '', instance);
    if (target !== href) link.setAttribute('href', target);
  }
}
