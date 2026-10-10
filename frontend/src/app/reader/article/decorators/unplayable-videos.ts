import { isHlsManaged } from './hls-streams';

export interface UnplayableVideoFallback {
  pageUrl: string | null;
  title: string;
  action: string;
}

/** Swaps a body video the browser cannot decode for a `.link-card` to where it can be watched. */
export function replaceUnplayableVideo(
  target: EventTarget | null,
  fallback: () => UnplayableVideoFallback,
): void {
  const video = failedVideo(target);
  if (!video || isHlsManaged(video)) return;
  const { pageUrl, title, action } = fallback();
  const href =
    pageUrl ?? video.getAttribute('src') ?? video.querySelector('source')?.getAttribute('src');
  video.replaceWith(linkCard(href ?? '', video.getAttribute('poster'), { title, action }));
}

function failedVideo(target: EventTarget | null): HTMLVideoElement | null {
  if (target instanceof HTMLVideoElement) return target;
  if (!(target instanceof HTMLSourceElement)) return null;
  // Only the last <source> failing leaves the browser nothing to play.
  if (target.nextElementSibling instanceof HTMLSourceElement) return null;
  return target.closest('video');
}

function linkCard(href: string, poster: string | null, text: { title: string; action: string }) {
  const link = document.createElement('a');
  link.href = href;
  link.target = '_blank';
  link.rel = 'noopener noreferrer';
  if (poster) {
    const image = document.createElement('img');
    image.src = poster;
    image.alt = '';
    link.append(image);
  }
  link.append(
    textElement('strong', text.title),
    textElement('span', text.action),
    textElement('small', link.hostname),
  );
  const card = document.createElement('figure');
  card.className = 'link-card';
  card.append(link);
  return card;
}

function textElement(tag: 'strong' | 'span' | 'small', text: string): HTMLElement {
  const element = document.createElement(tag);
  element.textContent = text;
  return element;
}
