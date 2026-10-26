import { embedFrame } from '../../media/media-embeds';

const HOST_CLASS = 'hidden-playback';

/** The one container for provider frames the player drives, created on first use. */
export function hiddenPlaybackHost(): HTMLElement {
  const existing = document.body.querySelector<HTMLElement>(`:scope > .${HOST_CLASS}`);
  if (existing) return existing;
  const host = document.createElement('div');
  host.className = HOST_CLASS;
  host.setAttribute('aria-hidden', 'true');
  document.body.appendChild(host);
  return host;
}

/** A provider frame, still detached (iOS honours referrerpolicy only on a frame whose src precedes insertion, #1594). */
export function hiddenPlaybackFrame(url: string, title: string): HTMLIFrameElement {
  const frame = embedFrame({ url, allow: 'autoplay' }, title);
  frame.setAttribute('loading', 'eager');
  frame.setAttribute('tabindex', '-1');
  return frame;
}
