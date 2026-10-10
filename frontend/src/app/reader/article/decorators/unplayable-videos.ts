import { isPlaylist } from './hls-streams';

export interface UnplayableVideoFallback {
  pageUrl: string | null;
  title: string;
  action: string;
}

/** Swaps a body video the browser cannot decode (AV1 on an older iPhone, a ProRes master)
 *  for a link card, styled as `.link-card`, to where it can be watched. */
export function replaceUnplayableVideo(
  target: EventTarget | null,
  fallback: UnplayableVideoFallback,
): void {
  const video = failedVideo(target);
  if (!video || isPlaylist(video.getAttribute('src') ?? '')) return;
  const href =
    fallback.pageUrl ||
    video.currentSrc ||
    video.getAttribute('src') ||
    (video.querySelector('source')?.getAttribute('src') ?? '');
  video.replaceWith(linkCard(href, video.getAttribute('poster'), fallback));
}

function failedVideo(target: EventTarget | null): HTMLVideoElement | null {
  if (target instanceof HTMLVideoElement) return target;
  if (!(target instanceof HTMLSourceElement)) return null;
  // The browser tries each <source> in turn; only the last one failing leaves nothing to play.
  const hasNextSource = target.nextElementSibling instanceof HTMLSourceElement;
  return !hasNextSource && target.parentElement instanceof HTMLVideoElement
    ? target.parentElement
    : null;
}

function linkCard(href: string, poster: string | null, fallback: UnplayableVideoFallback) {
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
    textElement('strong', fallback.title),
    textElement('span', fallback.action),
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
